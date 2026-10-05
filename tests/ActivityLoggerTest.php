<?php

namespace App\Tests;

use App\Command\StockCheckCommand;
use App\Controller\DashboardController;
use App\Controller\ImportController;
use App\Controller\AuthController;
use App\Service\AccessControl;
use App\Service\ActivityLogger;
use App\Service\AppDatabase;
use App\Service\ImportService;
use App\Twig\MoneyExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class ActivityLoggerTest extends TestCase
{
    private AppDatabase $db;
    private string $directory;
    private array $files = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/simauto-audit-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->db = new AppDatabase($this->directory, $this->directory . '/test.sqlite');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) { @unlink($file); }
        foreach (glob($this->directory . '/*') as $file) { @unlink($file); }
        @rmdir($this->directory);
    }

    public function testStockAndReferenceActionsLogOnlySuccessfulWrites(): void
    {
        $payload = ['name' => 'Audit product', 'sku' => 'AUDIT-1', 'category_id' => $this->db->categories()[0]['id'], 'stock_qty' => 10, 'purchase_price' => 10, 'sale_price' => 20];
        [$controller, $request] = $this->controller('/products/new', 'POST', $payload, 2);
        $controller->newProduct($request, $this->db, new AccessControl());
        $product = $this->db->productBySku('AUDIT-1');
        self::assertNotNull($product);
        $this->assertLog('product_create', 'product', (int) $product['id'], 2);
        $payload['name'] = 'Updated product';
        [$controller, $request] = $this->controller('/products/' . $product['id'] . '/edit', 'POST', $payload, 2);
        $controller->productEdit((int) $product['id'], $request, $this->db, new AccessControl());
        $this->assertLog('product_update', 'product', (int) $product['id'], 2);
        [$controller, $request] = $this->controller('/stock/in', 'POST', ['product_id' => $product['id'], 'quantity' => 4, 'note' => 'Audit delivery', 'unit_cost' => 10], 2);
        $controller->stockIn($request, $this->db, new AccessControl());
        $movementId = (int) $this->db->pdo()->query('SELECT MAX(id) FROM stock_movements')->fetchColumn();
        $this->assertLog('stock_in', 'stock_movement', $movementId, 2);
        self::assertSame(14, (int) $this->db->product((int) $product['id'])['stock_qty']);
        [$controller, $request] = $this->controller('/clients', 'POST', ['name' => 'Audit client'], 2);
        $controller->clients($request, $this->db, new AccessControl());
        $clientId = (int) $this->db->pdo()->query('SELECT MAX(id) FROM clients')->fetchColumn();
        $this->assertLog('client_create', 'client', $clientId, 2);
        [$controller, $request] = $this->controller('/clients/' . $clientId . '/edit', 'POST', ['name' => 'Updated client'], 2);
        $controller->clientEdit($clientId, $request, $this->db, new AccessControl());
        $this->assertLog('client_update', 'client', $clientId, 2);
        $before = $this->countLogs();
        [$controller, $request] = $this->controller('/stock/in', 'POST', ['product_id' => $product['id'], 'quantity' => -1], 2);
        $controller->stockIn($request, $this->db, new AccessControl());
        self::assertSame($before, $this->countLogs());
        self::assertSame([], $this->db->stockConsistencyIssues());
    }

    public function testDocumentCycleAndDirectInvoiceLogDistinctDocuments(): void
    {
        $product = $this->db->products()[0];
        $beforeStock = (int) $product['stock_qty'];
        $payload = $this->operationPayload((int) $product['id']);
        [$controller, $request] = $this->controller('/operations/new', 'POST', $payload, 2);
        $controller->newOperation($request, $this->db, new AccessControl());
        $quote = (int) $this->db->pdo()->query('SELECT MAX(id) FROM operations')->fetchColumn();
        $this->assertLog('operation_create', 'operation', $quote, 2);
        [$controller, $request] = $this->controller('/operations/' . $quote . '/confirm', 'POST', [], 2);
        $controller->confirmOperation($quote, $request, $this->db, new AccessControl());
        $order = (int) $this->db->pdo()->query('SELECT MAX(id) FROM operations')->fetchColumn();
        $this->assertLog('operation_confirm', 'operation', $order, 2);
        self::assertSame($beforeStock - 1, (int) $this->db->product((int) $product['id'])['stock_qty']);
        [$controller, $request] = $this->controller('/operations/' . $order . '/invoice', 'POST', [], 2);
        $controller->invoiceOperation($order, $request, $this->db, new AccessControl());
        $invoice = (int) $this->db->pdo()->query('SELECT MAX(id) FROM operations')->fetchColumn();
        $this->assertLog('operation_invoice', 'operation', $invoice, 2);
        self::assertSame(3, $this->countLogs());
        self::assertSame($beforeStock - 1, (int) $this->db->product((int) $product['id'])['stock_qty']);
        self::assertStringContainsString($this->db->operation($invoice)['document_no'], (new ActivityLogger($this->db))->search([])['rows'][0]['description']);
        // Direct invoice uses the same action type but a separate invoice entity.
        $directQuote = $this->db->createOperation($payload, 1);
        [$controller, $request] = $this->controller('/operations/' . $directQuote . '/invoice-direct', 'POST', [], 1);
        $controller->invoiceDirectOperation($directQuote, $request, $this->db, new AccessControl());
        $directInvoice = (int) $this->db->pdo()->query('SELECT MAX(id) FROM operations')->fetchColumn();
        $this->assertLog('operation_invoice', 'operation', $directInvoice, 1);
        self::assertSame($beforeStock - 2, (int) $this->db->product((int) $product['id'])['stock_qty']);
        $tester = new CommandTester(new StockCheckCommand($this->db));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
    }

    public function testQuoteEditAndOrderResetAreLoggedAfterSuccess(): void
    {
        $product = $this->db->products()[0];
        $payload = $this->operationPayload((int) $product['id']);
        $quote = $this->db->createOperation($payload, 2);
        [$controller, $request] = $this->controller('/operations/' . $quote . '/edit', 'POST', $payload, 2);
        $controller->operationEdit($quote, $request, $this->db, new AccessControl());
        $this->assertLog('operation_edit', 'operation', $quote, 2);
        $order = $this->db->confirmQuote($quote, 2);
        [$controller, $request] = $this->controller('/operations/' . $order . '/reset-draft', 'POST', [], 2);
        $controller->resetOrderDraft($order, $request, $this->db, new AccessControl());
        $this->assertLog('operation_reset_draft', 'operation', $order, 2);
        self::assertSame((int) $product['stock_qty'], (int) $this->db->product((int) $product['id'])['stock_qty']);
        self::assertSame('quote', $this->db->operation($order)['doc_type']);
        $count = $this->countLogs();
        // Reset cannot succeed twice, so a rejected attempt adds no audit event.
        $controller->resetOrderDraft($order, $request, $this->db, new AccessControl());
        self::assertSame($count, $this->countLogs());
        self::assertSame([], $this->db->stockConsistencyIssues());
    }

    public function testAuditAccessFiltersDatesAndRealTranslatedTemplate(): void
    {
        $logger = new ActivityLogger($this->db);
        $logger->log(['id' => 1, 'name' => 'Alice'], 'stock_in', 'product', 1, 'Delivery <script>alert(1)</script>');
        $logger->log(['id' => 2, 'name' => 'Bob'], 'client_create', 'client', 1, 'New client');
        $logger->log(['id' => 1, 'name' => 'Alice'], 'client_create', 'client', 2, 'Other client');
        $this->db->pdo()->exec("UPDATE activity_logs SET created_at = '2026-09-15 23:59:59' WHERE id = 1");
        $this->db->pdo()->exec("UPDATE activity_logs SET created_at = '2026-09-16 00:00:00' WHERE id > 1");
        self::assertCount(2, $logger->search(['action_type' => 'client_create'])['rows']);
        self::assertCount(2, $logger->search(['user_id' => '1'])['rows']);
        self::assertCount(1, $logger->search(['action_type' => 'client_create', 'user_id' => '2'])['rows']);
        self::assertCount(1, $logger->search(['from' => '2026-09-15', 'to' => '2026-09-15'])['rows']);
        self::assertCount(2, $logger->search(['q' => 'Alice'])['rows']);
        self::assertCount(1, $logger->search(['q' => 'Delivery'])['rows']);
        self::assertCount(0, $logger->search(['q' => "' OR 1=1 --"])['rows']);
        foreach (['fr' => 'Journal d’activité', 'ar' => 'سجل النشاط'] as $locale => $title) {
            [$controller, $request] = $this->controller('/audit', 'GET', [], 1, $locale);
            $response = $controller->audit($request, $this->db, new AccessControl());
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString($title, $response->getContent());
            self::assertStringContainsString('&lt;script&gt;', $response->getContent());
            self::assertStringNotContainsString('<script>alert(1)</script>', $response->getContent());
            self::assertStringNotContainsString('audit.action.stock_in', $response->getContent());
            self::assertStringContainsString('href="/app_audit"', $response->getContent());
        }
        foreach ([['from' => '2026-09-16', 'to' => '2026-09-15'], ['from' => '2026-02-30'], ['to' => 'bad-date']] as $dates) {
            [$controller, $request] = $this->controller('/audit', 'GET', $dates, 1);
            $response = $controller->audit($request, $this->db, new AccessControl());
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(3, $controller->context['result']['total']);
            self::assertSame('', $controller->context['filters']['from']);
            self::assertStringContainsString('Dates invalides', $response->getContent());
        }
        [$controller, $request] = $this->controller('/audit', 'GET', ['action_type' => 'client_create', 'user_id' => '2'], 1);
        $controller->audit($request, $this->db, new AccessControl());
        self::assertSame(1, $controller->context['result']['total']);
        foreach ([2 => '/app_dashboard', 0 => '/app_login'] as $id => $target) {
            [$controller, $request] = $this->controller('/audit', 'GET', [], $id);
            $response = $controller->audit($request, $this->db, new AccessControl());
            self::assertSame(302, $response->getStatusCode());
            self::assertSame($target, $response->getTargetUrl());
        }
        self::assertFalse((new AccessControl())->can('audit.view', ['role' => 'manager']));
        [$controller, $request] = $this->controller('/clients', 'GET', [], 2);
        self::assertStringNotContainsString('href="/app_audit"', $controller->clients($request, $this->db, new AccessControl())->getContent());
    }

    public function testAuditFailureDoesNotBreakInvoicesStockOrOtherBusinessWrites(): void
    {
        $product = $this->db->products()[0];
        $quote = $this->db->createOperation($this->operationPayload((int) $product['id']), 1);
        // Failure is real SQLite rejection, not a mock logger that skips the write.
        $this->db->pdo()->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON activity_logs BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
        [$controller, $request] = $this->controller('/operations/' . $quote . '/invoice-direct', 'POST', [], 1);
        $response = $controller->invoiceDirectOperation($quote, $request, $this->db, new AccessControl());
        $invoice = (int) $this->db->pdo()->query('SELECT MAX(id) FROM operations')->fetchColumn();
        self::assertSame('/app_operation_show/' . $invoice, $response->getTargetUrl());
        self::assertSame('invoice', $this->db->operation($invoice)['doc_type']);
        self::assertSame((int) $product['stock_qty'] - 1, (int) $this->db->product((int) $product['id'])['stock_qty']);
        self::assertSame([], $request->getSession()->getFlashBag()->get('error'));
        self::assertSame(0, $this->countLogs());
        self::assertFalse($this->db->pdo()->inTransaction());
        $tester = new CommandTester(new StockCheckCommand($this->db));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        $this->db->pdo()->exec('DROP TABLE activity_logs');
        [$controller, $request] = $this->controller('/clients', 'POST', ['name' => 'Client without audit'], 1);
        $controller->clients($request, $this->db, new AccessControl());
        self::assertSame(1, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM clients WHERE name = 'Client without audit'")->fetchColumn());
        self::assertSame([], $request->getSession()->getFlashBag()->get('error'));
    }

    public function testMigrationSnapshotsSystemEventsAndResultLimit(): void
    {
        $logger = new ActivityLogger($this->db);
        $logger->log(['id' => 1, 'name' => 'Original author'], 'client_create', 'client', 1, 'Created');
        $this->db->pdo()->exec("UPDATE users SET name = 'Renamed author' WHERE id = 1");
        $logger->log(null, 'import', null, null, 'System import');
        $reopened = new AppDatabase($this->directory, $this->directory . '/test.sqlite');
        self::assertSame(2, (new ActivityLogger($reopened))->search([])['total']);
        self::assertSame('Original author', $logger->search(['user_id' => '1'])['rows'][0]['user_name']);
        self::assertNull($logger->search(['action_type' => 'import'])['rows'][0]['user_id']);
        self::assertCount(3, $this->db->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name LIKE 'idx_activity_logs_%'")->fetchAll());
        for ($i = 0; $i < 201; $i++) { $logger->log(null, 'import', null, null, 'Batch ' . $i); }
        $result = $logger->search([]);
        self::assertSame(203, $result['total']);
        self::assertCount(200, $result['rows']);
        self::assertTrue($result['limited']);
        self::assertSame('Batch 200', $result['rows'][0]['description']);
        $this->db->pdo()->beginTransaction();
        $logger->log(null, 'import', null, null, 'Must not enter business transaction');
        $this->db->pdo()->rollBack();
        self::assertSame(203, $logger->search([])['total']);
    }

    public function testImportWritesOneSummaryAndSuccessfulLoginWritesAuthor(): void
    {
        $path = $this->directory . '/clients.csv';
        file_put_contents($path, "type;name;surname;phone;email;address;ice;vat;rc\nindividual;Audit imported;;0601234567;;;;;\n");
        [$controller, $request] = $this->controller('/import/clients', 'POST', [], 1);
        $request->files->set('file', new UploadedFile($path, 'clients.csv', 'text/csv', null, true));
        $imports = new ImportController();
        $imports->setContainer($this->container($request));
        $response = $imports->upload('clients', $request, $this->db, new ImportService($this->db), new AccessControl());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->countLogs());
        $row = (new ActivityLogger($this->db))->search([])['rows'][0];
        self::assertSame('import', $row['action_type']);
        self::assertStringContainsString('1 créés, 0 mis à jour', $row['description']);
        $this->db->changeUserPassword(1, 'AuditPassword123!', 'AuditPassword123!');
        [$controller, $request] = $this->controller('/login', 'POST', ['email' => $this->db->userById(1)['email'], 'password' => 'AuditPassword123!'], 0);
        $auth = new AuthController();
        $auth->setContainer($this->container($request));
        self::assertSame('/app_dashboard', $auth->login($request, $this->db)->getTargetUrl());
        $this->assertLog('login', 'user', 1, 1);
        self::assertStringNotContainsString('AuditPassword123!', json_encode((new ActivityLogger($this->db))->search([])));
    }

    public function testStockMaintenanceUsersAndOtherReferenceHooks(): void
    {
        $product = $this->db->products()[0];
        [$controller, $request] = $this->controller('/stock/in', 'POST', ['product_id' => $product['id'], 'quantity' => 2, 'note' => 'Maintenance audit', 'unit_cost' => 10], 1);
        $controller->stockIn($request, $this->db, new AccessControl());
        $movement = (int) $this->db->pdo()->query('SELECT MAX(id) FROM stock_movements')->fetchColumn();
        [$controller, $request] = $this->controller('/stock/movements/' . $movement . '/edit', 'POST', ['movement_type' => 'in', 'quantity' => 3, 'note' => 'Updated delivery', 'unit_cost' => 10], 1);
        $controller->stockMovementEdit($movement, $request, $this->db, new AccessControl());
        $this->assertLog('stock_movement_update', 'stock_movement', $movement, 1);
        [$controller, $request] = $this->controller('/stock/movements/' . $movement . '/delete', 'POST', [], 1);
        $controller->stockMovementDelete($movement, $request, $this->db, new AccessControl());
        $this->assertLog('stock_movement_delete', 'stock_movement', $movement, 1);
        [$controller, $request] = $this->controller('/stock/adjust', 'POST', ['product_id' => $product['id'], 'real_quantity' => 15, 'reason' => 'Inventaire audit'], 1);
        $controller->stockAdjust($request, $this->db, new AccessControl());
        $this->assertLog('stock_adjust', 'product', (int) $product['id'], 1);
        self::assertSame([], $this->db->stockConsistencyIssues());
        foreach (['suppliers' => ['supplier', 'suppliers', 'supplierEdit'], 'categories' => ['category', 'categories', 'categoryEdit']] as $method => [$entity, $table, $edit]) {
            [$controller, $request] = $this->controller('/' . $method, 'POST', ['name' => 'Audit ' . $entity], 1);
            $controller->$method($request, $this->db, new AccessControl());
            $id = (int) $this->db->pdo()->query('SELECT MAX(id) FROM ' . $table)->fetchColumn();
            $this->assertLog($entity . '_create', $entity, $id, 1);
            [$controller, $request] = $this->controller('/' . $method . '/' . $id . '/edit', 'POST', ['name' => 'Updated ' . $entity], 1);
            $controller->$edit($id, $request, $this->db, new AccessControl());
            $this->assertLog($entity . '_update', $entity, $id, 1);
        }
        [$controller, $request] = $this->controller('/vehicles/settings', 'POST', ['kind' => 'brand', 'name' => 'AUDIT brand'], 1);
        $controller->vehicleSettings($request, $this->db, new AccessControl());
        $brand = (int) $this->db->vehicleBrandByName('AUDIT brand')['id'];
        $this->assertLog('vehicle_brand_create', 'vehicle_brand', $brand, 1);
        // An existing brand must never be attributed to a different, newer brand.
        $this->db->saveVehicleBrand('Other brand');
        $controller->vehicleSettings($request, $this->db, new AccessControl());
        $this->assertLog('vehicle_brand_create', 'vehicle_brand', $brand, 1);
        [$controller, $request] = $this->controller('/vehicles/settings', 'POST', ['kind' => 'model', 'name' => 'AUDIT model', 'brand_id' => $brand], 1);
        $controller->vehicleSettings($request, $this->db, new AccessControl());
        $model = (int) $this->db->vehicleModelByName($brand, 'AUDIT model')['id'];
        $this->assertLog('vehicle_model_create', 'vehicle_model', $model, 1);
        foreach (['vehicleBrandEdit' => [$brand, 'vehicle_brand'], 'vehicleModelEdit' => [$model, 'vehicle_model']] as $method => [$id, $entity]) {
            [$controller, $request] = $this->controller('/settings/' . $id . '/edit', 'POST', ['name' => 'Updated ' . $entity, 'brand_id' => $brand], 1);
            $controller->$method($id, $request, $this->db, new AccessControl());
            $this->assertLog($entity . '_update', $entity, $id, 1);
        }
        $this->db->saveClient(['name' => 'Vehicle owner']);
        $payload = ['client_id' => (int) $this->db->pdo()->query('SELECT MAX(id) FROM clients')->fetchColumn(), 'plate' => 'AUDIT-55', 'brand_id' => $brand, 'model_id' => $model];
        [$controller, $request] = $this->controller('/vehicles', 'POST', $payload, 1);
        $controller->vehicles($request, $this->db, new AccessControl());
        $vehicle = (int) $this->db->vehicleByPlate('AUDIT-55')['id'];
        $this->assertLog('vehicle_create', 'vehicle', $vehicle, 1);
        $payload['plate'] = 'AUDIT-66';
        [$controller, $request] = $this->controller('/vehicles/' . $vehicle . '/edit', 'POST', $payload, 1);
        $controller->vehicleEdit($vehicle, $request, $this->db, new AccessControl());
        $this->assertLog('vehicle_update', 'vehicle', $vehicle, 1);
        $userPayload = ['name' => 'Audit user', 'email' => 'AUDIT@EXAMPLE.COM', 'role' => 'manager', 'active' => 1, 'password' => 'AuditPassword123!', 'password_confirm' => 'AuditPassword123!'];
        [$controller, $request] = $this->controller('/users/new', 'POST', $userPayload, 1);
        $controller->userNew($request, $this->db);
        $target = (int) $this->db->userByEmail('audit@example.com')['id'];
        $this->assertLog('user_create', 'user', $target, 1);
        $userPayload['name'] = 'Renamed audit user';
        [$controller, $request] = $this->controller('/users/' . $target . '/edit', 'POST', $userPayload, 1);
        $request->getSession()->set('csrf_tokens', ['user_edit_' . $target => 'valid-token']);
        $controller->userEdit($target, $request, $this->db);
        $this->assertLog('user_update', 'user', $target, 1);
        [$controller, $request] = $this->controller('/users/' . $target . '/toggle', 'POST', [], 1);
        $controller->userToggle($target, $request, $this->db);
        $this->assertLog('user_toggle', 'user', $target, 1);
        self::assertSame(0, (int) $this->db->userById($target)['active']);
    }

    private function operationPayload(int $productId): array
    {
        $this->db->saveClient(['name' => 'Audit operation client']);
        $client = (int) $this->db->pdo()->query('SELECT MAX(id) FROM clients')->fetchColumn();
        $this->db->saveVehicleBrand('AUDIT');
        $brand = (int) $this->db->vehicleBrandByName('AUDIT')['id'];
        $this->db->saveVehicleModel($brand, 'MODEL');
        $model = (int) $this->db->vehicleModelByName($brand, 'MODEL')['id'];
        $this->db->saveVehicle(['client_id' => $client, 'plate' => 'AUDIT-PLATE', 'brand_id' => $brand, 'model_id' => $model]);
        $vehicle = (int) $this->db->pdo()->query('SELECT MAX(id) FROM vehicles')->fetchColumn();
        return ['client_id' => $client, 'vehicle_id' => $vehicle, 'product_1' => $productId, 'product_qty_1' => 1];
    }

    private function assertLog(string $type, string $entity, int $id, int $user): void
    {
        $rows = (new ActivityLogger($this->db))->search(['action_type' => $type])['rows'];
        self::assertNotEmpty($rows, 'Expected audit event: ' . $type);
        $row = $rows[0];
        self::assertSame($entity, $row['entity_type']);
        self::assertSame($id, (int) $row['entity_id']);
        self::assertSame($user, (int) $row['user_id']);
        self::assertSame($this->db->userById($user)['name'], $row['user_name']);
        self::assertNotEmpty($row['description']);
    }

    private function countLogs(): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
    }

    private function controller(string $uri, string $method, array $payload, int $user, string $locale = 'fr'): array
    {
        $request = Request::create($uri, $method, $payload);
        $request->setLocale($locale);
        $session = new Session(new MockArraySessionStorage());
        if ($user > 0) { $session->set('user', ['id' => $user]); }
        $tokens = array_fill_keys(['operation_form', 'operation_action', 'stock_adjust', 'stock_movement', 'record_state', 'import_clients', 'user_new', 'user_edit_2', 'users_toggle'], 'valid-token');
        $session->set('csrf_tokens', $tokens);
        $request->setSession($session);
        if ($method === 'POST') { $request->request->set('_token', 'valid-token'); }
        $controller = new class extends DashboardController {
            public array $context = [];
            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->context = $parameters;
                return parent::render($view, $parameters, $response);
            }
        };
        $controller->setContainer($this->container($request));
        return [$controller, $request];
    }

    private function container(Request $request): Container
    {
        $container = new Container();
        $stack = new RequestStack();
        $stack->push($request);
        $container->set('request_stack', $stack);
        $router = new class implements UrlGeneratorInterface {
            private RequestContext $context;
            public function __construct() { $this->context = new RequestContext(); }
            public function setContext(RequestContext $context): void { $this->context = $context; }
            public function getContext(): RequestContext { return $this->context; }
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/' . $name . (isset($parameters['id']) ? '/' . $parameters['id'] : '');
            }
        };
        $container->set('router', $router);
        $translator = new Translator($request->getLocale());
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['fr', 'ar'] as $locale) { $translator->addResource('yaml', __DIR__ . '/../translations/messages.' . $locale . '.yaml', $locale); }
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
        $request->attributes->set('_route', $request->getPathInfo() === '/audit' ? 'app_audit' : 'app_clients');
        $container->set('twig', $twig);
        return $container;
    }
}
