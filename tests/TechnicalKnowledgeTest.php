<?php

namespace App\Tests;

use App\Command\StockCheckCommand;
use App\Controller\DashboardController;
use App\Service\AccessControl;
use App\Service\AppDatabase;
use App\Service\CompanyProfile;
use App\Service\TechnicalKnowledge;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Console\Tester\CommandTester;
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

final class TechnicalKnowledgeTest extends TestCase
{
    private AppDatabase $db;
    private TechnicalKnowledge $knowledge;
    private string $directory;
    private array $invoices = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/simauto-knowledge-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->db = new AppDatabase($this->directory, $this->directory . '/test.sqlite');
        $this->knowledge = new TechnicalKnowledge($this->db);
        $this->db->saveClient(['name' => 'Knowledge <client>']);
        $client = (int) $this->db->pdo()->lastInsertId();
        $this->db->saveVehicleBrand('Knowledge Motors');
        $brand = (int) $this->db->vehicleBrandByName('Knowledge Motors')['id'];
        $this->db->saveProduct(['sku' => 'K-OIL', 'ref_company' => 'INT-OIL', 'name' => 'Huile 5W30', 'category_id' => $this->db->categories()[0]['id'], 'stock_qty' => 100, 'purchase_price' => 20, 'sale_price' => 40], 1);
        $oil = (int) $this->db->productBySku('K-OIL')['id'];
        $this->db->saveProduct(['sku' => 'K-FILTER', 'name' => 'Filtre à air', 'category_id' => $this->db->categories()[0]['id'], 'stock_qty' => 100, 'sale_price' => 30], 1);
        $filter = (int) $this->db->productBySku('K-FILTER')['id'];
        foreach ([['Alpha', 'K-ONE', $oil, 2], ['Alpha', 'K-TWO', $oil, 3], ['Beta', 'K-THREE', $filter, 1]] as $index => [$modelName, $plate, $product, $quantity]) {
            $model = $this->db->getOrCreateVehicleModel($brand, $modelName);
            $this->db->saveVehicle(['client_id' => $client, 'plate' => $plate, 'brand_id' => $brand, 'model_id' => $model]);
            $vehicle = (int) $this->db->vehicleByPlate($plate)['id'];
            $quote = $this->db->createOperation(['client_id' => $client, 'vehicle_id' => $vehicle, 'product_1' => $product, 'product_qty_1' => $quantity], 1);
            $invoice = $this->db->invoiceDocument($quote, 1);
            $this->invoices[] = $invoice;
            $this->db->pdo()->prepare('UPDATE operations SET created_at = :date WHERE id = :id')->execute(['date' => ['2026-09-10 00:00:00', '2026-09-11 23:59:59', '2026-09-12 12:00:00'][$index], 'id' => $invoice]);
        }
        // Duplicate lines on one invoice count once, while quantities are summed.
        $stmt = $this->db->pdo()->prepare("INSERT INTO operation_items (operation_id, product_id, line_type, label, quantity, unit_price, total) VALUES (:operation, :product, 'product', 'Huile 5W30', 1, 40, 40)");
        $stmt->execute(['operation' => $this->invoices[0], 'product' => $oil]);
        $this->db->pdo()->prepare("INSERT INTO operation_items (operation_id, line_type, label, quantity, unit_price, total) VALUES (:operation, 'service', 'Vidange <test>', 1, 50, 50)")->execute(['operation' => $this->invoices[0]]);
    }

    protected function tearDown(): void
    {
        unset($this->knowledge, $this->db);
        foreach (glob($this->directory . '/*') as $file) @unlink($file);
        @rmdir($this->directory . '/data');
        @rmdir($this->directory);
    }

    public function testInvoicesAggregateDistinctUsesQuantitiesAndServicesInFrequencyOrder(): void
    {
        $before = $this->db->pdo()->query('SELECT total_changes()')->fetchColumn();
        $groups = $this->knowledge->search();
        self::assertCount(2, $groups);
        self::assertSame('Alpha', $groups[0]['model']);
        self::assertSame(2, $groups[0]['uses']);
        self::assertSame(2, $groups[0]['products'][0]['uses']);
        self::assertSame(6.0, $groups[0]['products'][0]['quantity']);
        self::assertSame('INT-OIL', $groups[0]['products'][0]['reference']);
        self::assertSame('Vidange <test>', $groups[0]['services'][0]['name']);
        self::assertCount(2, $groups[0]['operations']);
        $this->knowledge->options();
        self::assertSame($before, $this->db->pdo()->query('SELECT total_changes()')->fetchColumn(), 'Consultation must never write.');
        $tester = new CommandTester(new StockCheckCommand($this->db));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
    }

    public function testCombinedFiltersInclusiveDatesLiteralSearchAndInvalidDates(): void
    {
        self::assertSame(['Beta'], array_column($this->knowledge->search(['model' => 'Beta']), 'model'));
        self::assertSame(['Alpha'], array_column($this->knowledge->search(['q' => '5w30']), 'model'));
        $groups = $this->knowledge->search(['brand' => 'knowledge motors', 'model' => 'Alpha', 'q' => '5W30', 'from' => '2026-09-11', 'to' => '2026-09-11', 'plate' => 'k-two']);
        self::assertCount(1, $groups);
        self::assertSame(3.0, $groups[0]['products'][0]['quantity']);
        self::assertSame([], $this->knowledge->search(['brand' => 'Other', 'model' => 'Alpha']));
        self::assertSame([], $this->knowledge->search(['q' => "' OR 1=1 --"]));
        self::assertSame([], $this->knowledge->search(['q' => '%']));
        self::assertSame([], $this->knowledge->search(['q' => '_']));
        foreach ([['2026-09-12', '2026-09-10'], ['2026-02-30', ''], ['bad', '']] as [$from, $to]) {
            $result = $this->knowledge->filters(['model' => 'Alpha', 'from' => $from, 'to' => $to]);
            self::assertTrue($result['invalid_dates']);
            self::assertSame('', $result['filters']['from']);
            self::assertSame('', $result['filters']['to']);
            self::assertCount(1, $this->knowledge->search($result['filters']));
        }
        self::assertFalse($this->knowledge->filters(['from' => '2024-02-29'])['invalid_dates']);
        self::assertCount(1, $this->knowledge->search(['from' => '2026-09-12']));
        self::assertCount(1, $this->knowledge->search(['to' => '2026-09-10']));
    }

    public function testLegacyTextFallbackMergesModelsAndIncludesInactiveReferences(): void
    {
        $id = $this->invoices[0];
        $this->db->pdo()->prepare("UPDATE operations SET vehicle_id = NULL, client_id = NULL, vehicle_brand = 'Knowledge Motors', vehicle_model = 'Alpha', vehicle_plate = 'OLD-PLATE' WHERE id = :id")->execute(['id' => $id]);
        $groups = $this->knowledge->search(['model' => 'Alpha', 'plate' => 'OLD-PLATE']);
        self::assertCount(1, $groups);
        self::assertSame($id, $groups[0]['operations'][0]['id']);
        self::assertSame('Knowledge <client>', $groups[0]['operations'][0]['client']);
        self::assertSame(2, $this->knowledge->search(['model' => 'Alpha'])[0]['uses']);
        $this->db->pdo()->exec('UPDATE vehicles SET active = 0; UPDATE vehicle_brands SET active = 0; UPDATE vehicle_models SET active = 0; UPDATE products SET active = 0');
        self::assertCount(2, $this->knowledge->search());
        self::assertContains('Alpha', $this->knowledge->options()['models']);
        $this->db->pdo()->prepare("UPDATE operations SET vehicle_brand = '', vehicle_model = '' WHERE id = :id")->execute(['id' => $id]);
        self::assertCount(3, $this->knowledge->search());
    }

    public function testBothRolesConsultFilterAndSeeRealOperationLinksInBothLanguages(): void
    {
        foreach ([1, 2] as $userId) {
            foreach (['fr', 'ar'] as $locale) {
                [$controller, $request] = $this->controller('/knowledge?model=Alpha&q=5W30', $userId, $locale);
                $response = $controller->knowledge($request, $this->db, new AccessControl(), $this->knowledge);
                self::assertSame(200, $response->getStatusCode());
                $html = $response->getContent();
                self::assertStringContainsString('Alpha', $html);
                self::assertStringNotContainsString('K-THREE', $html);
                foreach (array_slice($this->invoices, 0, 2) as $id) self::assertStringContainsString('href="/operations/' . $id . '"', $html);
                self::assertStringContainsString('Knowledge &lt;client&gt;', $html);
                self::assertStringContainsString('dir="' . ($locale === 'ar' ? 'rtl' : 'ltr') . '"', $html);
                self::assertStringNotContainsString('knowledge.title', $html);
                if ($userId === 1) {
                    self::assertStringContainsString('/knowledge/export/pdf?brand=', $html);
                    self::assertStringContainsString('model=Alpha&amp;q=5W30', $html);
                } else {
                    self::assertStringNotContainsString('/knowledge/export/pdf', $html);
                }
            }
        }
        [$controller, $request] = $this->controller('/knowledge?from=2026-09-12&to=2026-09-10&model=Alpha', 2);
        self::assertStringContainsString('role="alert"', $controller->knowledge($request, $this->db, new AccessControl(), $this->knowledge)->getContent());
        [$controller, $request] = $this->controller('/knowledge', 0);
        self::assertSame('/login', $controller->knowledge($request, $this->db, new AccessControl(), $this->knowledge)->headers->get('Location'));
    }

    public function testAdminExportReusesFiltersAndManagerIsDeniedServerSide(): void
    {
        [$controller, $request] = $this->controller('/knowledge/export/pdf?model=Alpha&q=5W30&from=2026-09-11&to=2026-09-11&plate=K-TWO', 1, 'ar');
        $response = $controller->knowledgeExportPdf($request, $this->db, new AccessControl(), $this->knowledge, new CompanyProfile());
        self::assertSame(200, $response->getStatusCode());
        $html = $response->getContent();
        foreach (['window.print()', 'SIM Auto', 'Base technique par modèle', 'Pièces et produits', 'Alpha', '5W30', '2026-09-11', 'K-TWO', 'INT-OIL', '<td>3</td>'] as $value) self::assertStringContainsString($value, $html);
        self::assertStringNotContainsString('Beta', $html);
        self::assertTrue((new AccessControl())->can('view.knowledge', ['role' => 'manager']));
        self::assertFalse((new AccessControl())->can('knowledge.export', ['role' => 'manager']));
        [$controller, $request] = $this->controller('/knowledge/export/pdf', 2);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $controller->knowledgeExportPdf($request, $this->db, new AccessControl(), $this->knowledge, new CompanyProfile());
    }

    private function controller(string $uri, int $userId, string $locale = 'fr'): array
    {
        $request = Request::create($uri);
        $request->setLocale($locale);
        $request->attributes->set('_route', str_contains($uri, '/export/') ? 'app_knowledge_export_pdf' : 'app_knowledge');
        $request->setSession(new Session(new MockArraySessionStorage()));
        if ($userId) $request->getSession()->set('user', ['id' => $userId]);
        $stack = new RequestStack();
        $stack->push($request);
        $routes = new RouteCollection();
        $routeLoader = new AttributeRouteControllerLoader();
        foreach (glob(__DIR__ . '/../src/Controller/*Controller.php') as $file) {
            $routes->addCollection($routeLoader->load('App\\Controller\\' . basename($file, '.php')));
        }
        $router = new UrlGenerator($routes, new RequestContext());
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['fr', 'ar'] as $language) $translator->addResource('yaml', __DIR__ . '/../translations/messages.' . $language . '.yaml', $language);
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../templates'), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', fn (string $key, array $params = [], ?string $domain = null, ?string $language = null) => $translator->trans($key, $params, $domain, $language)));
        $twig->addFunction(new TwigFunction('path', fn (string $name, array $params = []) => $router->generate($name, $params)));
        $twig->addFunction(new TwigFunction('asset', fn (string $path) => '/' . $path));
        $twig->addFunction(new TwigFunction('can', fn (string $permission, array $user) => (new AccessControl())->can($permission, $user)));
        $twig->addGlobal('app_name', 'SIM Auto');
        $twig->addGlobal('app', new class($request) {
            public function __construct(public Request $request) {}
            public function flashes(string $type): array { return $this->request->getSession()->getFlashBag()->get($type); }
        });
        $container = new Container();
        $container->set('request_stack', $stack);
        $container->set('router', $router);
        $container->set('twig', $twig);
        $controller = new DashboardController();
        $controller->setContainer($container);
        return [$controller, $request];
    }
}
