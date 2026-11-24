<?php

namespace App\Tests;

use App\Command\StockCheckCommand;
use App\Controller\DashboardController;
use App\Service\AccessControl;
use App\Service\AppDatabase;
use App\Twig\MoneyExtension;
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

final class SupplierCostsTest extends TestCase
{
    private AppDatabase $db;
    private string $directory;
    private int $product;
    private int $a;
    private int $b;
    private int $unknown;
    private int $client;
    private int $vehicle;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/simauto-costs-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->db = new AppDatabase($this->directory, $this->directory . '/test.sqlite');
        $this->db->saveProduct(['sku' => 'COST-OIL', 'name' => 'Huile test', 'category_id' => $this->db->categories()[0]['id'], 'purchase_price' => 80, 'sale_price' => 150], 1);
        $this->product = (int) $this->db->productBySku('COST-OIL')['id'];
        foreach (['a' => 'Supplier A <test>', 'b' => 'Supplier B', 'unknown' => 'Unknown cost'] as $property => $name) {
            $this->db->saveSupplier(['name' => $name]);
            $this->$property = (int) $this->db->supplierByName($name)['id'];
        }
        $this->db->saveClient(['name' => 'Oil buyer']);
        $this->client = (int) $this->db->pdo()->lastInsertId();
        $brand = $this->db->getOrCreateVehicleBrand('Hyundai');
        $model = $this->db->getOrCreateVehicleModel($brand, 'Accent');
        $this->db->saveVehicle(['client_id' => $this->client, 'plate' => 'COST-TEST', 'brand_id' => $brand, 'model_id' => $model]);
        $this->vehicle = (int) $this->db->vehicleByPlate('COST-TEST')['id'];
    }

    protected function tearDown(): void
    {
        unset($this->db);
        foreach (glob($this->directory . '/*') as $file) @unlink($file);
        @rmdir($this->directory);
    }

    public function testLatestPurchaseIsReferenceWhileCheapestSupplierIsOnlyAComparison(): void
    {
        foreach ([[$this->a, 100], [$this->b, 90], [$this->a, 105]] as [$supplier, $cost]) {
            $this->db->addStock($this->product, 2, 'Purchase', 2, $supplier, $cost);
            self::assertSame((float) $cost, (float) $this->db->product($this->product)['purchase_price']);
        }
        // Equal timestamps must resolve by id, independently of the wall clock.
        $this->db->pdo()->exec("UPDATE stock_movements SET created_at = '2026-11-24 10:00:00' WHERE product_id = " . $this->product);
        $before = $this->db->pdo()->query('SELECT total_changes()')->fetchColumn();
        $costs = $this->db->supplierCostsForProduct($this->product);
        self::assertSame([$this->b, $this->a], array_map('intval', array_column($costs, 'supplier_id')));
        self::assertSame([90.0, 105.0], array_map('floatval', array_column($costs, 'unit_cost')));
        self::assertSame(105.0, (float) $this->db->product($this->product)['purchase_price']);
        self::assertSame(150.0, (float) $this->db->product($this->product)['sale_price']);
        self::assertCount(3, $this->db->productSupplierEntries($this->product));
        self::assertSame($before, $this->db->pdo()->query('SELECT total_changes()')->fetchColumn());
        $checker = new CommandTester(new StockCheckCommand($this->db));
        self::assertSame(0, $checker->execute([]), $checker->getDisplay());
    }

    public function testUnknownCostDoesNotEraseKnownCostAndExplicitZeroIsPreserved(): void
    {
        $this->db->addStock($this->product, 1, '', 1, $this->a, 100);
        $this->db->addStock($this->product, 1, '', 1, $this->a, null);
        self::assertSame(100.0, (float) $this->db->supplierCostsForProduct($this->product)[0]['unit_cost']);
        self::assertSame(100.0, (float) $this->db->product($this->product)['purchase_price']);
        $this->db->addStock($this->product, 1, '', 1, $this->a, 0);
        $this->db->addStock($this->product, 1, '', 1, $this->a, null);
        self::assertSame(0.0, (float) $this->db->supplierCostsForProduct($this->product)[0]['unit_cost']);
        self::assertSame(0.0, (float) $this->db->product($this->product)['purchase_price']);
        $other = (int) $this->db->products()[0]['id'];
        $this->db->addStock($other, 1, '', 1, $this->b, 999);
        self::assertSame([$this->a], array_map('intval', array_column($this->db->supplierCostsForProduct($this->product), 'supplier_id')));
        self::assertSame([], $this->db->supplierCostsForProduct(999999));
    }

    public function testOnlyGenuineSupplierPurchasesSupplyCostReferences(): void
    {
        $this->db->addStock($this->product, 10, 'Purchase', 1, $this->a, 100);
        $order = $this->db->createPurchaseOrder($this->productPayload(), 2);
        $this->db->resetOrderToDraft($order, 2);
        $stmt = $this->db->pdo()->prepare('UPDATE stock_movements SET supplier_id = :supplier, unit_cost = 1 WHERE operation_id = :operation');
        $stmt->execute(['supplier' => $this->a, 'operation' => $order]);
        $this->db->addStock($this->product, 1, 'Annulation BC LEGACY', 1, $this->a, 2);
        $this->db->adjustStock($this->product, 20, 'Inventory', 1);
        $this->db->pdo()->exec("UPDATE stock_movements SET supplier_id = " . $this->a . ", unit_cost = 0 WHERE product_id = " . $this->product . " AND movement_type = 'adjustment'");
        $costs = $this->db->supplierCostsForProduct($this->product);
        self::assertCount(1, $costs);
        self::assertSame(100.0, (float) $costs[0]['unit_cost']);
        self::assertSame([], $this->db->stockConsistencyIssues());
    }

    public function testComparisonAndStockPageAreReadOnlyTranslatedAndOpenToBothRoles(): void
    {
        $this->db->addStock($this->product, 1, '', 1, $this->a, 105);
        $this->db->addStock($this->product, 1, '', 1, $this->b, 90);
        foreach ([1, 2] as $user) {
            foreach (['fr', 'ar'] as $locale) {
                $before = $this->snapshot();
                [$controller, $request] = $this->controller('/stock/supplier-costs?product_id=' . $this->product, $user, 'GET', [], $locale);
                $response = $controller->stockSupplierCosts($request, $this->db, new AccessControl());
                self::assertSame(200, $response->getStatusCode());
                self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                $html = $response->getContent();
                self::assertStringContainsString('Supplier A &lt;test&gt;', $html);
                self::assertStringNotContainsString('stock.last_known_cost', $html);
                self::assertLessThan(strpos($html, 'data-supplier-id="' . $this->a . '"'), strpos($html, 'data-supplier-id="' . $this->b . '"'));
                [$controller, $request] = $this->controller('/stock?product=' . $this->product, $user, 'GET', [], $locale);
                $html = $controller->stock($request, $this->db)->getContent();
                self::assertStringContainsString('data-stock-cost-form', $html);
                self::assertStringContainsString('<noscript>', $html);
                self::assertStringContainsString('value="' . $this->unknown . '"', $html);
                self::assertStringContainsString('id="stock-cost-reference"', $html);
                self::assertStringContainsString('dir="' . ($locale === 'ar' ? 'rtl' : 'ltr') . '"', $html);
                self::assertSame($before, $this->snapshot());
            }
        }
        [$controller, $request] = $this->controller('/stock/supplier-costs?product_id=' . $this->product, 0);
        self::assertSame('/login', $controller->stockSupplierCosts($request, $this->db, new AccessControl())->headers->get('Location'));
        $this->db->pdo()->exec('UPDATE users SET active = 0 WHERE id = 2');
        [$controller, $request] = $this->controller('/stock/supplier-costs?product_id=' . $this->product, 2);
        self::assertSame('/login', $controller->stockSupplierCosts($request, $this->db, new AccessControl())->headers->get('Location'));
        $this->db->pdo()->exec('UPDATE users SET active = 1 WHERE id = 2');
        $denied = $this->createMock(AccessControl::class);
        $denied->method('can')->willReturn(false);
        [$controller, $request] = $this->controller('/stock/supplier-costs?product_id=' . $this->product, 2);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $controller->stockSupplierCosts($request, $this->db, $denied);
    }

    public function testComparisonRejectsMissingInactiveAndServiceProducts(): void
    {
        $this->db->saveProduct(['sku' => 'COST-SERVICE', 'name' => 'Service', 'category_id' => $this->db->categories()[0]['id'], 'product_type' => 'service'], 1);
        $service = (int) $this->db->productBySku('COST-SERVICE')['id'];
        foreach ([999999, $this->product, $service] as $id) {
            if ($id === $this->product) $this->db->pdo()->exec('UPDATE products SET active = 0 WHERE id = ' . $id);
            [$controller, $request] = $this->controller('/stock/supplier-costs?product_id=' . $id, 1);
            try {
                $controller->stockSupplierCosts($request, $this->db, new AccessControl());
                self::fail('Only an active stockable product can be compared.');
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                self::assertSame([], $this->db->supplierCostsForProduct($id));
            }
        }
    }

    public function testSubmittedCostOverridesSuggestionWithoutChangingSalePrice(): void
    {
        $this->db->addStock($this->product, 1, '', 1, $this->a, 100);
        [$controller, $request] = $this->controller('/stock/in', 2, 'POST', ['product_id' => $this->product, 'supplier_id' => $this->a, 'quantity' => 2, 'unit_cost' => '107.50']);
        $controller->stockIn($request, $this->db, new AccessControl());
        self::assertSame(3, (int) $this->db->product($this->product)['stock_qty']);
        self::assertSame(107.5, (float) $this->db->product($this->product)['purchase_price']);
        self::assertSame(150.0, (float) $this->db->product($this->product)['sale_price']);
        self::assertSame(107.5, (float) $this->db->supplierCostsForProduct($this->product)[0]['unit_cost']);
        self::assertSame(1, (int) $this->db->pdo()->query("SELECT count(*) FROM activity_logs WHERE action_type = 'stock_in'")->fetchColumn());
    }

    public function testMissingCostAndMissingSupplierHaveSeparateSemantics(): void
    {
        foreach ([['unit_cost' => '112'], [], ['unit_cost' => '   '], ['unit_cost' => '0']] as $index => $extra) {
            [$controller, $request] = $this->controller('/stock/in', 2, 'POST', ['product_id' => $this->product, 'quantity' => 1] + $extra);
            $controller->stockIn($request, $this->db, new AccessControl());
            self::assertSame($index < 3 ? 112.0 : 0.0, (float) $this->db->product($this->product)['purchase_price']);
        }
        self::assertSame(4, (int) $this->db->product($this->product)['stock_qty']);
        self::assertSame([], $this->db->supplierCostsForProduct($this->product));
        $costs = $this->db->pdo()->query('SELECT unit_cost FROM stock_movements WHERE product_id = ' . $this->product . ' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([112.0, null, null, 0.0], array_map(static fn ($cost) => $cost === null ? null : (float) $cost, $costs));
    }

    public function testInvalidCostAndCsrfNeverChangeStockReferenceOrAudit(): void
    {
        foreach (['-1', 'abc', 'NaN', 'INF', '1e309', '-1e309'] as $cost) {
            $before = $this->snapshot();
            [$controller, $request] = $this->controller('/stock/in', 2, 'POST', ['product_id' => $this->product, 'supplier_id' => $this->a, 'quantity' => 1, 'unit_cost' => $cost]);
            $controller->stockIn($request, $this->db, new AccessControl());
            self::assertSame($before, $this->snapshot(), $cost);
        }
        foreach ([-1.0, INF, -INF, NAN] as $cost) {
            $before = $this->snapshot();
            try {
                $this->db->addStock($this->product, 1, '', 1, $this->a, $cost);
                self::fail('Non-finite and negative costs must be refused.');
            } catch (\InvalidArgumentException) {
                self::assertSame($before, $this->snapshot());
            }
        }
        foreach ([[], ['unit_cost' => 95]] as $extra) {
            $before = $this->snapshot();
            [$controller, $request] = $this->controller('/stock/in', 2, 'POST', ['product_id' => $this->product, 'quantity' => 1] + $extra);
            $request->request->remove('_token');
            $controller->stockIn($request, $this->db, new AccessControl());
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testReferenceUpdateFailureRollsBackStockMovementAndSalePrice(): void
    {
        $this->db->pdo()->exec("CREATE TRIGGER reject_cost BEFORE UPDATE OF purchase_price ON products BEGIN SELECT RAISE(ABORT, 'cost failure'); END");
        $before = $this->snapshot();
        [$controller, $request] = $this->controller('/stock/in', 2, 'POST', ['product_id' => $this->product, 'supplier_id' => $this->a, 'quantity' => 1, 'unit_cost' => 95, 'sale_price' => 160, 'update_sale_price' => '1']);
        $controller->stockIn($request, $this->db, new AccessControl());
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $this->db->stockConsistencyIssues());
    }

    public function testProductOnlyPostWorksForManagerAndAdminWithNoPhantomService(): void
    {
        $this->db->addStock($this->product, 10, '', 1, $this->a, 100);
        foreach ([1, 2] as $user) {
            $services = $this->db->pdo()->query("SELECT count(*) FROM products WHERE product_type = 'service'")->fetchColumn();
            $stock = (int) $this->db->product($this->product)['stock_qty'];
            [$controller, $request] = $this->controller('/operations', $user);
            self::assertStringContainsString('Services (facultatifs)', $controller->operations($request, $this->db)->getContent());
            [$controller, $request] = $this->controller('/operations/new', $user, 'POST', $this->productPayload());
            $controller->newOperation($request, $this->db, new AccessControl());
            $id = (int) $this->db->pdo()->query('SELECT max(id) FROM operations')->fetchColumn();
            $operation = $this->db->operation($id);
            self::assertSame($user === 1 ? 'quote' : 'order', $operation['doc_type']);
            self::assertCount(1, $operation['items']);
            self::assertSame('product', $operation['items'][0]['line_type']);
            self::assertSame(300.0, (float) $operation['total_ttc']);
            self::assertSame(100.0, (float) $operation['total_margin']);
            self::assertSame($services, $this->db->pdo()->query("SELECT count(*) FROM products WHERE product_type = 'service'")->fetchColumn());
            self::assertSame($user === 1 ? $stock : $stock - 2, (int) $this->db->product($this->product)['stock_qty']);
            if ($user === 1) {
                [$controller, $request] = $this->controller('/operations/' . $id . '/confirm', $user, 'POST');
                $controller->confirmOperation($id, $request, $this->db, new AccessControl());
                $id = (int) $this->db->pdo()->query('SELECT max(id) FROM operations')->fetchColumn();
            }
            [$controller, $request] = $this->controller('/operations/' . $id . '/invoice', $user, 'POST');
            $controller->invoiceOperation($id, $request, $this->db, new AccessControl());
            self::assertSame($stock - 2, (int) $this->db->product($this->product)['stock_qty']);
        }
        self::assertSame([], $this->db->stockConsistencyIssues());
    }

    public function testEmptyOperationAndInvalidPartialServicesAreRefused(): void
    {
        $this->db->addStock($this->product, 10, '', 1, $this->a, 100);
        $empty = $this->productPayload();
        $empty['line_product_id'][0] = '';
        $empty['line_unit_price'][0] = 0;
        $invalid = [];
        foreach ([['line_unit_price', -1], ['line_discount', 5], ['line_quantity', -1]] as [$field, $value]) {
            $payload = $this->productPayload();
            $payload[$field][1] = $value;
            $invalid[] = $payload;
        }
        foreach (array_merge([$empty], $invalid) as $payload) {
            $before = $this->snapshot();
            [$controller, $request] = $this->controller('/operations/new', 2, 'POST', $payload);
            $controller->newOperation($request, $this->db, new AccessControl());
            self::assertSame($before, $this->snapshot());
        }
        $serviceOnly = $empty;
        $serviceOnly['line_label'][1] = 'Service only';
        $serviceOnly['line_unit_price'][1] = 50;
        [$controller, $request] = $this->controller('/operations/new', 2, 'POST', $serviceOnly);
        $controller->newOperation($request, $this->db, new AccessControl());
        $id = (int) $this->db->pdo()->query('SELECT max(id) FROM operations')->fetchColumn();
        self::assertSame(['service'], array_column($this->db->operation($id)['items'], 'line_type'));
        self::assertSame(10, (int) $this->db->product($this->product)['stock_qty']);
    }

    private function productPayload(): array
    {
        return ['client_id' => $this->client, 'vehicle_id' => $this->vehicle,
            'line_type' => ['product', 'service'], 'line_product_id' => [$this->product, ''],
            'line_label' => ['', ''], 'line_quantity' => [2, 1], 'line_unit_price' => [150, 0],
            'line_margin_mode' => ['manual', 'manual'], 'line_discount' => [0, 0]];
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['products', 'stock_movements', 'operations', 'operation_items', 'activity_logs'] as $table) {
            $snapshot[$table] = $this->db->pdo()->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
        }
        return $snapshot;
    }

    private function controller(string $uri, int $user, string $method = 'GET', array $payload = [], string $locale = 'fr'): array
    {
        $request = Request::create($uri, $method, $payload);
        $request->setLocale($locale);
        $request->attributes->set('_route', 'app_stock');
        $request->setSession(new Session(new MockArraySessionStorage()));
        if ($user) $request->getSession()->set('user', ['id' => $user]);
        $request->getSession()->set('csrf_tokens', ['stock_in' => 'valid-token', 'operation_form' => 'valid-token', 'operation_action' => 'valid-token']);
        if ($method === 'POST') $request->request->set('_token', 'valid-token');
        $routes = new RouteCollection();
        $routeLoader = new AttributeRouteControllerLoader();
        foreach (glob(__DIR__ . '/../src/Controller/*Controller.php') as $file) $routes->addCollection($routeLoader->load('App\\Controller\\' . basename($file, '.php')));
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
        $twig->addFunction(new TwigFunction('can_edit_document', fn (array $user, array $operation) => (new AccessControl())->canEditDocument($user, $operation)));
        $twig->addGlobal('app_name', 'SIM Auto');
        $twig->addGlobal('app', new class($request) {
            public function __construct(public Request $request) {}
            public function flashes(string $type): array { return $this->request->getSession()->getFlashBag()->get($type); }
        });
        $container = new Container();
        $stack = new RequestStack();
        $stack->push($request);
        $container->set('request_stack', $stack);
        $container->set('router', $router);
        $container->set('twig', $twig);
        $controller = new DashboardController();
        $controller->setContainer($container);
        return [$controller, $request];
    }
}
