<?php

namespace App\Tests;

use App\Controller\DashboardController;
use App\Controller\ImportController;
use App\Controller\ReportController;
use App\Kernel;
use App\Command\StockCheckCommand;
use App\Service\AccessControl;
use App\Service\AppDatabase;
use App\Service\DesktopPaths;
use App\Service\ImportService;
use App\Service\PricingCalculator;
use App\Service\LineMarginCalculator;
use App\Twig\MoneyExtension;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppDatabaseTest extends TestCase
{
    public function testDivisorPricingAndSharedLineMarginCalculator(): void
    {
        self::assertSame(153.85, PricingCalculator::priceFromMargin(100, 35));
        self::assertSame(181.82, PricingCalculator::priceFromMargin(100, 45));
        self::assertSame(222.22, PricingCalculator::priceFromMargin(100, 55));
        self::assertNull(PricingCalculator::supportedMargin('manual'));

        $product = LineMarginCalculator::decorate(['product_id' => 1, 'line_type' => 'product', 'product_type' => 'stockable', 'quantity' => 2, 'purchase_price' => 60, 'total_ht' => 150], 20);
        $service = LineMarginCalculator::decorate(['product_id' => null, 'line_type' => 'service', 'quantity' => 1, 'total_ht' => 50], 20);
        self::assertSame(50.0, $product['margin']);
        self::assertSame(50.0, $service['margin']);
        self::assertSame(100.0, $product['margin'] + $service['margin']);
    }

    public function testServiceMarginUsesEnteredTotalWithQuantityAndDiscount(): void
    {
        $one = LineMarginCalculator::decorate(['product_id' => 1, 'line_type' => 'service', 'product_type' => 'service', 'quantity' => 1, 'total_ht' => 83.33, 'total' => 100], 20);
        $two = LineMarginCalculator::decorate(['product_id' => 1, 'line_type' => 'service', 'product_type' => 'service', 'quantity' => 2, 'total_ht' => 166.67, 'total' => 200], 20);
        $discounted = LineMarginCalculator::decorate(['product_id' => null, 'line_type' => 'service', 'quantity' => 1, 'total_ht' => 75, 'total' => 90], 20);
        $product = LineMarginCalculator::decorate(['product_id' => 2, 'line_type' => 'product', 'product_type' => 'stockable', 'quantity' => 1, 'purchase_price' => 60, 'total_ht' => 100, 'total' => 120], 20);

        self::assertSame(100.0, $one['margin']);
        self::assertSame(200.0, $two['margin']);
        self::assertSame(90.0, $discounted['margin']);
        self::assertSame(50.0, $product['margin']);
        self::assertSame(150.0, $one['margin'] + $product['margin']);
        self::assertStringContainsString('line.dataset.lineType === "service"', file_get_contents(__DIR__ . '/../public/scripts/app.js'));
    }

    public function testServiceOnlyFormIgnoresBlankProductPrototypeAndReusesAutoCreatedService(): void
    {
        $db = $this->database();
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $db->pdo());
        $payload = [
            'client_id' => $clientId, 'vehicle_id' => $vehicleId, 'payment_method' => 'ESP',
            'line_type' => ['product', 'service'], 'line_product_id' => ['', ''],
            'line_label' => ['', 'Diagnostic Libre'], 'line_quantity' => [1, 1],
            'line_margin_mode' => ['manual', 'manual'], 'line_unit_price' => [0, 50],
            'line_discount' => [0, 0],
        ];

        $firstId = $db->createOperation($payload, 1);
        $first = $db->operation($firstId);
        self::assertCount(1, $first['items']);
        self::assertSame('service', $first['items'][0]['line_type']);
        self::assertGreaterThan(0, (int) $first['items'][0]['product_id']);
        self::assertSame(0, (int) $db->pdo()->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'out'")->fetchColumn());

        $payload['line_label'][1] = '  diagnostic   libre ';
        $db->createOperation($payload, 1);
        self::assertSame(1, (int) $db->pdo()->query("SELECT COUNT(*) FROM products WHERE product_type = 'service' AND lower(name) LIKE '%diagnostic%libre%'")->fetchColumn());
    }

    public function testMultipleStockableAndServiceRowsKeepTheirSeparateRules(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        foreach ([['MULTI-P-1', 10, 20], ['MULTI-P-2', 20, 40]] as [$sku, $purchase, $sale]) {
            $db->saveProduct(['sku' => $sku, 'name' => $sku, 'category_id' => $categoryId, 'stock_qty' => 10, 'min_qty' => 1, 'purchase_price' => $purchase, 'sale_price' => $sale], 1);
        }
        $db->saveProduct(['sku' => 'MULTI-S-1', 'name' => 'Service catalogue multi', 'category_id' => $categoryId, 'stock_qty' => 0, 'min_qty' => 0, 'purchase_price' => 0, 'sale_price' => 0, 'product_type' => 'service'], 1);
        $p1 = $this->id($pdo, 'SELECT id FROM products WHERE sku = "MULTI-P-1"');
        $p2 = $this->id($pdo, 'SELECT id FROM products WHERE sku = "MULTI-P-2"');
        $service = $this->id($pdo, 'SELECT id FROM products WHERE sku = "MULTI-S-1"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);

        $quoteId = $db->createOperation([
            'client_id' => $clientId, 'vehicle_id' => $vehicleId, 'payment_method' => 'ESP',
            'line_type' => ['product', 'product', 'service', 'service'],
            'line_product_id' => [$p1, $p2, $service, ''],
            'line_label' => ['', '', '', 'Service libre multi'],
            'line_quantity' => [2, 3, 2, 1],
            'line_margin_mode' => ['manual', 'manual', 'manual', 'manual'],
            'line_unit_price' => [20, 40, 50, 75],
            'line_discount' => [0, 0, 10, 0],
        ], 1);
        $quote = $db->operation($quoteId);
        self::assertCount(4, $quote['items']);
        self::assertSame(['product', 'product', 'service', 'service'], array_column($quote['items'], 'line_type'));
        self::assertSame(90.0, (float) $quote['items'][2]['margin']);
        self::assertSame(75.0, (float) $quote['items'][3]['margin']);

        $db->invoiceDocument($quoteId, 1);
        self::assertSame(8, (int) $db->product($p1)['stock_qty']);
        self::assertSame(7, (int) $db->product($p2)['stock_qty']);
        self::assertSame(0, (int) $db->product($service)['stock_qty']);
        self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'out' AND product_id IN ($p1, $p2, $service)")->fetchColumn());

        $js = file_get_contents(__DIR__ . '/../public/scripts/app.js');
        self::assertStringContainsString('if (input.type === "hidden") return;', $js);
        self::assertStringContainsString(
            'scripts/app.js\') }}?v=20260907-mixed-lines-1',
            file_get_contents(__DIR__ . '/../templates/base.html.twig'),
            'The fixed line-type serializer must not be hidden behind the previous browser cache key.'
        );
    }

    public function testMixedStockableAndFreeServiceLinesPassThroughControllerPost(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        foreach ([['POST-MIX-A', 10, 25], ['POST-MIX-B', 15, 35]] as [$sku, $purchase, $sale]) {
            $db->saveProduct(['sku' => $sku, 'name' => $sku, 'category_id' => $categoryId, 'stock_qty' => 10, 'min_qty' => 1, 'purchase_price' => $purchase, 'sale_price' => $sale], 1);
        }
        $productA = $this->id($pdo, 'SELECT id FROM products WHERE sku = "POST-MIX-A"');
        $productB = $this->id($pdo, 'SELECT id FROM products WHERE sku = "POST-MIX-B"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);
        $manager = ['id' => 2, 'name' => 'Manager', 'email' => 'manager@simauto.ma', 'role' => 'manager'];
        $request = $this->requestWithUser('/operations/new', 'POST', [
            'client_id' => $clientId, 'vehicle_id' => $vehicleId, 'payment_method' => 'ESP',
            'line_type' => ['product', 'product', 'service'],
            'line_product_id' => [$productA, $productB, ''],
            'line_label' => ['', '', 'Main oeuvre libre'],
            'line_quantity' => [1, 2, 1],
            'line_margin_mode' => ['manual', 'manual', 'manual'],
            'line_unit_price' => [25, 35, 100],
            'line_discount' => [0, 0, 0],
        ], $manager);
        $controller = new DashboardController();
        $csrf = new ReflectionMethod($controller, 'csrfToken');
        $csrf->setAccessible(true);
        $request->request->set('_token', $csrf->invoke($controller, $request, 'operation_form'));
        $controller->setContainer($this->controllerContainer($request));

        $response = $controller->newOperation($request, $db, new AccessControl());
        $operationId = (int) $pdo->query('SELECT id FROM operations ORDER BY id DESC LIMIT 1')->fetchColumn();
        $operation = $db->operation($operationId);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertCount(3, $operation['items']);
        self::assertSame(['product', 'product', 'service'], array_column($operation['items'], 'line_type'));
        self::assertSame(10, (int) $db->product($productA)['stock_qty']);
        self::assertSame(10, (int) $db->product($productB)['stock_qty']);
    }

    public function testInvalidNumericMarginIsRejectedByServerAndManualPriceIsPreserved(): void
    {
        self::assertSame(153.85, PricingCalculator::priceFromMargin(100, 35));
        self::assertSame(181.82, PricingCalculator::priceFromMargin(100, 45));
        self::assertSame(222.22, PricingCalculator::priceFromMargin(100, 55));
        $this->expectException(InvalidArgumentException::class);
        PricingCalculator::priceFromMargin(100, 100);
    }

    public function testThermalReceiptUsesAppLayoutAndIsolatesTicketOnlyForPrint(): void
    {
        $db = $this->database();
        $id = $this->operationWithService($db, 'ESP');
        $operation = $db->operation($id);
        $receipt = $this->renderTemplate('documents/receipt.html.twig', ['user' => ['role' => 'manager', 'name' => 'Manager'], 'operation' => $operation, 'company' => ['service_line_1' => 'Garage', 'address' => 'Agadir', 'contact' => '0600']]);
        self::assertStringContainsString('class="topbar"', $receipt);
        self::assertStringContainsString('ticket-screen-actions', $receipt);
        self::assertStringContainsString('@page { size: 80mm auto; margin: 0; }', $receipt);
        self::assertStringContainsString('body * { visibility: hidden; }', $receipt);
        self::assertStringContainsString('#ticket, #ticket * { visibility: visible; }', $receipt);
        self::assertStringContainsString('width: 80mm; margin: 0 auto; padding: 2mm;', $receipt);
        self::assertStringNotContainsString('min-height: 100vh', $receipt);
        self::assertStringNotContainsString('Marge', $receipt);
        self::assertStringContainsString('font-weight: 800', $receipt);
        self::assertStringContainsString('.receipt-ticket .ticket-line, .receipt-ticket .ticket-line * { color: #000; font-size: 13px; font-weight: 900;', $receipt);
        self::assertStringContainsString('color: #000', $receipt);
        self::assertStringContainsString('-webkit-print-color-adjust: exact', $receipt);
        self::assertStringContainsString('print-color-adjust: exact', $receipt);
        self::assertStringContainsString('class="ticket-footer"', $receipt);
        self::assertStringContainsString('.ticket-footer { page-break-inside: avoid; break-inside: avoid; }', $receipt);

        $history = $this->renderTemplate('app/operations_history.html.twig', [
            'user' => ['role' => 'manager', 'name' => 'Manager'], 'operations' => [$operation],
            'result_total' => 1, 'is_limited' => false, 'operation_action_token' => 'token',
            'filters' => ['q' => '', 'doc_type' => '', 'from' => '', 'to' => '', 'payment' => ''],
        ]);
        self::assertStringContainsString(($operation['client_real_name'] ?: $operation['client_name']) . ' — ' . $operation['brand_name'] . ' ' . $operation['model_name'], $history);
        self::assertStringNotContainsString($operation['vehicle_real_plate'], $history);
        self::assertStringContainsString('operations.margin', $history);
        self::assertStringContainsString('class="money" dir="ltr"', $history);
    }

    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }
        $this->temporaryDirectories = [];
    }

    public function testDatabaseUsesConfiguredDesktopDataDirectory(): void
    {
        $project = $this->temporaryDirectory();
        $base = $this->temporaryDirectory();
        $previous = getenv('SIMAUTO_DATA_DIR');

        try {
            putenv('SIMAUTO_DATA_DIR=' . $base);
            new AppDatabase($project);

            self::assertSame(str_replace('\\', '/', $base) . '/data', DesktopPaths::dataDir($project));
            self::assertFileExists($base . '/data/simauto.sqlite');
            self::assertFileDoesNotExist($project . '/data/simauto.sqlite');
        } finally {
            $previous === false ? putenv('SIMAUTO_DATA_DIR') : putenv('SIMAUTO_DATA_DIR=' . $previous);
        }
    }

    public function testDatabaseFallsBackToProjectDataDirectory(): void
    {
        $project = $this->temporaryDirectory();
        $previous = getenv('SIMAUTO_DATA_DIR');

        try {
            putenv('SIMAUTO_DATA_DIR');
            new AppDatabase($project);

            self::assertSame(str_replace('\\', '/', $project) . '/data', DesktopPaths::dataDir($project));
            self::assertFileExists($project . '/data/simauto.sqlite');
        } finally {
            $previous === false ? putenv('SIMAUTO_DATA_DIR') : putenv('SIMAUTO_DATA_DIR=' . $previous);
        }
    }

    public function testKernelUsesConfiguredDesktopVarDirectory(): void
    {
        $base = $this->temporaryDirectory();
        $previous = getenv('SIMAUTO_DATA_DIR');

        try {
            putenv('SIMAUTO_DATA_DIR=' . $base);
            $kernel = new Kernel('prod', false);

            self::assertStringStartsWith(str_replace('\\', '/', $base) . '/var/cache/prod', str_replace('\\', '/', $kernel->getCacheDir()));
            self::assertStringStartsWith(str_replace('\\', '/', $base) . '/var/log', str_replace('\\', '/', $kernel->getLogDir()));
        } finally {
            $previous === false ? putenv('SIMAUTO_DATA_DIR') : putenv('SIMAUTO_DATA_DIR=' . $previous);
        }
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
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = $productId AND movement_type = 'out'")->fetchColumn());

        $orderId = $db->confirmQuote($quoteId, 1);
        $order = $db->operation($orderId);
        self::assertSame(12, (int) $db->product($productId)['stock_qty']);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = $productId AND movement_type = 'out'")->fetchColumn());
        self::assertSame('Bon de commande ' . $order['order_no'], $pdo->query("SELECT note FROM stock_movements WHERE product_id = $productId AND movement_type = 'out'")->fetchColumn());

        $operationId = $db->invoiceDocument($orderId, 1);

        $product = $db->product($productId);
        $operation = $db->operation($operationId);
        $outMovement = $pdo->query("SELECT * FROM stock_movements WHERE product_id = $productId AND movement_type = 'out' ORDER BY id DESC LIMIT 1")->fetch();

        self::assertSame(12, (int) $product['stock_qty']);
        self::assertSame(3, (int) $outMovement['quantity']);
        self::assertSame('Bon de commande ' . $order['order_no'], $outMovement['note']);
        self::assertSame('invoice', $operation['doc_type']);
        self::assertStringStartsWith('INV/' . date('Ym') . '/', $operation['invoice_no']);
        self::assertSame('003151412000082', $operation['client_ice']);
        self::assertSame('15428-A-32', $operation['vehicle_real_plate']);
        self::assertSame('VW', $operation['brand_name']);
        self::assertSame('Tiguan', $operation['model_name']);
        self::assertSame(360.0, (float) $operation['total_ttc']);
        self::assertSame(300.0, (float) $operation['subtotal_ht']);
        self::assertSame(60.0, (float) $operation['vat_amount']);
        self::assertCount(2, $operation['items']);
        self::assertSame('FLT-AIR-001', $operation['items'][0]['product_sku']);
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ' . $productId)->fetchColumn());
    }

    public function testDirectQuoteInvoicingDecrementsStockExactlyOnce(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'DIRECT-001',
            'name' => 'Direct invoice product',
            'category_id' => $categoryId,
            'stock_qty' => 10,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 20,
        ], 1);
        $productId = $this->id($pdo, 'SELECT id FROM products WHERE sku = "DIRECT-001"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);

        $quoteId = $db->createOperation([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => 'ESP',
            'product_1' => $productId,
            'product_qty_1' => 4,
        ], 1);
        $invoiceId = $db->invoiceDocument($quoteId, 1);
        $invoice = $db->operation($invoiceId);
        $order = $db->operation((int) $invoice['parent_id']);

        self::assertSame(6, (int) $db->product($productId)['stock_qty']);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = $productId AND movement_type = 'out'")->fetchColumn());
        self::assertSame(4, (int) $pdo->query("SELECT quantity FROM stock_movements WHERE product_id = $productId AND movement_type = 'out'")->fetchColumn());
        self::assertSame('Bon de commande ' . $order['order_no'], $pdo->query("SELECT note FROM stock_movements WHERE product_id = $productId AND movement_type = 'out'")->fetchColumn());
    }

    public function testInvoiceRejectsInsufficientStockWithoutPartialWrites(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'SHORT-001',
            'name' => 'Short product',
            'category_id' => $categoryId,
            'stock_qty' => 2,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 20,
        ], 1);
        $productId = $this->id($pdo, 'SELECT id FROM products WHERE sku = "SHORT-001"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);
        $quoteId = $db->createOperation([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => 'ESP',
            'product_1' => $productId,
            'product_qty_1' => 5,
        ], 1);

        try {
            $db->invoiceDocument($quoteId, 1);
            self::fail('Expected insufficient stock exception.');
        } catch (InvalidArgumentException) {
            self::assertSame(2, (int) $db->product($productId)['stock_qty']);
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM operations')->fetchColumn());
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = $productId AND movement_type = 'out'")->fetchColumn());
        }
    }

    public function testMultiLineInvoiceMovesOnlyStockableProducts(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct(['sku' => 'MULTI-A', 'name' => 'Product A', 'category_id' => $categoryId, 'stock_qty' => 10, 'min_qty' => 1, 'purchase_price' => 10, 'sale_price' => 20], 1);
        $db->saveProduct(['sku' => 'MULTI-B', 'name' => 'Product B', 'category_id' => $categoryId, 'stock_qty' => 5, 'min_qty' => 1, 'purchase_price' => 10, 'sale_price' => 30], 1);
        $db->saveProduct(['sku' => 'MULTI-SVC', 'name' => 'Service line', 'category_id' => $categoryId, 'stock_qty' => 0, 'min_qty' => 0, 'purchase_price' => 0, 'sale_price' => 100, 'product_type' => 'service'], 1);
        $productA = $this->id($pdo, 'SELECT id FROM products WHERE sku = "MULTI-A"');
        $productB = $this->id($pdo, 'SELECT id FROM products WHERE sku = "MULTI-B"');
        $service = $this->id($pdo, 'SELECT id FROM products WHERE sku = "MULTI-SVC"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);

        $quoteId = $db->createOperation([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => 'ESP',
            'line_product_id' => [$productA, $productB, $service],
            'line_label' => ['', '', ''],
            'line_quantity' => [3, 2, 1],
            'line_unit_price' => [20, 30, 100],
            'line_discount' => [0, 0, 0],
        ], 1);
        $db->invoiceDocument($quoteId, 1);

        self::assertSame(7, (int) $db->product($productA)['stock_qty']);
        self::assertSame(3, (int) $db->product($productB)['stock_qty']);
        self::assertSame(0, (int) $db->product($service)['stock_qty']);
        self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'out' AND product_id IN ($productA, $productB, $service)")->fetchColumn());
    }

    public function testDocumentProgressionDoesNotDoubleDecrementStock(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct(['sku' => 'DOUBLE-001', 'name' => 'Double guard', 'category_id' => $categoryId, 'stock_qty' => 10, 'min_qty' => 1, 'purchase_price' => 10, 'sale_price' => 20], 1);
        $productId = $this->id($pdo, 'SELECT id FROM products WHERE sku = "DOUBLE-001"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);

        $quoteId = $db->createOperation([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => 'ESP',
            'product_1' => $productId,
            'product_qty_1' => 3,
        ], 1);
        $invoiceId = $db->invoiceDocument($quoteId, 1);

        foreach ([$quoteId, $invoiceId] as $documentId) {
            try {
                $db->invoiceDocument($documentId, 1);
                self::fail('Expected already processed document to be rejected.');
            } catch (InvalidArgumentException) {
                self::assertSame(7, (int) $db->product($productId)['stock_qty']);
            }
        }
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = $productId AND movement_type = 'out'")->fetchColumn());
    }

    public function testStockConsistencyCheckCommandReportsCleanDatabase(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct(['sku' => 'CHECK-001', 'name' => 'Check product', 'category_id' => $categoryId, 'stock_qty' => 10, 'min_qty' => 1, 'purchase_price' => 10, 'sale_price' => 20], 1);
        $productId = $this->id($pdo, 'SELECT id FROM products WHERE sku = "CHECK-001"');
        $db->addStock($productId, 2, 'purchase', 1);
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);
        $quoteId = $db->createOperation(['client_id' => $clientId, 'vehicle_id' => $vehicleId, 'payment_method' => 'ESP', 'product_1' => $productId, 'product_qty_1' => 4], 1);
        $db->invoiceDocument($quoteId, 1);
        $db->adjustStock($productId, 9, 'inventory', 1);

        self::assertSame([], $db->stockConsistencyIssues());
        $tester = new CommandTester(new StockCheckCommand($db));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Stock is consistent.', $tester->getDisplay());
    }

    public function testServiceProductInvoicesWithoutStockMovement(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct(['sku' => 'SVC-ONLY', 'name' => 'Service only', 'category_id' => $categoryId, 'stock_qty' => 0, 'min_qty' => 0, 'purchase_price' => 0, 'sale_price' => 120, 'product_type' => 'service'], 1);
        $serviceId = $this->id($pdo, 'SELECT id FROM products WHERE sku = "SVC-ONLY"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);

        $quoteId = $db->createOperation([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => 'ESP',
            'line_product_id' => [$serviceId],
            'line_label' => [''],
            'line_quantity' => [1],
            'line_unit_price' => [120],
        ], 1);
        $invoiceId = $db->invoiceDocument($quoteId, 1);

        self::assertSame('invoice', $db->operation($invoiceId)['doc_type']);
        self::assertSame(0, (int) $db->product($serviceId)['stock_qty']);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = $serviceId")->fetchColumn());
    }

    public function testMinimumQuantityAndDashboardCriticalStockAlerts(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct(['sku' => 'MIN-DEFAULT', 'name' => 'Default minimum', 'category_id' => $categoryId, 'stock_qty' => 8, 'purchase_price' => 10, 'sale_price' => 20], 1);
        $db->saveProduct(['sku' => 'MIN-LOW', 'name' => 'Low threshold product', 'category_id' => $categoryId, 'stock_qty' => 2, 'min_qty' => 5, 'purchase_price' => 10, 'sale_price' => 20], 1);
        $db->saveProduct(['sku' => 'MIN-EMPTY', 'name' => 'Empty threshold product', 'category_id' => $categoryId, 'stock_qty' => 0, 'min_qty' => 5, 'purchase_price' => 10, 'sale_price' => 20], 1);
        $db->saveProduct(['sku' => 'MIN-OK', 'name' => 'Healthy threshold product', 'category_id' => $categoryId, 'stock_qty' => 8, 'min_qty' => 5, 'purchase_price' => 10, 'sale_price' => 20], 1);

        self::assertSame(0, (int) $db->product($this->id($pdo, 'SELECT id FROM products WHERE sku = "MIN-DEFAULT"'))['min_qty']);
        $critical = $db->criticalStockProducts();
        self::assertSame(['Empty threshold product', 'Low threshold product'], array_column($critical, 'name'));
        self::assertSame('empty', $critical[0]['stock_severity']);
        self::assertSame('low', $critical[1]['stock_severity']);

        $context = $db->dashboardData();
        foreach (['admin', 'manager'] as $role) {
            $html = $this->renderTemplate('app/index.html.twig', $context + ['user' => ['role' => $role, 'name' => $role]]);
            self::assertStringContainsString('Low threshold product', $html);
            self::assertStringContainsString('2 produit(s) a reapprovisionner', $html);
            self::assertStringContainsString('Stock critique', $html);
            self::assertStringContainsString('data-critical-stock-toggle', $html);
            self::assertStringContainsString('data-critical-stock-panel hidden', $html);
            self::assertStringContainsString('dashboard-badge', $html);
            self::assertStringNotContainsString('stock-alert-danger', $html);
        }

        $healthyDb = $this->database();
        $healthyHtml = $this->renderTemplate('app/index.html.twig', $healthyDb->dashboardData() + ['user' => ['role' => 'admin', 'name' => 'Admin']]);
        self::assertStringContainsString('Stock sain', $healthyHtml);
        self::assertStringContainsString('dashboard-badge-ok', $healthyHtml);
        self::assertStringNotContainsString('stock-alert', $healthyHtml);
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
        self::assertSame('ref_universal', $migrated->query("SELECT name FROM pragma_table_info('products') WHERE name = 'ref_universal'")->fetchColumn());
        self::assertSame('ref_company', $migrated->query("SELECT name FROM pragma_table_info('products') WHERE name = 'ref_company'")->fetchColumn());
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

    public function testPrintableQuoteOrderAndInvoiceUseCorrectTitlesAndLegalMentions(): void
    {
        $db = $this->database();
        $quoteId = $this->operationWithService($db, 'ESP');
        $orderId = $db->confirmQuote($quoteId, 1);
        $invoiceId = $db->invoiceDocument($orderId, 1);

        $quote = $db->operation($quoteId);
        $order = $db->operation($orderId);
        $invoice = $db->operation($invoiceId);

        $quoteHtml = $this->renderTemplate('documents/invoice.html.twig', [
            'operation' => $quote,
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'company' => $this->company(),
            'amount_words' => 'cent cinquante dirhams TTC',
        ]);
        $orderHtml = $this->renderTemplate('documents/invoice.html.twig', [
            'operation' => $order,
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'company' => $this->company(),
            'amount_words' => 'cent cinquante dirhams TTC',
        ]);
        $invoiceHtml = $this->renderTemplate('documents/invoice.html.twig', [
            'operation' => $invoice,
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'company' => $this->company(),
            'amount_words' => 'cent cinquante dirhams TTC',
        ]);

        self::assertStringContainsString('DEVIS N° ' . $quote['quote_no'], $quoteHtml);
        self::assertStringContainsString('Devis valable 30 jours', $quoteHtml);
        self::assertStringContainsString('NET A PAYER', $quoteHtml);
        self::assertStringNotContainsString('MT HT', $quoteHtml);
        self::assertStringNotContainsString('TVA', $quoteHtml);
        self::assertStringNotContainsString('FACTURE N°', $quoteHtml);
        self::assertStringContainsString('BON DE COMMANDE N° ' . $order['order_no'], $orderHtml);
        self::assertStringContainsString('NET A PAYER', $orderHtml);
        self::assertStringNotContainsString('MT HT', $orderHtml);
        self::assertStringNotContainsString('TVA', $orderHtml);
        self::assertStringContainsString('FACTURE N° ' . $invoice['invoice_no'], $invoiceHtml);
        self::assertStringContainsString('MT HT', $invoiceHtml);
        self::assertStringContainsString('TVA', $invoiceHtml);
        self::assertStringContainsString('MT TTC A PAYER', $invoiceHtml);
        self::assertStringContainsString(number_format((float) $invoice['total_ttc'], 2, ',', ' ') . ' DH', $invoiceHtml);
        self::assertStringContainsString('cent cinquante dirhams TTC', $invoiceHtml);
    }

    public function testOperationLineMarginSelectorIsRecalculatedServerSideAndManualPriceIsPreserved(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'MARGIN-LINE',
            'name' => 'Produit marge ligne',
            'category_id' => $categoryId,
            'stock_qty' => 3,
            'min_qty' => 1,
            'purchase_price' => 100,
            'sale_price' => 130,
            'margin_mode' => '45',
        ], 1);
        $productId = $this->id($pdo, 'SELECT id FROM products WHERE sku = "MARGIN-LINE"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);

        $quoteId = $db->createOperation([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => 'ESP',
            'line_product_id' => [$productId, $productId],
            'line_label' => ['', 'Prix manuel'],
            'line_quantity' => [1, 1],
            'line_margin_mode' => ['45', 'manual'],
            'line_unit_price' => [1, 150],
            'line_discount' => [0, 0],
        ], 1);
        $operation = $db->operation($quoteId);

        self::assertSame(181.82, (float) $operation['items'][0]['unit_price']);
        self::assertSame(181.82, (float) $operation['items'][0]['total']);
        self::assertSame(150.0, (float) $operation['items'][1]['unit_price']);
        self::assertSame(331.82, (float) $operation['total_ttc']);
    }

    public function testDraftQuoteCanBeEditedWithLineMarginButConfirmedDocumentCannot(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'EDIT-MARGIN',
            'name' => 'Produit edit marge',
            'category_id' => $categoryId,
            'stock_qty' => 3,
            'min_qty' => 1,
            'purchase_price' => 100,
            'sale_price' => 120,
        ], 1);
        $productId = $this->id($pdo, 'SELECT id FROM products WHERE sku = "EDIT-MARGIN"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);
        $quoteId = $db->createOperation([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => 'ESP',
            'line_product_id' => [$productId],
            'line_label' => [''],
            'line_quantity' => [1],
            'line_unit_price' => [120],
            'line_discount' => [0],
        ], 1);

        $db->updateDraftOperation($quoteId, [
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => 'ESP',
            'line_product_id' => [$productId],
            'line_label' => [''],
            'line_quantity' => [1],
            'line_margin_mode' => ['55'],
            'line_unit_price' => [1],
            'line_discount' => [0],
        ], 1);
        $quote = $db->operation($quoteId);
        self::assertSame(222.22, (float) $quote['items'][0]['unit_price']);
        self::assertSame(222.22, (float) $quote['total_ttc']);

        $orderId = $db->confirmQuote($quoteId, 1);
        $this->expectException(InvalidArgumentException::class);
        $db->updateDraftOperation($orderId, [
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => 'ESP',
            'line_product_id' => [$productId],
            'line_label' => [''],
            'line_quantity' => [1],
            'line_unit_price' => [200],
            'line_discount' => [0],
        ], 1);
    }

    public function testBillingShowsReceiptOnlyForInvoices(): void
    {
        $db = $this->database();
        $quoteId = $this->operationWithService($db, 'ESP');
        $orderId = $db->confirmQuote($quoteId, 1);
        $invoiceId = $db->invoiceDocument($orderId, 1);

        $html = $this->renderTemplate('app/billing.html.twig', [
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'operations' => [$db->operation($quoteId), $db->operation($orderId), $db->operation($invoiceId)],
            'products' => [],
            'metrics' => [],
            'categories' => [],
            'suppliers' => [],
            'clients' => [],
            'brands' => [],
            'models' => [],
            'vehicles' => [],
        ]);

        self::assertSame(3, substr_count($html, '/app_receipt/'));
    }

    public function testSearchOperationsFiltersByTextTypePaymentAndInclusiveDates(): void
    {
        $db = $this->database();
        $quoteId = $this->operationWithService($db, 'CHQ', 'CHQ-42');
        $orderId = $db->confirmQuote($quoteId, 1);
        $invoiceId = $db->invoiceDocument($orderId, 1);
        $invoice = $db->operation($invoiceId);
        $date = substr((string) $invoice['created_at'], 0, 10);

        $byNumber = $db->searchOperations(['q' => $invoice['invoice_no']]);
        self::assertSame($invoiceId, (int) $byNumber['rows'][0]['id']);

        $byClient = $db->searchOperations(['q' => 'client sim']);
        self::assertGreaterThanOrEqual(3, $byClient['total']);

        $byType = $db->searchOperations(['doc_type' => 'invoice']);
        self::assertSame([$invoiceId], array_map('intval', array_column($byType['rows'], 'id')));

        $byPayment = $db->searchOperations(['payment_method' => 'CHQ', 'date_from' => $date, 'date_to' => $date]);
        self::assertCount(3, $byPayment['rows']);
        self::assertContains($invoiceId, array_map('intval', array_column($byPayment['rows'], 'id')));
    }

    public function testOperationShowDisplaysLinesTotalsAndDocumentChain(): void
    {
        $db = $this->database();
        $quoteId = $this->operationWithService($db, 'ESP');
        $orderId = $db->confirmQuote($quoteId, 1);
        $invoiceId = $db->invoiceDocument($orderId, 1);
        $invoice = $db->operation($invoiceId);

        $html = $this->renderTemplate('app/operation_show.html.twig', [
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'operation' => $invoice,
            'chain' => $db->operationDocumentChain($invoiceId),
            'back_query' => ['doc_type' => 'invoice'],
        ]);

        self::assertStringContainsString('FACTURE N° ' . $invoice['invoice_no'], $html);
        self::assertStringContainsString('DEVIS N°', $html);
        self::assertStringContainsString('BON DE COMMANDE N°', $html);
        self::assertStringContainsString('Diagnostic', $html);
        self::assertStringContainsString('المجموع TTC', $html);
        self::assertStringContainsString('aria-disabled="true"', $html);
        self::assertStringContainsString('مستند مؤكد', $html);
        self::assertStringNotContainsString('MT HT', $html);
        self::assertStringNotContainsString('TVA', $html);

        $lockedQuoteHtml = $this->renderTemplate('app/operation_show.html.twig', [
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'operation' => $db->operation($quoteId),
            'chain' => $db->operationDocumentChain($quoteId),
            'back_query' => ['doc_type' => 'quote'],
        ]);
        self::assertStringNotContainsString('/app_operation_edit/' . $quoteId, $lockedQuoteHtml);
        self::assertStringNotContainsString('MT HT', $lockedQuoteHtml);
        self::assertStringNotContainsString('TVA', $lockedQuoteHtml);

        $draftQuoteId = $this->operationWithService($db, 'ESP');
        $draftQuoteHtml = $this->renderTemplate('app/operation_show.html.twig', [
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'operation' => $db->operation($draftQuoteId),
            'chain' => $db->operationDocumentChain($draftQuoteId),
            'back_query' => ['doc_type' => 'quote'],
        ]);
        self::assertStringContainsString('/app_operation_edit/' . $draftQuoteId, $draftQuoteHtml);
        self::assertStringContainsString('method="post"', $draftQuoteHtml);
        self::assertStringContainsString('/app_operation_confirm/' . $draftQuoteId, $draftQuoteHtml);
        self::assertStringContainsString('/app_operation_invoice_direct/' . $draftQuoteId, $draftQuoteHtml);

        $historyHtml = $this->renderTemplate('app/operations_history.html.twig', [
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'operations' => [$db->operation($draftQuoteId)],
            'result_total' => 1,
            'is_limited' => false,
            'operation_action_token' => 'token',
            'filters' => ['q' => '', 'doc_type' => '', 'from' => '', 'to' => '', 'payment' => ''],
        ]);
        self::assertStringContainsString('/app_operation_confirm/' . $draftQuoteId, $historyHtml);
        self::assertStringContainsString('/app_operation_invoice_direct/' . $draftQuoteId, $historyHtml);
    }

    public function testDocumentEditPermissionDependsOnDraftQuoteState(): void
    {
        $db = $this->database();
        $access = new AccessControl();
        $controller = new DashboardController();
        $manager = ['id' => 2, 'name' => 'Manager', 'email' => 'manager@simauto.ma', 'role' => 'manager'];
        $admin = ['id' => 1, 'name' => 'Admin', 'email' => 'admin@simauto.ma', 'role' => 'admin'];
        $quoteId = $this->operationWithService($db, 'ESP');
        $quote = $db->operation($quoteId);

        self::assertTrue($access->canEditDocument($manager, $quote));
        self::assertTrue($access->canEditDocument($admin, $quote));

        $draftPost = $this->requestWithUser('/operations/' . $quoteId . '/edit', 'POST', [
            'client_id' => $quote['client_id'],
            'vehicle_id' => $quote['vehicle_id'],
            'payment_method' => 'ESP',
            'vat_rate' => 20,
            'line_product_id' => [''],
            'line_label' => ['Diagnostic modifie'],
            'line_quantity' => [1],
            'line_margin_mode' => ['manual'],
            'line_unit_price' => [250],
            'line_discount' => [0],
        ], $manager);
        $csrf = new ReflectionMethod($controller, 'csrfToken');
        $csrf->setAccessible(true);
        $draftPost->request->set('_token', $csrf->invoke($controller, $draftPost, 'operation_form'));
        $controller->setContainer($this->controllerContainer($draftPost));

        $response = $controller->operationEdit($quoteId, $draftPost, $db, $access);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('Diagnostic modifie', $db->operation($quoteId)['items'][0]['label']);

        $orderId = $db->confirmQuote($quoteId, 1);
        $orderPost = $this->requestWithUser('/operations/' . $orderId . '/edit', 'POST', [], $manager);
        $controller->setContainer($this->controllerContainer($orderPost));
        $response = $controller->operationEdit($orderId, $orderPost, $db, $access);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertFalse($access->canEditDocument($manager, $db->operation($orderId)));

        $invoiceId = $db->invoiceDocument($orderId, 1);
        $invoicePost = $this->requestWithUser('/operations/' . $invoiceId . '/edit', 'POST', [], $admin);
        $controller->setContainer($this->controllerContainer($invoicePost));
        $response = $controller->operationEdit($invoiceId, $invoicePost, $db, $access);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertFalse($access->canEditDocument($admin, $db->operation($invoiceId)));
    }

    public function testOperationProgressPostRoutesUseCsrfAndMoveStockOnOrderOnly(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $access = new AccessControl();
        $controller = new DashboardController();
        $manager = ['id' => 2, 'name' => 'Manager', 'email' => 'manager@simauto.ma', 'role' => 'manager'];
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct(['sku' => 'FLOW-001', 'name' => 'Workflow product', 'category_id' => $categoryId, 'stock_qty' => 10, 'min_qty' => 1, 'purchase_price' => 10, 'sale_price' => 20], 1);
        $productId = $this->id($pdo, 'SELECT id FROM products WHERE sku = "FLOW-001"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);
        $quoteId = $db->createOperation([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => 'ESP',
            'product_1' => $productId,
            'product_qty_1' => 2,
        ], 2);

        $missingToken = $this->requestWithUser('/operations/' . $quoteId . '/confirm', 'POST', [], $manager);
        $controller->setContainer($this->controllerContainer($missingToken));
        $response = $controller->confirmOperation($quoteId, $missingToken, $db, $access);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM operations')->fetchColumn());
        self::assertSame(10, (int) $db->product($productId)['stock_qty']);

        $csrf = new ReflectionMethod($controller, 'csrfToken');
        $csrf->setAccessible(true);
        $confirmRequest = $this->requestWithUser('/operations/' . $quoteId . '/confirm', 'POST', [], $manager);
        $confirmRequest->request->set('_token', $csrf->invoke($controller, $confirmRequest, 'operation_action'));
        $controller->setContainer($this->controllerContainer($confirmRequest));
        $response = $controller->confirmOperation($quoteId, $confirmRequest, $db, $access);

        $orderId = $this->id($pdo, 'SELECT id FROM operations WHERE parent_id = ' . $quoteId . ' AND doc_type = "order"');
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame($quoteId, (int) $db->operation($orderId)['parent_id']);
        self::assertSame(8, (int) $db->product($productId)['stock_qty']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ' . $productId . ' AND movement_type = "out"')->fetchColumn());

        $invoiceRequest = $this->requestWithUser('/operations/' . $orderId . '/invoice', 'POST', [], $manager);
        $invoiceRequest->request->set('_token', $csrf->invoke($controller, $invoiceRequest, 'operation_action'));
        $controller->setContainer($this->controllerContainer($invoiceRequest));
        $response = $controller->invoiceOperation($orderId, $invoiceRequest, $db, $access);

        $invoiceId = $this->id($pdo, 'SELECT id FROM operations WHERE parent_id = ' . $orderId . ' AND doc_type = "invoice"');
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('invoice', $db->operation($invoiceId)['doc_type']);
        self::assertSame(8, (int) $db->product($productId)['stock_qty']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ' . $productId . ' AND movement_type = "out"')->fetchColumn());
        self::assertSame([], $db->stockConsistencyIssues());
    }

    public function testDirectInvoiceRouteBuildsFullChainAndRejectsDoubleActions(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $access = new AccessControl();
        $controller = new DashboardController();
        $manager = ['id' => 2, 'name' => 'Manager', 'email' => 'manager@simauto.ma', 'role' => 'manager'];
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct(['sku' => 'DIRECT-ROUTE', 'name' => 'Direct route product', 'category_id' => $categoryId, 'stock_qty' => 5, 'min_qty' => 1, 'purchase_price' => 10, 'sale_price' => 20], 1);
        $productId = $this->id($pdo, 'SELECT id FROM products WHERE sku = "DIRECT-ROUTE"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);
        $quoteId = $db->createOperation([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => 'ESP',
            'product_1' => $productId,
            'product_qty_1' => 3,
        ], 2);
        $csrf = new ReflectionMethod($controller, 'csrfToken');
        $csrf->setAccessible(true);

        $request = $this->requestWithUser('/operations/' . $quoteId . '/invoice-direct', 'POST', [], $manager);
        $request->request->set('_token', $csrf->invoke($controller, $request, 'operation_action'));
        $controller->setContainer($this->controllerContainer($request));
        $response = $controller->invoiceDirectOperation($quoteId, $request, $db, $access);

        $orderId = $this->id($pdo, 'SELECT id FROM operations WHERE parent_id = ' . $quoteId . ' AND doc_type = "order"');
        $invoiceId = $this->id($pdo, 'SELECT id FROM operations WHERE parent_id = ' . $orderId . ' AND doc_type = "invoice"');
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(2, (int) $db->product($productId)['stock_qty']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ' . $productId . ' AND movement_type = "out"')->fetchColumn());

        $repeat = $this->requestWithUser('/operations/' . $invoiceId . '/invoice', 'POST', [], $manager);
        $repeat->request->set('_token', $csrf->invoke($controller, $repeat, 'operation_action'));
        $controller->setContainer($this->controllerContainer($repeat));
        $controller->invoiceOperation($invoiceId, $repeat, $db, $access);

        self::assertSame(2, (int) $db->product($productId)['stock_qty']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ' . $productId . ' AND movement_type = "out"')->fetchColumn());
    }

    public function testFinancialMarginsForProductsServicesAndEstimatedLines(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $categoryId = $this->id($pdo, 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'MARGIN-001',
            'name' => 'Piece marge',
            'category_id' => $categoryId,
            'stock_qty' => 5,
            'min_qty' => 1,
            'purchase_price' => 120,
            'sale_price' => 240,
        ], 1);
        $productId = $this->id($pdo, 'SELECT id FROM products WHERE sku = "MARGIN-001"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $pdo);

        $quoteId = $db->createOperation([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => 'ESP',
            'line_product_id' => [$productId, '', ''],
            'line_label' => ['', 'Service diagnostic', 'Ligne libre'],
            'line_quantity' => [1, 1, 1],
            'line_unit_price' => [240, 120, 60],
            'line_discount' => [0, 0, 0],
        ], 1);
        $invoiceId = $db->invoiceDocument($quoteId, 1);
        $details = $db->getOperationMarginDetails($invoiceId);

        self::assertCount(3, $details['margin_lines']);
        self::assertSame(100.0, (float) $details['margin_lines'][0]['cost_ht']);
        self::assertSame(100.0, (float) $details['margin_lines'][0]['margin']);
        self::assertSame(0.0, (float) $details['margin_lines'][1]['cost_ht']);
        self::assertSame(120.0, (float) $details['margin_lines'][1]['margin']);
        self::assertSame(0.0, (float) $details['margin_lines'][2]['cost_ht']);
        self::assertSame(60.0, (float) $details['margin_lines'][2]['margin']);
        self::assertFalse((bool) $details['margin_lines'][2]['is_estimated']);
        self::assertSame('service', $details['margin_lines'][2]['product_type']);
        self::assertSame(280.0, (float) $details['margin']);
        self::assertSame(350.0, (float) $details['subtotal_ht']);
    }

    public function testFinancialSummaryUsesInvoicesOnlyInclusiveDatesAndPaymentBreakdown(): void
    {
        $db = $this->database();
        $pdo = $db->pdo();
        $quoteId = $this->operationWithService($db, 'CHQ', 'CHQ-42');
        $orderId = $db->confirmQuote($quoteId, 1);
        $invoiceId = $db->invoiceDocument($orderId, 1);
        $date = date('Y-m-d');

        $summary = $db->getFinancialSummary($date, $date);
        $operations = $db->getFinancialOperations($date, $date);

        self::assertSame(1, $summary['invoice_count']);
        self::assertCount(1, $operations);
        self::assertSame($invoiceId, (int) $operations[0]['id']);
        self::assertSame(150.0, (float) $summary['total_ttc']);
        self::assertSame(125.0, (float) $summary['subtotal_ht']);
        self::assertSame(25.0, (float) $summary['vat_amount']);
        self::assertSame(150.0, (float) $summary['payments']['CHQ']['total_ttc']);
        self::assertSame(1, $summary['payments']['CHQ']['count']);
        self::assertSame([], $db->getOperationMarginDetails($quoteId));
    }

    public function testFinancialMarginRateIsProtectedAgainstZeroSubtotal(): void
    {
        $db = $this->database();
        $quoteId = $this->operationWithService($db, 'ESP');
        $invoiceId = $db->invoiceDocument($quoteId, 1);
        $db->pdo()->prepare('UPDATE operations SET subtotal_ht = 0, vat_amount = 0, total_ttc = 0, total = 0 WHERE id = :id')->execute(['id' => $invoiceId]);
        $date = date('Y-m-d');

        $summary = $db->getFinancialSummary($date, $date);
        $operation = $db->getOperationMarginDetails($invoiceId);

        self::assertSame(0.0, (float) $summary['margin_rate']);
        self::assertSame(0.0, (float) $operation['margin_rate']);
    }

    public function testReportPeriodFallbackAndReportPermissions(): void
    {
        $controller = new ReportController();
        $period = $controller->resolvePeriod(Request::create('/reports/finance?preset=custom&from=2026-07-20&to=2026-07-01'));
        $access = new AccessControl();

        self::assertSame('today', $period['preset']);
        self::assertTrue($period['invalid']);
        self::assertFalse($access->can('reports.view', ['role' => 'manager']));
        self::assertTrue($access->can('reports.view', ['role' => 'admin']));
    }

    public function testDayReceiptTemplateRendersSeededTotals(): void
    {
        $db = $this->database();
        $invoiceId = $db->invoiceDocument($this->operationWithService($db, 'ESP'), 1);
        $operation = $db->operation($invoiceId);
        $date = substr((string) $operation['created_at'], 0, 10);
        $html = $this->renderTemplate('documents/day_receipt.html.twig', [
            'report' => $db->getDailySessionReport($date),
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'company' => $this->company(),
        ]);

        self::assertStringContainsString('CLOTURE DE CAISSE', $html);
        self::assertStringContainsString($operation['invoice_no'], $html);
        self::assertStringContainsString('150,00 DH', $html);
        self::assertStringContainsString('TOTAL GENERAL', $html);
        self::assertStringNotContainsString('Total HT', $html);
        self::assertStringNotContainsString('TVA</span>', $html);
        self::assertStringNotContainsString('MARGE', $html);
        $financeHtml = $this->renderTemplate('app/report_finance.html.twig', [
            'user' => ['role' => 'admin', 'name' => 'Admin'],
            'period' => ['preset' => 'today', 'from' => $date, 'to' => $date, 'invalid' => false],
            'summary' => $db->getFinancialSummary($date, $date),
            'operations' => [],
        ]);
        self::assertStringContainsString('reports.kpi.subtotal_ht', $financeHtml);
        self::assertStringContainsString('reports.kpi.vat', $financeHtml);
        self::assertStringContainsString('reports.kpi.margin', $financeHtml);
    }

    public function testProductReferencesAreSavedUniqueAndSearchPriorityUsesReferences(): void
    {
        $db = $this->database();
        $categoryId = $this->id($db->pdo(), 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'SKU-A',
            'ref_universal' => 'OEM-777',
            'ref_company' => 'RC100',
            'name' => 'Filtre prioritaire',
            'category_id' => $categoryId,
            'stock_qty' => 1,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 20,
        ], 1);
        $db->saveProduct([
            'sku' => 'SKU-B',
            'name' => 'Produit RC100 dans le nom',
            'category_id' => $categoryId,
            'stock_qty' => 1,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 20,
        ], 1);
        $db->saveProduct([
            'sku' => 'SKU-C',
            'name' => 'Sans reference',
            'category_id' => $categoryId,
            'stock_qty' => 1,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 20,
        ], 1);

        try {
            $db->saveProduct([
                'sku' => 'SKU-D',
                'ref_company' => 'RC100',
                'name' => 'Double ref',
                'category_id' => $categoryId,
                'stock_qty' => 1,
                'min_qty' => 1,
                'purchase_price' => 10,
                'sale_price' => 20,
            ], 1);
            self::fail('Expected duplicate company reference rejection.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('مرجع الشركة مستعمل مسبقا', $e->getMessage());
        }

        $results = $db->products(['q' => 'RC100', 'state' => 'all']);
        self::assertSame('SKU-A', $results[0]['sku']);
        self::assertSame('RC100', $results[0]['ref_company']);
        self::assertSame('SKU-A', $db->products(['q' => 'OEM-777', 'state' => 'all'])[0]['sku']);
        self::assertNotEmpty($db->products(['q' => 'Filtre', 'state' => 'all']));
    }

    public function testProductReferencesAreRenderedInProductPagesAndOperationFallbackSelect(): void
    {
        $db = $this->database();
        $categoryId = $this->id($db->pdo(), 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'RENDER-001',
            'ref_universal' => 'OEM-RENDER',
            'ref_company' => 'SIM-RENDER',
            'name' => 'Produit rendu',
            'category_id' => $categoryId,
            'stock_qty' => 2,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 20,
        ], 1);
        $product = $db->productBySku('RENDER-001');
        $productsHtml = $this->renderTemplate('app/products.html.twig', $db->dashboardData(['state' => 'all']) + [
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'filters' => ['state' => 'all'],
            'record_token' => 'token',
        ]);
        $showHtml = $this->renderTemplate('app/product_show.html.twig', [
            'user' => ['role' => 'manager', 'name' => 'Manager'],
            'product' => $product,
            'movements' => [],
        ]);
        $operationHtml = $this->renderTemplate('app/operations.html.twig', $db->dashboardData() + [
            'user' => ['role' => 'manager', 'name' => 'Manager'],
        ]);

        self::assertStringContainsString('SIM-RENDER', $productsHtml);
        self::assertStringContainsString('OEM-RENDER', $showHtml);
        self::assertStringContainsString('<select name="line_product_id[]"', $operationHtml);
        self::assertStringContainsString('data-ref-company="SIM-RENDER"', $operationHtml);
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
            ['sku', 'ref_universal', 'ref_company', 'name', 'category_name', 'stock_qty', 'min_qty', 'purchase_price', 'sale_price'],
            ['IMP-001', 'OEM-IMP-001', 'SIM-IMP-001', 'Produit import', 'Categorie Import', '8', '2', '10', '20'],
        ]);

        $first = $imports->import('products', $path, 1);
        $product = $db->productBySku('IMP-001');

        self::assertSame(1, $first['created']);
        self::assertSame(8, (int) $product['stock_qty']);
        self::assertSame('OEM-IMP-001', $product['ref_universal']);
        self::assertSame('SIM-IMP-001', $product['ref_company']);
        self::assertSame('Categorie Import', $product['category_name']);
        self::assertSame(1, (int) $db->pdo()->query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ' . (int) $product['id'])->fetchColumn());

        $updatePath = $this->csv([
            ['sku', 'ref_universal', 'ref_company', 'name', 'category_name', 'stock_qty', 'min_qty', 'purchase_price', 'sale_price'],
            ['IMP-001', 'OEM-IMP-001-B', 'SIM-IMP-001', 'Produit import modifie', 'Categorie Import', '99', '3', '11', '25'],
        ]);
        $second = $imports->import('products', $updatePath, 1);
        $updated = $db->productBySku('IMP-001');

        self::assertSame(1, $second['updated']);
        self::assertSame(8, (int) $updated['stock_qty']);
        self::assertSame('Produit import modifie', $updated['name']);
        self::assertSame('OEM-IMP-001-B', $updated['ref_universal']);

        $duplicate = $imports->import('products', $this->csv([
            ['sku', 'ref_universal', 'ref_company', 'name', 'category_name', 'stock_qty', 'min_qty', 'purchase_price', 'sale_price'],
            ['IMP-002', '', 'SIM-DUP', 'Produit A', 'Categorie Import', '1', '1', '10', '20'],
            ['IMP-003', '', 'SIM-DUP', 'Produit B', 'Categorie Import', '1', '1', '10', '20'],
        ]), 1);
        self::assertSame(1, $duplicate['created']);
        self::assertSame(1, $duplicate['ignored']);
        self::assertSame('مرجع الشركة مكرر في الملف', $duplicate['errors'][0]['message']);
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
            ['sku', 'ref_universal', 'ref_company', 'name', 'category_name', 'stock_qty', 'min_qty', 'purchase_price', 'sale_price'],
            ['GOOD-001', '', '', 'Produit valide', 'Import Erreurs', '1', '1', '10', '20'],
            ['BAD-001', '', '', 'Produit invalide', 'Import Erreurs', '1', '1', '-10', '20'],
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

    public function testAccessControlRestrictsManagerToViewCreateAndDocumentProgress(): void
    {
        $access = new AccessControl();
        $manager = ['role' => 'manager'];
        $admin = ['role' => 'admin'];

        self::assertTrue($access->can('view', $manager));
        self::assertTrue($access->can('view.products', $manager));
        self::assertTrue($access->can('view.clients', $manager));
        self::assertTrue($access->can('view.suppliers', $manager));
        self::assertTrue($access->can('view.vehicles', $manager));
        self::assertTrue($access->can('view.vehicle_settings', $manager));
        self::assertTrue($access->can('view.operations', $manager));
        self::assertTrue($access->can('view.billing', $manager));
        self::assertTrue($access->can('create', $manager));
        self::assertTrue($access->can('progress_document', $manager));
        self::assertTrue($access->can('edit.reference', $manager));
        self::assertTrue($access->can('edit.quote_draft', $manager));
        self::assertTrue($access->can('clients', $manager));
        self::assertTrue($access->can('stock.adjust', $admin));
        self::assertTrue($access->can('unknown.future.permission', $admin));
        self::assertFalse($access->can('edit', $manager));
        self::assertFalse($access->can('edit.stock', $manager));
        self::assertFalse($access->can('edit.operation', $manager));
        self::assertFalse($access->can('stock.adjust', $manager));
        self::assertFalse($access->can('delete', $manager));
        self::assertFalse($access->can('toggle', $manager));
        self::assertFalse($access->can('import', $manager));
        self::assertFalse($access->can('imports', $manager));
        self::assertFalse($access->can('manage_users', $manager));
        self::assertFalse($access->can('reports.view', $manager));
        self::assertFalse($access->can('vehicle_settings', $manager));
        self::assertTrue($access->can('delete', $admin));
        self::assertTrue($access->can('toggle', $admin));
        self::assertTrue($access->can('import', $admin));
        self::assertTrue($access->can('manage_users', $admin));
    }

    public function testManagerCanEditProductDescriptionButCannotChangeStockOrDeleteOrToggle(): void
    {
        $db = $this->database();
        $access = new AccessControl();
        $controller = new DashboardController();
        $manager = ['id' => 2, 'name' => 'Manager', 'email' => 'manager@simauto.ma', 'role' => 'manager'];

        $categoryId = $this->id($db->pdo(), 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'ACL-001',
            'name' => 'Produit ACL',
            'category_id' => $categoryId,
            'stock_qty' => 2,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 20,
        ], 1);
        $productId = $this->id($db->pdo(), 'SELECT id FROM products WHERE sku = "ACL-001"');
        $beforeQty = (int) $db->product($productId)['stock_qty'];

        $editRequest = $this->requestWithUser('/products/' . $productId . '/edit', 'POST', [
            'sku' => 'ACL-001',
            'name' => 'Produit modifie',
            'ref_universal' => 'OEM-ACL',
            'ref_company' => 'SIM-ACL',
            'product_type' => 'stockable',
            'category_id' => $categoryId,
            'stock_qty' => 999,
            'min_qty' => 1,
            'purchase_price' => 10,
            'margin_mode' => '45',
            'sale_price' => 1,
        ], $manager);
        $controller->setContainer($this->controllerContainer($editRequest));

        $response = $controller->productEdit($productId, $editRequest, $db, $access);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $product = $db->product($productId);
        self::assertSame('Produit modifie', $product['name']);
        self::assertSame('SIM-ACL', $product['ref_company']);
        self::assertSame($beforeQty, (int) $product['stock_qty']);
        self::assertSame(45.0, (float) $product['margin_rate']);

        $editHtml = $this->renderTemplate('app/product_edit.html.twig', [
            'user' => $manager,
            'product' => $product,
            'categories' => $db->categories(false),
        ]);
        self::assertStringNotContainsString('name="stock_qty"', $editHtml);
        self::assertStringContainsString('name="product_type"', $editHtml);

        $toggleRequest = $this->requestWithUser('/records/product/' . $productId . '/deactivate', 'POST', [], $manager);
        $controller->setContainer($this->controllerContainer($toggleRequest));
        $response = $controller->recordState('product', $productId, 'deactivate', $toggleRequest, $db, $access);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(1, (int) $db->product($productId)['active']);

        $deleteRequest = $this->requestWithUser('/records/product/' . $productId . '/delete', 'POST', [], $manager);
        $controller->setContainer($this->controllerContainer($deleteRequest));
        $response = $controller->recordState('product', $productId, 'delete', $deleteRequest, $db, $access);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertNotNull($db->product($productId));
    }

    public function testManagerCanEditReferenceEntities(): void
    {
        $db = $this->database();
        $access = new AccessControl();
        $controller = new DashboardController();
        $manager = ['id' => 2, 'name' => 'Manager', 'email' => 'manager@simauto.ma', 'role' => 'manager'];

        $post = $this->requestWithUser('/vehicles/settings', 'POST', [
            'kind' => 'brand',
            'name' => 'MANAGER-BRAND',
        ], $manager);
        $controller->setContainer($this->controllerContainer($post));
        $response = $controller->vehicleSettings($post, $db, $access);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $brandId = (int) $db->vehicleBrandByName('MANAGER-BRAND')['id'];

        $brandEdit = $this->requestWithUser('/vehicle-brands/' . $brandId . '/edit', 'POST', ['name' => 'MANAGER-BRAND-EDIT'], $manager);
        $controller->setContainer($this->controllerContainer($brandEdit));
        $controller->vehicleBrandEdit($brandId, $brandEdit, $db, $access);
        self::assertNotNull($db->vehicleBrandByName('MANAGER-BRAND-EDIT'));

        $modelCreate = $this->requestWithUser('/vehicles/settings', 'POST', [
            'kind' => 'model',
            'brand_id' => $brandId,
            'name' => 'MODEL-1',
        ], $manager);
        $controller->setContainer($this->controllerContainer($modelCreate));
        $controller->vehicleSettings($modelCreate, $db, $access);
        $modelId = (int) $db->vehicleModelByName($brandId, 'MODEL-1')['id'];

        $modelEdit = $this->requestWithUser('/vehicle-models/' . $modelId . '/edit', 'POST', [
            'brand_id' => $brandId,
            'name' => 'MODEL-EDIT',
        ], $manager);
        $controller->setContainer($this->controllerContainer($modelEdit));
        $controller->vehicleModelEdit($modelId, $modelEdit, $db, $access);
        self::assertNotNull($db->vehicleModelByName($brandId, 'MODEL-EDIT'));

        $categoryId = $this->id($db->pdo(), 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $categoryEdit = $this->requestWithUser('/categories/' . $categoryId . '/edit', 'POST', ['name' => 'Manager Category'], $manager);
        $controller->setContainer($this->controllerContainer($categoryEdit));
        $controller->categoryEdit($categoryId, $categoryEdit, $db, $access);
        self::assertSame('Manager Category', $db->category($categoryId)['name']);

        $db->saveSupplier(['name' => 'Supplier editable']);
        $supplierId = $this->id($db->pdo(), 'SELECT id FROM suppliers WHERE name = "Supplier editable"');
        $supplierEdit = $this->requestWithUser('/suppliers/' . $supplierId . '/edit', 'POST', [
            'name' => 'Supplier edited',
            'phone' => '0600000099',
        ], $manager);
        $controller->setContainer($this->controllerContainer($supplierEdit));
        $controller->supplierEdit($supplierId, $supplierEdit, $db, $access);
        self::assertSame('Supplier edited', $db->supplier($supplierId)['name']);

        $db->saveClient(['type' => 'company', 'name' => 'Client editable', 'ice' => 'OLD']);
        $clientId = $this->id($db->pdo(), 'SELECT id FROM clients WHERE name = "Client editable"');
        $clientEdit = $this->requestWithUser('/clients/' . $clientId . '/edit', 'POST', [
            'type' => 'company',
            'name' => 'Client edited',
            'phone' => '0612345678',
            'email' => '',
            'address' => 'Agadir',
            'ice' => 'ICE-NEW',
            'vat' => 'IF-NEW',
            'rc' => 'RC-NEW',
        ], $manager);
        $controller->setContainer($this->controllerContainer($clientEdit));
        $controller->clientEdit($clientId, $clientEdit, $db, $access);
        self::assertSame('ICE-NEW', $db->client($clientId)['ice']);

        $db->saveVehicle([
            'client_id' => $clientId,
            'plate' => '11111-A-10',
            'brand_id' => $brandId,
            'model_id' => $modelId,
            'year' => 2020,
            'mileage' => 100,
        ]);
        $vehicleId = $this->id($db->pdo(), 'SELECT id FROM vehicles WHERE plate = "11111-A-10"');
        $vehicleEdit = $this->requestWithUser('/vehicles/' . $vehicleId . '/edit', 'POST', [
            'client_id' => $clientId,
            'plate' => '11111-A-11',
            'brand_id' => $brandId,
            'model_id' => $modelId,
            'year' => 2025,
            'mileage' => 12345,
            'notes' => 'Updated by manager',
        ], $manager);
        $controller->setContainer($this->controllerContainer($vehicleEdit));
        $controller->vehicleEdit($vehicleId, $vehicleEdit, $db, $access);
        self::assertSame('11111-A-11', $db->vehicle($vehicleId)['plate']);
    }

    public function testManagerImportRoutesAreDenied(): void
    {
        $db = $this->database();
        $access = new AccessControl();
        $imports = new ImportService($db);
        $controller = new ImportController();

        $request = $this->requestWithUser('/import', 'GET', [], ['id' => 2, 'name' => 'Manager', 'email' => 'manager@simauto.ma', 'role' => 'manager']);
        $controller->setContainer($this->controllerContainer($request));
        $response = $controller->index($request, $db, $imports, $access);

        self::assertInstanceOf(RedirectResponse::class, $response);

        $post = $this->requestWithUser('/import/products', 'POST', [], ['id' => 2, 'name' => 'Manager', 'email' => 'manager@simauto.ma', 'role' => 'manager']);
        $controller->setContainer($this->controllerContainer($post));
        $response = $controller->upload('products', $post, $db, $imports, $access);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testManagerCanCreateDailyRecordsAndProgressDocumentWorkflow(): void
    {
        $db = $this->database();
        $access = new AccessControl();
        $controller = new DashboardController();
        $manager = ['id' => 2, 'name' => 'Manager', 'email' => 'manager@simauto.ma', 'role' => 'manager'];
        $categoryId = $this->id($db->pdo(), 'SELECT id FROM categories ORDER BY id LIMIT 1');

        $productRequest = $this->requestWithUser('/products/new', 'POST', [
            'sku' => 'ACL-CREATE',
            'name' => 'Creation manager',
            'category_id' => $categoryId,
            'stock_qty' => 5,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 30,
        ], $manager);
        $controller->setContainer($this->controllerContainer($productRequest));
        $controller->newProduct($productRequest, $db, $access);
        $productId = $this->id($db->pdo(), 'SELECT id FROM products WHERE sku = "ACL-CREATE"');
        self::assertGreaterThan(0, $productId);

        $clientRequest = $this->requestWithUser('/clients', 'POST', [
            'type' => 'individual',
            'name' => 'Client Manager',
            'phone' => '0600000001',
            'email' => '',
            'address' => 'Agadir',
        ], $manager);
        $controller->setContainer($this->controllerContainer($clientRequest));
        $controller->clients($clientRequest, $db, $access);
        self::assertGreaterThan(0, $this->id($db->pdo(), 'SELECT id FROM clients WHERE name = "Client Manager"'));

        $supplierRequest = $this->requestWithUser('/suppliers', 'POST', [
            'name' => 'Fournisseur Manager',
            'phone' => '0600000002',
            'email' => '',
            'address' => 'Agadir',
        ], $manager);
        $controller->setContainer($this->controllerContainer($supplierRequest));
        $controller->suppliers($supplierRequest, $db, $access);
        self::assertGreaterThan(0, $this->id($db->pdo(), 'SELECT id FROM suppliers WHERE name = "Fournisseur Manager"'));

        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $db->pdo());
        $brandId = $this->id($db->pdo(), 'SELECT id FROM vehicle_brands WHERE name = "VW"');
        $modelId = $this->id($db->pdo(), 'SELECT id FROM vehicle_models WHERE brand_id = ' . $brandId . ' AND name = "Tiguan"');
        $vehicleRequest = $this->requestWithUser('/vehicles', 'POST', [
            'client_id' => $clientId,
            'plate' => '33333-M-10',
            'brand_id' => $brandId,
            'model_id' => $modelId,
            'year' => 2024,
            'mileage' => 500,
        ], $manager);
        $controller->setContainer($this->controllerContainer($vehicleRequest));
        $controller->vehicles($vehicleRequest, $db, $access);
        self::assertGreaterThan(0, $this->id($db->pdo(), 'SELECT id FROM vehicles WHERE plate = "33333-M-10"'));

        $quoteId = $db->createOperation([
            'client_id' => $clientId,
            'vehicle_id' => $vehicleId,
            'payment_method' => 'ESP',
            'product_1' => $productId,
            'product_qty_1' => 2,
        ], 2);
        $orderId = $db->confirmQuote($quoteId, 2);
        $invoiceId = $db->invoiceDocument($orderId, 2);

        self::assertSame('invoice', $db->operation($invoiceId)['doc_type']);
        self::assertSame(3, (int) $db->product($productId)['stock_qty']);
    }

    public function testManagerProductListShowsEditButHidesDeleteAndToggleButtons(): void
    {
        $db = $this->database();
        $categoryId = $this->id($db->pdo(), 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'BTN-001',
            'name' => 'Produit bouton',
            'category_id' => $categoryId,
            'stock_qty' => 1,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 20,
        ], 1);
        $context = $db->dashboardData() + [
            'filters' => [],
            'record_token' => 'token',
        ];

        $managerHtml = $this->renderTemplate('app/products.html.twig', $context + [
            'user' => ['role' => 'manager', 'name' => 'Manager'],
        ]);
        $adminHtml = $this->renderTemplate('app/products.html.twig', $context + [
            'user' => ['role' => 'admin', 'name' => 'Admin'],
        ]);

        self::assertStringContainsString('تعديل', $managerHtml);
        self::assertStringNotContainsString('حذف', $managerHtml);
        self::assertStringNotContainsString('تعطيل', $managerHtml);
        self::assertStringContainsString('تعديل', $adminHtml);
        self::assertStringContainsString('حذف', $adminHtml);
    }

    public function testBusinessListButtonsMatchAdminAndManagerPermissions(): void
    {
        $db = $this->database();
        $categoryId = $this->id($db->pdo(), 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'BTN-ALL',
            'name' => 'Produit global',
            'category_id' => $categoryId,
            'stock_qty' => 1,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 20,
        ], 1);
        $db->saveSupplier(['name' => 'Supplier buttons']);
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $db->pdo());

        $contexts = [
            'app/products.html.twig' => $db->dashboardData(['state' => 'all']) + ['filters' => ['state' => 'all'], 'record_token' => 'token'],
            'app/categories.html.twig' => ['categories' => $db->categories(false), 'record_token' => 'token'],
            'app/suppliers.html.twig' => ['suppliers' => $db->suppliers(false), 'record_token' => 'token'],
            'app/clients.html.twig' => ['clients' => $db->clients(), 'record_token' => 'token'],
            'app/vehicles.html.twig' => [
                'clients' => $db->clients(),
                'brands' => $db->vehicleBrands(),
                'models' => $db->vehicleModels(),
                'vehicles' => $db->vehicles(),
                'selected_client_id' => $clientId,
                'record_token' => 'token',
            ],
            'app/vehicle_settings.html.twig' => [
                'brands' => $db->vehicleBrands('all'),
                'models' => $db->vehicleModels(null, 'all'),
                'record_token' => 'token',
            ],
        ];
        $expectedEditLinks = [
            'app/products.html.twig' => '/app_product_edit/',
            'app/categories.html.twig' => '/app_category_edit/',
            'app/suppliers.html.twig' => '/app_supplier_edit/',
            'app/clients.html.twig' => '/app_client_edit/',
            'app/vehicles.html.twig' => '/app_vehicle_edit/',
            'app/vehicle_settings.html.twig' => '/app_vehicle_brand_edit/',
        ];

        foreach ($contexts as $template => $context) {
            $managerHtml = $this->renderTemplate($template, $context + [
                'user' => ['id' => 2, 'role' => 'manager', 'name' => 'Manager'],
            ]);
            $adminHtml = $this->renderTemplate($template, $context + [
                'user' => ['id' => 1, 'role' => 'admin', 'name' => 'Admin'],
            ]);

            self::assertStringContainsString('عرض', $managerHtml, $template);
            self::assertStringContainsString('تعديل', $managerHtml, $template);
            self::assertStringContainsString($expectedEditLinks[$template], $managerHtml, $template);
            self::assertStringContainsString('حفظ', $managerHtml, $template);
            self::assertStringNotContainsString('حذف', $managerHtml, $template);
            self::assertStringNotContainsString('تعطيل', $managerHtml, $template);
            self::assertStringContainsString('تعديل', $adminHtml, $template);
            self::assertStringContainsString('حذف', $adminHtml, $template);
        }

        self::assertNotNull($db->vehicle($vehicleId));
    }

    public function testManagerShowViewsExposeActiveEditButtons(): void
    {
        $db = $this->database();
        $categoryId = $this->id($db->pdo(), 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'SHOW-BTN',
            'name' => 'Produit fiche',
            'category_id' => $categoryId,
            'stock_qty' => 1,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 20,
        ], 1);
        $productId = $this->id($db->pdo(), 'SELECT id FROM products WHERE sku = "SHOW-BTN"');
        $db->saveSupplier(['name' => 'Supplier fiche']);
        $supplierId = $this->id($db->pdo(), 'SELECT id FROM suppliers WHERE name = "Supplier fiche"');
        [$clientId, $vehicleId] = $this->clientAndVehicle($db, $db->pdo());
        $brandId = $this->id($db->pdo(), 'SELECT id FROM vehicle_brands ORDER BY id LIMIT 1');
        $modelId = $this->id($db->pdo(), 'SELECT id FROM vehicle_models WHERE brand_id = ' . $brandId . ' ORDER BY id LIMIT 1');
        $manager = ['id' => 2, 'role' => 'manager', 'name' => 'Manager'];

        $views = [
            'app/product_show.html.twig' => [
                'context' => ['product' => $db->product($productId), 'movements' => $db->productMovements($productId)],
                'edit' => '/app_product_edit/' . $productId,
            ],
            'app/category_show.html.twig' => [
                'context' => ['category' => $db->category($categoryId), 'products' => $db->categoryProducts($categoryId)],
                'edit' => '/app_category_edit/' . $categoryId,
            ],
            'app/supplier_show.html.twig' => [
                'context' => ['supplier' => $db->supplier($supplierId), 'movements' => $db->supplierMovements($supplierId)],
                'edit' => '/app_supplier_edit/' . $supplierId,
            ],
            'app/client_show.html.twig' => [
                'context' => ['client' => $db->client($clientId), 'vehicles' => $db->clientVehicles($clientId), 'operations' => $db->clientOperations($clientId)],
                'edit' => '/app_client_edit/' . $clientId,
            ],
            'app/vehicle_show.html.twig' => [
                'context' => ['vehicle' => $db->vehicle($vehicleId), 'operations' => $db->vehicleOperations($vehicleId)],
                'edit' => '/app_vehicle_edit/' . $vehicleId,
            ],
            'app/vehicle_brand_show.html.twig' => [
                'context' => ['brand' => $db->vehicleBrand($brandId), 'models' => $db->brandModels($brandId)],
                'edit' => '/app_vehicle_brand_edit/' . $brandId,
            ],
            'app/vehicle_model_show.html.twig' => [
                'context' => ['model' => $db->vehicleModel($modelId)],
                'edit' => '/app_vehicle_model_edit/' . $modelId,
            ],
        ];

        foreach ($views as $template => $data) {
            $html = $this->renderTemplate($template, $data['context'] + ['user' => $manager]);

            self::assertStringContainsString('تعديل', $html, $template);
            self::assertStringContainsString($data['edit'], $html, $template);
        }
    }

    public function testAdminStockScreensExposeAdjustmentAndActiveButtons(): void
    {
        $db = $this->database();
        $categoryId = $this->id($db->pdo(), 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'ADMIN-STOCK',
            'name' => 'Admin stock',
            'category_id' => $categoryId,
            'stock_qty' => 12,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 20,
        ], 1);
        $productId = $this->id($db->pdo(), 'SELECT id FROM products WHERE sku = "ADMIN-STOCK"');
        $admin = ['id' => 1, 'role' => 'admin', 'name' => 'Admin'];
        $context = [
            'user' => $admin,
            'product' => $db->product($productId),
            'movements' => $db->productMovements($productId),
            'suppliers' => $db->suppliers(false),
            'stock_adjust_token' => 'token',
            'stock_movement_token' => 'token',
        ];

        $productHtml = $this->renderTemplate('app/product_show.html.twig', $context);
        $stockHtml = $this->renderTemplate('app/stock.html.twig', $db->dashboardData() + [
            'user' => $admin,
            'stock_adjust_token' => 'token',
            'stock_movement_token' => 'token',
        ]);
        $editHtml = $this->renderTemplate('app/product_edit.html.twig', [
            'user' => $admin,
            'product' => $db->product($productId),
            'categories' => $db->categories(false),
        ]);

        self::assertStringContainsString('ضبط المخزون', $productHtml);
        self::assertStringContainsString('/app_stock_movement_edit/', $productHtml);
        self::assertStringNotContainsString('aria-disabled="true"', $productHtml);
        self::assertStringContainsString('ضبط المخزون', $stockHtml);
        self::assertStringNotContainsString('aria-disabled="true"', $stockHtml);
        self::assertStringNotContainsString('name="stock_qty"', $editHtml);
    }

    public function testAdminStockAdjustmentCreatesTracedMovements(): void
    {
        $db = $this->database();
        $categoryId = $this->id($db->pdo(), 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'ADJUST-001',
            'name' => 'Produit ajustement',
            'category_id' => $categoryId,
            'stock_qty' => 12,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 20,
        ], 1);
        $productId = $this->id($db->pdo(), 'SELECT id FROM products WHERE sku = "ADJUST-001"');
        $beforeSummary = $db->getFinancialSummary(date('Y-m-d'), date('Y-m-d'));

        $delta = $db->adjustStock($productId, 15, 'inventaire', 1);
        self::assertSame(3, $delta);
        self::assertSame(15, (int) $db->product($productId)['stock_qty']);
        $movement = $db->pdo()->query('SELECT * FROM stock_movements WHERE product_id = ' . $productId . ' ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame('adjustment', $movement['movement_type']);
        self::assertSame(3, (int) $movement['quantity']);
        self::assertSame('inventaire', $movement['note']);
        self::assertSame(1, (int) $movement['created_by']);

        $delta = $db->adjustStock($productId, 10, 'casse', 1);
        self::assertSame(-5, $delta);
        self::assertSame(10, (int) $db->product($productId)['stock_qty']);

        $countBeforeNoChange = (int) $db->pdo()->query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ' . $productId)->fetchColumn();
        self::assertSame(0, $db->adjustStock($productId, 10, 'inventaire final', 1));
        self::assertSame($countBeforeNoChange, (int) $db->pdo()->query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ' . $productId)->fetchColumn());

        try {
            $db->adjustStock($productId, -1, 'invalid', 1);
            self::fail('Expected negative stock adjustment rejection.');
        } catch (InvalidArgumentException) {
            self::assertSame(10, (int) $db->product($productId)['stock_qty']);
        }

        $afterSummary = $db->getFinancialSummary(date('Y-m-d'), date('Y-m-d'));
        self::assertSame($beforeSummary['total_ttc'], $afterSummary['total_ttc']);
        self::assertSame($beforeSummary['total_margin'], $afterSummary['total_margin']);
    }

    public function testAdminCanEditAndDeleteStockMovementWithStockRecalculation(): void
    {
        $db = $this->database();
        $categoryId = $this->id($db->pdo(), 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'MOVE-EDIT',
            'name' => 'Movement edit',
            'category_id' => $categoryId,
            'stock_qty' => 12,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 20,
        ], 1);
        $productId = $this->id($db->pdo(), 'SELECT id FROM products WHERE sku = "MOVE-EDIT"');
        $movementId = $this->id($db->pdo(), 'SELECT id FROM stock_movements WHERE product_id = ' . $productId . ' ORDER BY id DESC LIMIT 1');

        $db->updateStockMovement($movementId, [
            'movement_type' => 'in',
            'quantity' => 15,
            'note' => 'correction inventaire',
            'supplier_id' => '',
            'unit_cost' => '',
        ], 1);
        self::assertSame(15, (int) $db->product($productId)['stock_qty']);

        try {
            $db->updateStockMovement($movementId, [
                'movement_type' => 'adjustment',
                'quantity' => -5,
                'note' => 'casse',
                'supplier_id' => '',
                'unit_cost' => '',
            ], 1);
            self::fail('Expected movement edit that makes stock negative to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertNotNull($db->stockMovement($movementId));
            self::assertSame(15, (int) $db->product($productId)['stock_qty']);
        }

        $db->updateStockMovement($movementId, [
            'movement_type' => 'in',
            'quantity' => 5,
            'note' => 'retour inventaire',
            'supplier_id' => '',
            'unit_cost' => '',
        ], 1);
        self::assertSame(5, (int) $db->product($productId)['stock_qty']);
        $db->deleteStockMovement($movementId);
        self::assertSame(0, (int) $db->product($productId)['stock_qty']);
        self::assertNull($db->stockMovement($movementId));
    }

    public function testManagerStockPageShowsDisabledStockActions(): void
    {
        $db = $this->database();
        $categoryId = $this->id($db->pdo(), 'SELECT id FROM categories ORDER BY id LIMIT 1');
        $db->saveProduct([
            'sku' => 'STOCK-ACL',
            'name' => 'Stock ACL',
            'category_id' => $categoryId,
            'stock_qty' => 4,
            'min_qty' => 1,
            'purchase_price' => 10,
            'sale_price' => 20,
        ], 1);

        $html = $this->renderTemplate('app/stock.html.twig', $db->dashboardData() + [
            'user' => ['id' => 2, 'role' => 'manager', 'name' => 'Manager'],
        ]);

        self::assertStringContainsString('STOCK-ACL', $html);
        self::assertStringContainsString('ضبط', $html);
        self::assertStringContainsString('aria-disabled="true"', $html);
        self::assertStringContainsString('المخزون مؤمن', $html);
        self::assertStringNotContainsString('ضبط المخزون', $html);
        self::assertStringNotContainsString('تعطيل', $html);

        $controller = new DashboardController();
        $access = new AccessControl();
        $productId = $this->id($db->pdo(), 'SELECT id FROM products WHERE sku = "STOCK-ACL"');
        $request = $this->requestWithUser('/stock/adjust', 'POST', [
            'product_id' => $productId,
            'real_quantity' => 99,
            'reason' => 'force',
        ], [
            'id' => 2,
            'role' => 'manager',
            'name' => 'Manager',
            'email' => 'manager@simauto.ma',
        ]);
        $controller->setContainer($this->controllerContainer($request));
        $response = $controller->stockAdjust($request, $db, $access);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(4, (int) $db->product($productId)['stock_qty']);

        $db->addStock($productId, 2, 'daily stock entry', 2);
        self::assertSame(6, (int) $db->product($productId)['stock_qty']);
    }

    public function testAdminCanDeleteRecordsButConfirmedDocumentsStayLocked(): void
    {
        $db = $this->database();
        $access = new AccessControl();
        $controller = new DashboardController();
        $admin = ['id' => 1, 'name' => 'Admin', 'email' => 'admin@simauto.ma', 'role' => 'admin'];
        $db->saveClient(['type' => 'individual', 'name' => 'Client deletable']);
        $clientId = $this->id($db->pdo(), 'SELECT id FROM clients WHERE name = "Client deletable"');
        $request = $this->requestWithUser('/records/client/' . $clientId . '/delete', 'POST', [], $admin);
        $csrf = new ReflectionMethod($controller, 'csrfToken');
        $csrf->setAccessible(true);
        $request->request->set('_token', $csrf->invoke($controller, $request, 'record_state'));
        $controller->setContainer($this->controllerContainer($request));

        $response = $controller->recordState('client', $clientId, 'delete', $request, $db, $access);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertNull($db->client($clientId));

        $invoiceId = $db->invoiceDocument($this->operationWithService($db, 'ESP'), 1);
        $invoiceHtml = $this->renderTemplate('app/operation_show.html.twig', [
            'user' => $admin,
            'operation' => $db->operation($invoiceId),
            'chain' => $db->operationDocumentChain($invoiceId),
            'back_query' => [],
            'record_token' => 'token',
        ]);

        try {
            $db->deleteRecord('operation', $invoiceId);
            self::fail('Expected confirmed document delete lock.');
        } catch (InvalidArgumentException $e) {
            self::assertNotNull($db->operation($invoiceId));
        }
        self::assertStringNotContainsString('/app_operation_edit/' . $invoiceId, $invoiceHtml);
        self::assertStringNotContainsString('/app_record_state/operation/' . $invoiceId . '/delete', $invoiceHtml);
    }

    public function testTopbarDropdownsAreClosedByDefaultAndManagerHasNoUsersEntry(): void
    {
        $template = file_get_contents(__DIR__ . '/../templates/app/_topbar.html.twig');
        $twig = new Environment(new ArrayLoader(['topbar' => $template]));
        $access = new AccessControl();
        $twig->addFunction(new TwigFunction('path', fn (string $route, array $params = []) => '/' . $route . (isset($params['locale']) ? '/' . $params['locale'] : '')));
        $twig->addFunction(new TwigFunction('asset', fn (string $path) => '/assets/' . $path));
        $twig->addFunction(new TwigFunction('can', fn (string $permission, array $user) => $access->can($permission, $user)));
        $twig->addFunction(new TwigFunction('can_edit_document', fn (array $user, array $operation) => $access->canEditDocument($user, $operation)));
        $twig->addFilter(new TwigFilter('trans', fn (string $key, array $params = []): string => $this->testTrans($key, $params)));
        $twig->addExtension(new MoneyExtension());

        $request = Request::create('/');
        $request->attributes->set('_route', 'app_dashboard');
        $adminHtml = $twig->render('topbar', ['app' => ['request' => $request], 'user' => ['role' => 'admin', 'name' => 'Admin']]);
        $managerHtml = $twig->render('topbar', ['app' => ['request' => $request], 'user' => ['role' => 'manager', 'name' => 'Manager']]);
        $css = file_get_contents(__DIR__ . '/../public/styles/app.css');

        self::assertStringContainsString('dropdown-menu', $adminHtml);
        self::assertStringNotContainsString('is-open', $adminHtml);
        self::assertStringContainsString('/app_users', $adminHtml);
        self::assertStringContainsString('/app_import', $adminHtml);
        self::assertStringContainsString('/app_vehicle_settings', $adminHtml);
        self::assertStringNotContainsString('/app_users', $managerHtml);
        self::assertStringNotContainsString('/app_import', $managerHtml);
        self::assertStringContainsString('/app_vehicle_settings', $managerHtml);
        self::assertStringNotContainsString('nav.admin', $managerHtml);
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
        $twig->addExtension(new MoneyExtension());
        $access = new AccessControl();
        $twig->addFunction(new TwigFunction('asset', fn (string $path) => '/assets/' . $path));
        $twig->addFunction(new TwigFunction('can', fn (string $permission, array $user) => $access->can($permission, $user)));
        $twig->addFunction(new TwigFunction('can_edit_document', fn (array $user, array $operation) => $access->canEditDocument($user, $operation)));
        $twig->addFilter(new TwigFilter('trans', fn (string $key, array $params = []): string => $this->testTrans($key, $params)));
        $twig->addFunction(new TwigFunction('path', function (string $route, array $params = []): string {
            $suffix = isset($params['id']) ? '/' . $params['id'] : (isset($params['locale']) ? '/' . $params['locale'] : '');
            return '/' . $route . $suffix;
        }));

        $context += [
            'app_name' => 'SIM Auto',
            'app' => new class {
                public Request $request;

                public function __construct()
                {
                    $this->request = Request::create('/');
                    $this->request->attributes->set('_route', 'app_dashboard');
                    $this->request->setLocale('ar');
                }

                public function flashes(string $type): array
                {
                    return [];
                }
            },
        ];

        return $twig->render($template, $context);
    }

    private function testTrans(string $key, array $params = []): string
    {
        $translations = [
            'dashboard.stock_alert_title' => '%count% produit(s) a reapprovisionner',
            'dashboard.stock_alert_product_link' => 'Fiche',
            'dashboard.stock_alert_restock_link' => 'Reappro',
            'dashboard.stock_healthy' => 'Stock sain',
            'dashboard.critical_stock' => 'Stock critique',
            'dashboard.products' => 'Produits',
            'dashboard.products_count' => '%count% produit(s)',
            'dashboard.stock' => 'Stock',
            'dashboard.stock_hint' => 'Suivi stock',
            'dashboard.operations' => 'Operations',
            'dashboard.operations_hint' => 'Operations',
            'dashboard.billing' => 'Factures',
            'dashboard.operations_count' => '%count% operation(s)',
            'stock.current' => 'Actuel',
            'stock.minimum' => 'Minimum',
            'stock.low' => 'Faible',
            'stock.empty' => 'Rupture',
            'clients.no_vehicles' => 'لا توجد سيارات لهذا العميل.',
            'ui.edit' => 'تعديل',
            'ui.delete' => 'حذف',
            'ui.deactivate' => 'تعطيل',
            'ui.activate' => 'تفعيل',
            'ui.show' => 'عرض',
            'ui.back' => 'رجوع',
            'ui.save' => 'حفظ',
            'ui.filter' => 'تصفية',
            'ui.search' => 'بحث',
            'ui.actions' => 'إجراءات',
            'ui.all' => 'الكل',
            'ui.code' => 'الكود',
            'ui.active_record' => 'حالة السجل',
            'ui.confirm_delete' => 'تأكيد الحذف؟',
            'access.admin_only' => 'محجوز للمدير',
            'access.document_locked' => 'مستند مؤكد',
            'access.stock_locked' => 'المخزون مؤمن',
            'operations.total_ttc' => 'المجموع TTC',
            'operations.edit_quote' => 'تعديل devis',
            'products.title' => 'المنتجات',
            'products.new' => 'منتج جديد',
            'products.name' => 'اسم المنتج',
            'products.type' => 'النوع',
            'products.stockable' => 'قطعة مخزون',
            'products.service' => 'خدمة',
            'products.category' => 'الصنف',
            'products.quantity' => 'الكمية',
            'products.min_qty' => 'الحد الأدنى',
            'products.purchase_price_ht' => 'ثمن الشراء HT',
            'products.sale_price_ht' => 'ثمن البيع HT',
            'products.margin' => 'هامش الربح',
            'products.manual' => 'يدوي',
            'products.product' => 'المنتج',
            'products.stock' => 'المخزون',
            'products.sku' => 'SKU / الاسم',
            'products.stock_state' => 'حالة المخزون',
            'products.low' => 'تحت الحد',
            'products.empty_stock' => 'نفد',
            'products.no_products' => 'لا توجد منتجات.',
            'products.movements' => 'حركات المخزون',
            'stock.adjust_title' => 'ضبط المخزون',
            'stock.adjust_short' => 'ضبط',
            'stock.adjust_submit' => 'تسجيل الضبط',
            'stock.real_quantity' => 'الكمية الحقيقية',
            'stock.adjust_reason' => 'سبب الضبط',
            'stock.adjust_reason_placeholder' => 'مثال: inventaire، casse، erreur de saisie',
            'stock.movement.in' => 'إدخال',
            'stock.movement.out' => 'خروج',
            'stock.movement.adjustment' => 'ضبط',
            'state.active' => 'نشط',
            'state.inactive' => 'غير نشط',
        ];

        return strtr($translations[$key] ?? $key, $params);
    }

    private function requestWithUser(string $uri, string $method, array $parameters, array $user): Request
    {
        $request = Request::create($uri, $method, $parameters);
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', $user);
        $request->setSession($session);

        return $request;
    }

    private function controllerContainer(Request $request): Container
    {
        $container = new Container();
        $stack = new RequestStack();
        $stack->push($request);
        $container->set('request_stack', $stack);
        $container->set('router', new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                $suffix = '';
                foreach ($parameters as $value) {
                    $suffix .= '/' . $value;
                }

                return '/' . $name . $suffix;
            }
        });

        return $container;
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
