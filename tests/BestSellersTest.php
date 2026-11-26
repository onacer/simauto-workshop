<?php

namespace App\Tests;

use App\Controller\DashboardController;
use App\Service\AccessControl;
use App\Service\AppDatabase;
use App\Twig\MoneyExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class BestSellersTest extends TestCase
{
    private AppDatabase $db;
    private string $directory;
    private int $oil;
    private int $filter;
    private array $payload;
    private int $order;
    private int $invoice;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/simauto-sales-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->db = new AppDatabase($this->directory, $this->directory . '/test.sqlite');
        $category = $this->db->categories()[0]['id'];
        foreach (['oil' => 'Oil <test>', 'filter' => 'Filter'] as $field => $name) {
            $this->db->saveProduct(['sku' => 'SALES-' . $field, 'name' => $name, 'category_id' => $category, 'stock_qty' => 100, 'sale_price' => 50], 1);
            $this->$field = (int) $this->db->productBySku('SALES-' . $field)['id'];
        }
        $this->db->saveClient(['name' => 'Sales test']);
        $client = (int) $this->db->pdo()->lastInsertId();
        $brand = $this->db->getOrCreateVehicleBrand('Hyundai');
        $model = $this->db->getOrCreateVehicleModel($brand, 'Accent');
        $this->db->saveVehicle(['client_id' => $client, 'plate' => 'SALES-TEST', 'brand_id' => $brand, 'model_id' => $model]);
        $vehicle = (int) $this->db->vehicleByPlate('SALES-TEST')['id'];
        $this->payload = ['client_id' => $client, 'vehicle_id' => $vehicle, 'product_1' => $this->oil, 'product_qty_1' => 2];
        $this->order = $this->db->createPurchaseOrder($this->payload, 2);
        $quote = $this->db->createOperation(array_replace($this->payload, ['product_qty_1' => 3]), 1);
        $this->invoice = $this->db->invoiceDocument($quote, 1);
        $this->db->createOperation(array_replace($this->payload, ['product_qty_1' => 90]), 1); // unsold quote
        $this->db->createPurchaseOrder(array_replace($this->payload, ['product_1' => $this->filter, 'product_qty_1' => 4]), 2);
        $this->db->createPurchaseOrder(['client_id' => $client, 'vehicle_id' => $vehicle, 'service_label_1' => 'Service only', 'service_price_1' => 100], 2);
        $this->db->pdo()->prepare('UPDATE operations SET created_at = :date WHERE id = :id')->execute(['date' => '2026-11-01 00:00:00', 'id' => $this->order]);
        $this->db->pdo()->prepare('UPDATE operations SET created_at = :date WHERE id = :id')->execute(['date' => '2026-11-02 23:59:59', 'id' => $this->invoice]);
    }

    protected function tearDown(): void
    {
        unset($this->db);
        foreach (glob($this->directory . '/*') as $file) @unlink($file);
        @rmdir($this->directory);
    }

    public function testOrdersAndInvoicesAreRankedWithoutCopiesQuotesServicesOrWrites(): void
    {
        $before = $this->db->pdo()->query('SELECT total_changes()')->fetchColumn();
        $rows = $this->db->bestSellingProducts();
        self::assertCount(2, $rows);
        self::assertSame([$this->oil, $this->filter], array_map('intval', array_column($rows, 'product_id')));
        self::assertSame([5.0, 4.0], array_map('floatval', array_column($rows, 'quantity')));
        self::assertSame(2, (int) $rows[0]['document_count']);
        self::assertCount(1, $this->db->bestSellingProducts([], 1));
        self::assertSame($before, $this->db->pdo()->query('SELECT total_changes()')->fetchColumn());
        // Turning the remaining order into an invoice must not increase the sales total.
        $this->db->invoiceDocument($this->order, 2);
        self::assertSame(5.0, (float) $this->db->bestSellingProducts()[0]['quantity']);
        $this->db->pdo()->exec('UPDATE products SET active = 0 WHERE id = ' . $this->oil);
        self::assertSame($this->oil, (int) $this->db->bestSellingProducts()[0]['product_id']);
        self::assertSame([], $this->db->stockConsistencyIssues());
    }

    public function testInclusivePeriodUsesSurvivingDocumentDates(): void
    {
        $rows = $this->db->bestSellingProducts(['from' => '2026-11-01', 'to' => '2026-11-02']);
        self::assertCount(1, $rows);
        self::assertSame(5.0, (float) $rows[0]['quantity']);
        self::assertSame(2.0, (float) $this->db->bestSellingProducts(['to' => '2026-11-01'])[0]['quantity']);
        self::assertSame(3.0, (float) $this->db->bestSellingProducts(['from' => '2026-11-02', 'to' => '2026-11-02'])[0]['quantity']);
        self::assertSame([], $this->db->bestSellingProducts(['to' => '1900-01-01']));
        $this->db->invoiceDocument($this->order, 2);
        self::assertSame([], $this->db->bestSellingProducts(['to' => '2026-11-01']), 'A dated-out invoice must not resurrect its copied order.');
    }

    public function testBothRolesSeeLinkedDashboardKpiRankingAndTranslatedFilters(): void
    {
        foreach ([1, 2] as $user) {
            foreach (['fr', 'ar'] as $locale) {
                [$controller, $request] = $this->controller('/', $user, $locale);
                $html = $controller->index($request, $this->db)->getContent();
                self::assertStringContainsString('href="/products/best-sellers"', $html);
                self::assertStringContainsString('Oil &lt;test&gt;', $html);
                self::assertStringNotContainsString('best_sellers.title', $html);
                [$controller, $request] = $this->controller('/products/best-sellers?from=2026-11-01&to=2026-11-02', $user, $locale);
                $response = $controller->bestSellers($request, $this->db, new AccessControl());
                self::assertSame(200, $response->getStatusCode());
                self::assertStringContainsString('href="/products/' . $this->oil . '"', $response->getContent());
                self::assertStringNotContainsString('>Filter<', $response->getContent());
            }
        }
        [$controller, $request] = $this->controller('/products/best-sellers?from=2026-12-01&to=2026-11-01', 2);
        self::assertStringContainsString('role="alert"', $controller->bestSellers($request, $this->db, new AccessControl())->getContent());
        [$controller, $request] = $this->controller('/products/best-sellers', 0);
        self::assertSame('/login', $controller->bestSellers($request, $this->db, new AccessControl())->headers->get('Location'));
        $denied = $this->createMock(AccessControl::class);
        $denied->method('can')->willReturn(false);
        [$controller, $request] = $this->controller('/products/best-sellers', 2);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $controller->bestSellers($request, $this->db, $denied);
    }

    private function controller(string $uri, int $user, string $locale = 'fr'): array
    {
        $request = Request::create($uri);
        $request->setLocale($locale);
        $request->attributes->set('_route', 'app_dashboard');
        $request->setSession(new Session(new MockArraySessionStorage()));
        if ($user) $request->getSession()->set('user', ['id' => $user]);
        $routes = new RouteCollection();
        $loader = new AttributeRouteControllerLoader();
        foreach (glob(__DIR__ . '/../src/Controller/*Controller.php') as $file) $routes->addCollection($loader->load('App\\Controller\\' . basename($file, '.php')));
        $router = new UrlGenerator($routes, new RequestContext());
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['fr', 'ar'] as $language) $translator->addResource('yaml', __DIR__ . '/../translations/messages.' . $language . '.yaml', $language);
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../templates'), ['strict_variables' => true]);
        $twig->addExtension(new MoneyExtension());
        $twig->addFilter(new TwigFilter('trans', fn (string $key, array $params = []) => $translator->trans($key, $params)));
        $twig->addFunction(new TwigFunction('path', fn (string $name, array $params = []) => $router->generate($name, $params)));
        $twig->addFunction(new TwigFunction('asset', fn (string $path) => '/' . $path));
        $twig->addFunction(new TwigFunction('can', fn (string $permission, array $user) => (new AccessControl())->can($permission, $user)));
        $twig->addGlobal('app_name', 'SIM Auto');
        $twig->addGlobal('app', new class($request) {
            public function __construct(public Request $request) {}
            public function flashes(string $type): array { return $this->request->getSession()->getFlashBag()->get($type); }
        });
        $container = new Container();
        $stack = new RequestStack(); $stack->push($request);
        $container->set('request_stack', $stack); $container->set('router', $router); $container->set('twig', $twig);
        $controller = new DashboardController(); $controller->setContainer($container);
        return [$controller, $request];
    }
}
