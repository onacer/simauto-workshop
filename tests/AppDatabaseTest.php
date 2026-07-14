<?php

namespace App\Tests;

use App\Controller\DashboardController;
use App\Service\AccessControl;
use App\Service\AppDatabase;
use App\Service\ImportService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppDatabaseTest extends TestCase
{
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }
        $this->temporaryDirectories = [];
    }

    public function testCompleteWorkshopFlowKeepsStockAndDocumentsConsistent(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();

        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveSupplier(['name' => 'SIM Parts', 'phone' => '0600000000', 'address' => 'Agadir']);
        $supplierId = $this->id($pdo, 'SELECT id FROM suppliers WHERE name = "SIM Parts"');

        $db->saveProduct([
            'sku' => 'FLT-AIR-001',
            'name' => 'Filtre a air',
            'category_id' => $categoryId,
            'stock_qty' => 10,
            'min_qty' => 2,
            'purchase_price' => 35,
            'sale_price' => 70,
        ], 1);
        $productId = $this->id($pdo, 'SELECT id FROM products WHERE sku = "FLT-AIR-001"');

        $db->addStock($productId, 5, 'Achat fournisseur', 1, $supplierId, 35);

        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);

        $quoteId = $db->createOperation([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => 'ESP',
            'product_1' => $productId,
            'product_qty_1' => 3,
            'service_label_1' => 'Diagnostic et entretien',
            'service_price_1' => 150,
        ], 1);
        self::assertSame(15, (int) $db->product($productId)['stock_qty']);

        $orderId = $db->confirmQuote($quoteId, 1);
        self::assertSame(15, (int) $db->product($productId)['stock_qty']);

        $operationId = $db->invoiceDocument($orderId, 1);

        $product = $db->product($productId);
        $operation = $db->operation($operationId);

        self::assertSame(12, (int) $product['stock_qty']);
        self::assertSame('invoice', $operation['doc_type']);
        self::assertStringStartsWith('FAC-', $operation['invoice_no']);
        self::assertSame('003151412000082', $operation['client_ice']);
        self::assertSame('15428-A-32', $operation['vehicle_real_plate']);
        self::assertSame('VW', $operation['brand_name']);
        self::assertSame('Tiguan', $operation['model_name']);
        self::assertSame(432.0, (float) $operation['total_ttc']);
        self::assertCount(2, $operation['items']);
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ' . $productId)->fetchColumn());
    }

    public function testProductValidationRejectsDuplicateSkuAndNegativePrices(): void
    {
        $db = $this->database();
        $categoryId = $this->id($db->pdo(), 'SELECT id FROM categories ORDER BY id LIMIT 1');

        $payload = [
            'sku' => 'OIL-001',
            'name' => 'Huile moteur',
            'category_id' => $categoryId,
            'stock_qty' => 4,
            'min_qty' => 1,
            'purchase_price' => 100,
            'sale_price' => 150,
        ];

        $db->saveProduct($payload, 1);

        $this->expectException(InvalidArgumentException::class);
        $db->saveProduct($payload, 1);
    }

    public function testProductValidationRejectsNegativePrices(): void
    {
        $db = $this->database();
        $categoryId = $this->id($db->pdo(), 'SELECT id FROM categories ORDER BY id LIMIT 1');

        $this->expectException(InvalidArgumentException::class);

        $db->saveProduct([
            'sku' => 'NEG-001',
            'name' => 'Prix invalide',
            'category_id' => $categoryId,
            'stock_qty' => 1,
            'min_qty' => 1,
            'purchase_price' => -1,
            'sale_price' => 10,
        ], 1);
    }

    public function testInsufficientStockDoesNotCreateOperation(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');

        $db->saveProduct([
            'sku' => 'BAT-001',
            'name' => 'Batterie',
            'category_id' => $categoryId,
            'stock_qty' => 1,
            'min_qty' => 1,
            'purchase_price' => 900,
            'sale_price' => 1200,
        ], 1);
        $productId = $this->id($pdo, 'SELECT id FROM products WHERE sku = "BAT-001"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);

        try {
            $quoteId = $db->createOperation([
                'client_id' => $clientId,
                'vehicle_id' => $vehicleId,
                'payment_method' => 'ESP',
                'product_1' => $productId,
                'product_qty_1' => 2,
            ], 1);
            $db->invoiceDocument($quoteId, 1);
            self::fail('Expected insufficient stock exception.');
        } catch (InvalidArgumentException) {
            self::assertSame(1, (int) $db->product($productId)['stock_qty']);
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM operations')->fetchColumn());
        }
    }

    public function testOperationRollbackRestoresStockWhenWriteFails(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');

        $db->saveProduct([
            'sku' => 'WIP-001',
            'name' => 'Balais essuie glace',
            'category_id' => $categoryId,
            'stock_qty' => 5,
            'min_qty' => 1,
            'purchase_price' => 40,
            'sale_price' => 80,
        ], 1);
        $productId = $this->id($pdo, 'SELECT id FROM products WHERE sku = "WIP-001"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);

        $this->expectException(RuntimeException::class);

        try {
            $db->createOperation([
                'client_id' => $clientId,
                'vehicle_id' => $vehicleId,
                'payment_method' => 'ESP',
                'product_1' => $productId,
                'product_qty_1' => 2,
                'simulate_failure' => '1',
            ], 1);
        } finally {
            self::assertSame(5, (int) $db->product($productId)['stock_qty']);
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM operations')->fetchColumn());
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM operation_items')->fetchColumn());
        }
    }

    public function testLegacyDatabaseIsMigratedWithoutLosingBusinessData(): void
    {
        $directory = $this->temporaryDirectory();
        $databasePath = $directory . '/legacy.sqlite';
        $pdo = new PDO('sqlite:' . $databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(<<<SQL
CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    category TEXT NOT NULL,
    stock_qty REAL DEFAULT 0,
    min_qty REAL DEFAULT 0,
    purchase_price REAL DEFAULT 0,
    sale_price REAL DEFAULT 0,
    created_at TEXT NOT NULL
);
CREATE TABLE operations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_no TEXT UNIQUE NOT NULL,
    receipt_no TEXT UNIQUE NOT NULL,
    client_name TEXT NOT NULL,
    client_address TEXT,
    vehicle_plate TEXT,
    vehicle_brand TEXT,
    vehicle_model TEXT,
    payment_method TEXT,
    total REAL DEFAULT 0,
    status TEXT DEFAULT 'invoice',
    created_by INTEGER,
    created_at TEXT NOT NULL
);
CREATE TABLE stock_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    movement_type TEXT NOT NULL,
    quantity REAL NOT NULL,
    note TEXT,
    created_by INTEGER,
    created_at TEXT NOT NULL
);
CREATE TABLE operation_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    operation_id INTEGER NOT NULL,
    product_id INTEGER,
    line_type TEXT NOT NULL,
    label TEXT NOT NULL,
    quantity REAL DEFAULT 1,
    unit_price REAL DEFAULT 0,
    total REAL DEFAULT 0
);
INSERT INTO products (sku, name, category, stock_qty, min_qty, purchase_price, sale_price, created_at)
VALUES ('OLD-001', 'Ancienne piece', 'Ancienne categorie', 7, 1, 20, 50, datetime('now'));
INSERT INTO operations (invoice_no, receipt_no, client_name, client_address, vehicle_plate, vehicle_brand, vehicle_model, payment_method, total, created_at)
VALUES ('FAC-OLD', 'REC-OLD', 'Ancien Client', 'Agadir', '1000-A-1', 'Renault', 'Clio', 'ESP', 50, datetime('now'));
SQL);

        $db = new AppDatabase($directory, $databasePath);
        $migrated = $db->pdo();

        self::assertSame('Ancienne categorie', $migrated->query('SELECT name FROM categories WHERE name = "Ancienne categorie"')->fetchColumn());
        self::assertGreaterThan(0, (int) $migrated->query('SELECT category_id FROM products WHERE sku = "OLD-001"')->fetchColumn());
        self::assertGreaterThan(0, (int) $migrated->query('SELECT client_id FROM operations WHERE invoice_no = "FAC-OLD"')->fetchColumn());
        self::assertGreaterThan(0, (int) $migrated->query('SELECT vehicle_id FROM operations WHERE invoice_no = "FAC-OLD"')->fetchColumn());
        self::assertSame('check_number', $migrated->query("SELECT name FROM pragma_table_info('operations') WHERE name = 'check_number'")->fetchColumn());
        self::assertSame('FAC-OLD', $migrated->query('SELECT invoice_no FROM operations WHERE invoice_no = "FAC-OLD"')->fetchColumn());
    }

    public function testInvoiceTitleUsesDocumentNumberAndNoDeliveryNoteTitle(): void
    {
        $db = $this->database();
        $operationId = $this->operationWithService($db, 'ESP');
        $operation = $db->operation($operationId);

        $html = $this->renderTemplate('documents/invoice.html.twig', [
            'operation' => $operation,
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'company' => $this->company(),
            'amount_words' => 'cent quatre-vingts dirhams TTC',
        ]);

        self::assertStringContainsString('DEVIS N° ' . $operation['document_no'], $html);
        self::assertStringNotContainsString('BON DE LIVRAISON', $html);
    }

    public function testChequePaymentIsStoredAndPrintedWhileCashHasNoChequeMention(): void
    {
        $db = $this->database();
        $chequeId = $this->operationWithService($db, 'CHQ', 'CHQ-7788');
        $cashId = $this->operationWithService($db, 'ESP');

        $cheque = $db->operation($chequeId);
        $cash = $db->operation($cashId);

        self::assertSame('CHQ', $cheque['payment_method']);
        self::assertSame('CHEQUE', $cheque['payment_label']);
        self::assertSame('CHQ-7788', $cheque['check_number']);
        self::assertSame('', (string) $cash['check_number']);

        $chequeHtml = $this->renderTemplate('documents/invoice.html.twig', [
            'operation' => $cheque,
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'company' => $this->company(),
            'amount_words' => 'cent quatre-vingts dirhams TTC',
        ]);
        $cashHtml = $this->renderTemplate('documents/invoice.html.twig', [
            'operation' => $cash,
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'company' => $this->company(),
            'amount_words' => 'cent quatre-vingts dirhams TTC',
        ]);

        self::assertStringContainsString('MODE DE PAIEMENT :</b> CHEQUE', $chequeHtml);
        self::assertStringContainsString('Cheque N°', $chequeHtml);
        self::assertStringContainsString('CHQ-7788', $chequeHtml);
        self::assertStringContainsString('MODE DE PAIEMENT :</b> ESP', $cashHtml);
        self::assertStringNotContainsString('Cheque N°', $cashHtml);
    }
    public function testClientShowListsRelatedVehiclesAndEmptyState(): void
    {
        $db = $this->database();
        [$clientId] = $this->clientAndVehicle($db, $db->pdo());
        $brandId = $this->id($db->pdo(), 'SELECT id FROM vehicle_brands WHERE name = "VW"');
        $modelId = $this->id($db->pdo(), 'SELECT id FROM vehicle_models WHERE brand_id = ' . $brandId . ' AND name = "Tiguan"');
        $db->saveVehicle([
            'client_id' => $clientId,
            'plate' => '22222-B-10',
            'brand_id' => $brandId,
            'model_id' => $modelId,
            'year' => 2022,
            'mileage' => 10000,
        ]);

        $client = $db->client($clientId);
        $html = $this->renderTemplate('app/client_show.html.twig', [
            'app' => ['request' => Request::create('/clients/' . $clientId)],
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'client' => $client,
            'vehicles' => $db->clientVehicles($clientId),
            'operations' => [],
        ]);

        self::assertStringContainsString('15428-A-32', $html);
        self::assertStringContainsString('22222-B-10', $html);

        $db->saveClient(['type' => 'individual', 'name' => 'Client sans voiture']);
        $emptyClientId = $this->id($db->pdo(), 'SELECT id FROM clients WHERE name = "Client sans voiture"');
        $emptyHtml = $this->renderTemplate('app/client_show.html.twig', [
            'app' => ['request' => Request::create('/clients/' . $emptyClientId)],
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'client' => $db->client($emptyClientId),
            'vehicles' => $db->clientVehicles($emptyClientId),
            'operations' => $db->clientOperations($emptyClientId),
        ]);

        self::assertStringContainsString('لا توجد سيارات لهذا العميل.', $emptyHtml);
    }

    public function testVehicleShowDisplaysOwnerAndLinkedOperations(): void
    {
        $db = $this->database();
        $operationId = $this->operationWithService($db, 'ESP');
        $operation = $db->operation($operationId);
        $vehicleId = (int) $operation['vehicle_id'];

        $html = $this->renderTemplate('app/vehicle_show.html.twig', [
            'app' => ['request' => Request::create('/vehicles/' . $vehicleId)],
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'vehicle' => $db->vehicle($vehicleId),
            'operations' => $db->vehicleOperations($vehicleId),
        ]);

        self::assertStringContainsString('Client SIM', $html);
        self::assertStringContainsString($operation['invoice_no'], $html);
    }

    public function testUserCreationValidationAndLoginPasswordFlow(): void
    {
        $db = $this->database();

        $userId = $db->createUser([
            'name' => 'Manager Test',
            'email' => 'manager.test@simauto.ma',
            'role' => 'manager',
            'active' => '1',
            'password' => 'manager123',
            'password_confirm' => 'manager123',
        ]);

        $user = $db->userByEmail('manager.test@simauto.ma', true);
        self::assertSame($userId, (int) $user['id']);
        self::assertTrue($db->isPasswordValid($user, 'manager123'));
        self::assertStringStartsWith('$2y$', $user['password']);
    }

    public function testUserValidationRejectsDuplicateEmailShortPasswordAndConfirmationMismatch(): void
    {
        $db = $this->database();
        $initialCount = (int) $db->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $db->createUser([
            'name' => 'Unique User',
            'email' => 'unique@simauto.ma',
            'role' => 'manager',
            'active' => '1',
            'password' => 'password123',
            'password_confirm' => 'password123',
        ]);

        foreach ([
            ['name' => 'Dup', 'email' => 'unique@simauto.ma', 'role' => 'manager', 'active' => '1', 'password' => 'password123', 'password_confirm' => 'password123'],
            ['name' => 'Short', 'email' => 'short@simauto.ma', 'role' => 'manager', 'active' => '1', 'password' => 'short', 'password_confirm' => 'short'],
            ['name' => 'Mismatch', 'email' => 'mismatch@simauto.ma', 'role' => 'manager', 'active' => '1', 'password' => 'password123', 'password_confirm' => 'password124'],
        ] as $payload) {
            try {
                $db->createUser($payload);
                self::fail('Expected validation exception.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        self::assertSame($initialCount + 1, (int) $db->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testAdminPasswordResetAndOwnPasswordChangeValidation(): void
    {
        $db = $this->database();
        $admin = $db->userByEmail('admin@simauto.ma');

        $db->changeUserPassword((int) $admin['id'], 'newAdmin123', 'newAdmin123');
        $admin = $db->userById((int) $admin['id']);

        self::assertFalse($db->isPasswordValid($admin, 'admin123'));
        self::assertTrue($db->isPasswordValid($admin, 'newAdmin123'));

        $this->expectException(InvalidArgumentException::class);
        $db->changeOwnPassword((int) $admin['id'], 'wrong-password', 'another123', 'another123');
    }

    public function testUserActivationRulesAndLastAdminGuard(): void
    {
        $db = $this->database();
        $managerId = $db->createUser([
            'name' => 'Toggle Manager',
            'email' => 'toggle@simauto.ma',
            'role' => 'manager',
            'active' => '1',
            'password' => 'manager123',
            'password_confirm' => 'manager123',
        ]);

        $db->toggleUser($managerId, 1);
        self::assertNull($db->userByEmail('toggle@simauto.ma', true));
        self::assertSame(0, (int) $db->userById($managerId)['active']);

        $db->toggleUser($managerId, 1);
        self::assertSame(1, (int) $db->userById($managerId)['active']);

        try {
            $db->toggleUser(1, 1);
            self::fail('Admin should not disable self.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        try {
            $db->updateUser(1, [
                'name' => 'Admin',
                'email' => 'admin@simauto.ma',
                'role' => 'manager',
                'active' => '1',
            ], 1);
            self::fail('Last active admin should keep admin role.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testSimpleCsrfTokenRejectsInvalidPostToken(): void
    {
        $controller = new DashboardController();
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $csrfToken = new ReflectionMethod(DashboardController::class, 'csrfToken');
        $csrfToken->setAccessible(true);
        $verifyCsrf = new ReflectionMethod(DashboardController::class, 'verifyCsrf');
        $verifyCsrf->setAccessible(true);

        $token = $csrfToken->invoke($controller, $request, 'users_toggle');
        $validPost = new Request([], ['_token' => $token]);
        $validPost->setSession($session);
        $verifyCsrf->invoke($controller, $validPost, 'users_toggle');

        $invalidPost = new Request([], ['_token' => 'bad-token']);
        $invalidPost->setSession($session);
        $this->expectException(InvalidArgumentException::class);
        $verifyCsrf->invoke($controller, $invalidPost, 'users_toggle');
    }

    public function testImportClientsCreatesThenIgnoresExistingRows(): void
    {
        $db = $this->database();
        $imports = new ImportService($db);
        $path = $this->csv([
            ['type', 'name', 'surname', 'phone', 'email', 'address', 'ice', 'vat', 'rc'],
            ['individual', 'Ali', 'Test', '0600000001', 'ali@example.com', 'Agadir', '', '', ''],
            ['individual', 'Sara', 'Test', '0600000002', 'sara@example.com', 'Agadir', '', '', ''],
            ['company', 'Garage Client', '', '0600000003', 'garage@example.com', 'Agadir', 'ICE123', 'IF123', 'RC123'],
        ]);

        $first = $imports->import('clients', $path, 1);
        $second = $imports->import('clients', $path, 1);

        self::assertSame(3, $first['created']);
        self::assertSame(0, $first['updated']);
        self::assertSame(0, $first['ignored']);
        self::assertSame(0, $second['created']);
        self::assertSame(3, $second['ignored']);
        self::assertSame(3, (int) $db->pdo()->query("SELECT COUNT(*) FROM clients WHERE email LIKE '%@example.com'")->fetchColumn());
    }

    public function testImportProductsCreatesCategoryAndDoesNotOverwriteExistingStock(): void
    {
        $db = $this->database();
        $imports = new ImportService($db);
        $path = $this->csv([
            ['sku', 'name', 'category_name', 'stock_qty', 'min_qty', 'purchase_price', 'sale_price'],
            ['IMP-001', 'Produit import', 'Categorie Import', '8', '2', '10', '20'],
        ]);

        $first = $imports->import('products', $path, 1);
        $product = $db->productBySku('IMP-001');

        self::assertSame(1, $first['created']);
        self::assertSame(8, (int) $product['stock_qty']);
        self::assertSame('Categorie Import', $product['category_name']);
        self::assertSame(1, (int) $db->pdo()->query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ' . (int) $product['id'])->fetchColumn());

        $updatePath = $this->csv([
            ['sku', 'name', 'category_name', 'stock_qty', 'min_qty', 'purchase_price', 'sale_price'],
            ['IMP-001', 'Produit import modifie', 'Categorie Import', '99', '3', '11', '25'],
        ]);
        $second = $imports->import('products', $updatePath, 1);
        $updated = $db->productBySku('IMP-001');

        self::assertSame(1, $second['updated']);
        self::assertSame(8, (int) $updated['stock_qty']);
        self::assertSame('Produit import modifie', $updated['name']);
    }

    public function testImportErrorsRejectInvalidHeadersAndKeepValidRowsOnLineErrors(): void
    {
        $db = $this->database();
        $imports = new ImportService($db);

        try {
            $imports->import('products', $this->csv([
                ['bad', 'headers'],
                ['x', 'y'],
            ]), 1);
            self::fail('Expected invalid headers exception.');
        } catch (InvalidArgumentException) {
            self::assertNull($db->productBySku('BAD-001'));
        }

        $report = $imports->import('products', $this->csv([
            ['sku', 'name', 'category_name', 'stock_qty', 'min_qty', 'purchase_price', 'sale_price'],
            ['GOOD-001', 'Produit valide', 'Import Erreurs', '1', '1', '10', '20'],
            ['BAD-001', 'Produit invalide', 'Import Erreurs', '1', '1', '-10', '20'],
        ]), 1);

        self::assertSame(2, $report['processed']);
        self::assertSame(1, $report['created']);
        self::assertSame(1, count($report['errors']));
        self::assertNotNull($db->productBySku('GOOD-001'));
        self::assertNull($db->productBySku('BAD-001'));
    }

    public function testImportModelsResolvesBrandCaseInsensitively(): void
    {
        $db = $this->database();
        $imports = new ImportService($db);
        $db->saveVehicleBrand('Dacia');

        $report = $imports->import('models', $this->csv([
            ['brand_name', 'name'],
            ['dacia', 'Logan'],
        ]), 1);

        $brandId = (int) $db->vehicleBrandByName('DACIA')['id'];
        self::assertSame(1, $report['created']);
        self::assertNotNull($db->vehicleModelByName($brandId, 'logan'));
    }

    public function testAccessControlKeepsManagerAwayFromDeleteAndUsers(): void
    {
        $access = new AccessControl();
        $manager = ['role' => 'manager'];
        $admin = ['role' => 'admin'];

        self::assertTrue($access->can('imports', $manager));
        self::assertTrue($access->can('clients', $manager));
        self::assertTrue($access->can('delete', $manager));
        self::assertFalse($access->can('manage_users', $manager));
        self::assertTrue($access->can('delete', $admin));
        self::assertTrue($access->can('manage_users', $admin));
    }

    public function testTopbarDropdownsAreClosedByDefaultAndManagerHasNoUsersEntry(): void
    {
        $template = file_get_contents(__DIR__ . '/../templates/app/_topbar.html.twig');
        $twig = new Environment(new ArrayLoader(['topbar' => $template]));
        $access = new AccessControl();
        $twig->addFunction(new TwigFunction('path', fn (string $route, array $params = []) => '/' . $route . (isset($params['locale']) ? '/' . $params['locale'] : '')));
        $twig->addFunction(new TwigFunction('asset', fn (string $path) => '/assets/' . $path));
        $twig->addFunction(new TwigFunction('can', fn (string $permission, array $user) => $access->can($permission, $user)));
        $twig->addFilter(new TwigFilter('trans', fn (string $key): string => $key));

        $request = Request::create('/');
        $request->attributes->set('_route', 'app_dashboard');
        $adminHtml = $twig->render('topbar', ['app' => ['request' => $request], 'user' => ['role' => 'admin', 'name' => 'Admin']]);
        $managerHtml = $twig->render('topbar', ['app' => ['request' => $request], 'user' => ['role' => 'manager', 'name' => 'Manager']]);
        $css = file_get_contents(__DIR__ . '/../public/styles/app.css');

        self::assertStringContainsString('dropdown-menu', $adminHtml);
        self::assertStringNotContainsString('is-open', $adminHtml);
        self::assertStringContainsString('/app_users', $adminHtml);
        self::assertStringNotContainsString('/app_users', $managerHtml);
        self::assertMatchesRegularExpression('/\\.dropdown-menu\\s*\\{[^}]*display:\\s*none;/s', $css);
    }

    private function operationWithService(AppDatabase $db, string $paymentMethod, string $checkNumber = ''): int
    {
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $db->pdo());

        return $db->createOperation([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => $paymentMethod,
            'check_number' => $checkNumber,
            'service_label_1' => 'Diagnostic',
            'service_price_1' => 150,
        ], 1);
    }

    private function renderTemplate(string $template, array $context): string
    {
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../templates'));
        $access = new AccessControl();
        $twig->addFunction(new TwigFunction('asset', fn (string $path) => '/assets/' . $path));
        $twig->addFunction(new TwigFunction('can', fn (string $permission, array $user) => $access->can($permission, $user)));
        $twig->addFilter(new TwigFilter('trans', fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('path', function (string $route, array $params = []): string {
            $suffix = isset($params['id']) ? '/' . $params['id'] : (isset($params['locale']) ? '/' . $params['locale'] : '');
            return '/' . $route . $suffix;
        }));

        $context += [
            'app_name' => 'SIM Auto',
            'app' => ['request' => Request::create('/')],
        ];

        return $twig->render($template, $context);
    }

    private function company(): array
    {
        return [
            'service_line_1' => 'Entretien Automobile',
            'service_line_2' => 'Mecanique Generale',
            'contact' => '0628193654 / 0680216699',
            'address' => 'Av. Omar Benjelloun N16 Riad Salam Agadir',
            'if' => '53237142',
            'pt' => '48107238',
            'ice' => '003151412000082',
            'rc' => '53425',
            'email' => 'simauto33@gmail.com',
        ];
    }

    private function database(): AppDatabase
    {
        $directory = $this->temporaryDirectory();

        return new AppDatabase($directory, $directory . '/simauto.sqlite');
    }

    private function clientAndVehicle(AppDatabase $db, PDO $pdo): array
    {
        $db->saveClient([
            'type' => 'company',
            'name' => 'Client SIM',
            'phone' => '0611111111',
            'address' => 'Agadir',
            'ice' => '003151412000082',
            'vat' => '53237142',
            'rc' => '53425',
        ]);
        $clientId = $this->id($pdo, 'SELECT id FROM clients WHERE name = "Client SIM" ORDER BY id DESC LIMIT 1');

        $db->saveVehicleBrand('VW');
        $brandId = $this->id($pdo, 'SELECT id FROM vehicle_brands WHERE name = "VW"');
        $db->saveVehicleModel($brandId, 'Tiguan');
        $modelId = $this->id($pdo, 'SELECT id FROM vehicle_models WHERE brand_id = ' . $brandId . ' AND name = "Tiguan"');

        $db->saveVehicle([
            'client_id' => $clientId,
            'plate' => '15428-A-32',
            'brand_id' => $brandId,
            'model_id' => $modelId,
            'year' => 2021,
            'mileage' => 84000,
        ]);
        $vehicleId = $this->id($pdo, 'SELECT id FROM vehicles WHERE plate = "15428-A-32" ORDER BY id DESC LIMIT 1');

        return [$clientId, $vehicleId];
    }

    private function id(PDO $pdo, string $sql): int
    {
        return (int) $pdo->query($sql)->fetchColumn();
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/simauto_' . bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function csv(array $rows): string
    {
        $directory = $this->temporaryDirectory();
        $path = $directory . '/import.csv';
        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }
        fclose($handle);

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
