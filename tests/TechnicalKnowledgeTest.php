<?php

namespace App\Tests;

use App\Command\StockCheckCommand;
use App\Controller\DashboardController;
use App\Service\AccessControl;
use App\Service\AppDatabase;
use App\Service\CompanyProfile;
use App\Service\TechnicalKnowledge;
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
        self::assertCount(1, $groups);
        self::assertSame(['Alpha', 'Beta'], $groups[0]['models']);
        self::assertSame(3, $groups[0]['uses']);
        self::assertSame(2, $groups[0]['products'][0]['uses']);
        self::assertSame(6.0, $groups[0]['products'][0]['quantity']);
        self::assertSame('INT-OIL', $groups[0]['products'][0]['reference']);
        self::assertSame('Vidange <test>', $groups[0]['services'][0]['name']);
        self::assertCount(3, $groups[0]['operations']);
        $this->knowledge->options();
        self::assertSame($before, $this->db->pdo()->query('SELECT total_changes()')->fetchColumn(), 'Consultation must never write.');
        $tester = new CommandTester(new StockCheckCommand($this->db));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
    }

    public function testCombinedFiltersInclusiveDatesLiteralSearchAndInvalidDates(): void
    {
        self::assertSame(['Beta'], array_column($this->knowledge->search(['model' => 'Beta']), 'model'));
        self::assertSame(['Alpha'], array_column($this->knowledge->search(['q' => '5w30']), 'model'));
        self::assertSame(['Beta'], array_column($this->knowledge->search(['q' => 'FILTRE A AIR']), 'model'));
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
        self::assertCount(1, $this->knowledge->search());
        self::assertContains('Alpha', $this->knowledge->options()['models']);
        $this->db->pdo()->prepare("UPDATE operations SET vehicle_brand = '', vehicle_model = '' WHERE id = :id")->execute(['id' => $id]);
        self::assertCount(2, $this->knowledge->search());
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
        foreach (['window.print()', 'SIM Auto', 'Base technique par marque', 'Pièces et produits', 'Alpha', '5W30', '2026-09-11', 'K-TWO', 'INT-OIL', '<td>3</td>'] as $value) self::assertStringContainsString($value, $html);
        self::assertStringNotContainsString('Beta', $html);
        self::assertTrue((new AccessControl())->can('view.knowledge', ['role' => 'manager']));
        self::assertFalse((new AccessControl())->can('knowledge.export', ['role' => 'manager']));
        [$controller, $request] = $this->controller('/knowledge/export/pdf', 2);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $controller->knowledgeExportPdf($request, $this->db, new AccessControl(), $this->knowledge, new CompanyProfile());
    }

    public function testHyundaiAccentTextFallbackUnicodeBrandAndModelOptionalAcrossDocumentTypes(): void
    {
        $this->db->saveVehicleBrand('Hyundai');
        $this->db->saveVehicleBrand('Mercedes');
        $id = $this->invoices[0];
        $this->db->pdo()->prepare("UPDATE operations SET vehicle_id = NULL, vehicle_brand = :brand, vehicle_model = 'Accent', client_name = 'Ounaceur Abdessamad' WHERE id = :id")
            ->execute(['brand' => " \u{00A0}HYUNDAI\t ", 'id' => $id]);
        // A still-uninvoiced order and a quote with brand alone must both be discoverable.
        $this->db->pdo()->prepare("UPDATE operations SET vehicle_id = NULL, vehicle_brand = '  Hyundai  ', vehicle_model = '', doc_type = 'order' WHERE id = :id")->execute(['id' => $this->invoices[1]]);
        $this->db->pdo()->prepare("UPDATE operations SET vehicle_id = NULL, vehicle_brand = 'MÉRCEDES', vehicle_model = '', doc_type = 'quote' WHERE id = :id")->execute(['id' => $this->invoices[2]]);
        $groups = $this->knowledge->search(['brand' => '  hyundai ']);
        self::assertCount(1, $groups);
        self::assertSame('Hyundai', $groups[0]['brand']);
        self::assertSame(2, $groups[0]['uses']);
        self::assertSame(6.0, $groups[0]['products'][0]['quantity']);
        self::assertSame('Ounaceur Abdessamad', array_values(array_filter($groups[0]['operations'], fn ($op) => $op['id'] === $id))[0]['client']);
        self::assertCount(1, $this->knowledge->search(['brand' => 'HYUNDAI', 'model' => ' accent ']));
        self::assertSame('Mercedes', $this->knowledge->search(['brand' => 'mercedes'])[0]['brand']);
        self::assertContains('Hyundai', $this->knowledge->options()['brands']);
        self::assertContains('Mercedes', $this->knowledge->options()['brands']);
        self::assertSame(1, count(array_filter($this->knowledge->options()['brands'], fn ($b) => TechnicalKnowledge::normalize($b) === 'hyundai')));
        [$controller, $request] = $this->controller('/knowledge?brand=hyundai', 2);
        $html = $controller->knowledge($request, $this->db, new AccessControl(), $this->knowledge)->getContent();
        self::assertStringContainsString('Ounaceur Abdessamad', $html);
        self::assertStringContainsString('value="Hyundai" selected', $html);
        self::assertStringContainsString('Accent', $html);
    }

    public function testDocumentChainIsCountedOnceAndDraftOnlyPartsAreClearlyIdentified(): void
    {
        $source = $this->db->operation($this->invoices[0]);
        $payload = ['client_id' => $source['client_id'], 'vehicle_id' => $source['vehicle_id'], 'product_1' => $source['items'][0]['product_id'], 'product_qty_1' => 2];
        $quote = $this->db->createOperation($payload, 1);
        $groups = $this->knowledge->search(['plate' => 'K-ONE']);
        self::assertSame(2, $groups[0]['uses']);
        self::assertContains('quote', array_column($groups[0]['operations'], 'doc_type'));
        [$controller, $request] = $this->controller('/knowledge?plate=K-ONE', 1);
        self::assertStringContainsString('utilisation non confirmée', $controller->knowledge($request, $this->db, new AccessControl(), $this->knowledge)->getContent());
        $order = $this->db->confirmQuote($quote, 1);
        self::assertSame(2, $this->knowledge->search(['plate' => 'K-ONE'])[0]['uses']);
        $invoice = $this->db->invoiceDocument($order, 1);
        $groups = $this->knowledge->search(['plate' => 'K-ONE']);
        self::assertSame(2, $groups[0]['uses']);
        self::assertSame(5.0, $groups[0]['products'][0]['quantity']);
        self::assertContains($invoice, array_column($groups[0]['operations'], 'id'));
        self::assertNotContains($quote, array_column($groups[0]['operations'], 'id'));
        self::assertNotContains($order, array_column($groups[0]['operations'], 'id'));
    }

    public function testManagerCreatesOnlyAnOrderAndInvoicingDoesNotDecrementTwice(): void
    {
        $source = $this->db->operation($this->invoices[0]);
        $product = $source['items'][0]['product_id'];
        $stock = (int) $this->db->product($product)['stock_qty'];
        $count = (int) $this->db->pdo()->query('SELECT count(*) FROM operations')->fetchColumn();
        $quotes = (int) $this->db->pdo()->query("SELECT count(*) FROM operations WHERE doc_type = 'quote'")->fetchColumn();
        [$controller, $request] = $this->controller('/operations/new', 2, 'fr', 'POST', $this->orderPayload($source, [2, 3]));
        $controller->newOperation($request, $this->db, new AccessControl());
        $id = (int) $this->db->pdo()->query('SELECT max(id) FROM operations')->fetchColumn();
        $order = $this->db->operation($id);
        self::assertSame('order', $order['doc_type']);
        self::assertSame('confirmed', $order['status']);
        self::assertNull($order['parent_id']);
        self::assertNull($order['quote_no']);
        self::assertSame($order['order_no'], $order['document_no']);
        self::assertNotEmpty($order['stock_decremented_at']);
        self::assertSame($count + 1, (int) $this->db->pdo()->query('SELECT count(*) FROM operations')->fetchColumn());
        self::assertSame($quotes, (int) $this->db->pdo()->query("SELECT count(*) FROM operations WHERE doc_type = 'quote'")->fetchColumn());
        self::assertSame($stock - 5, (int) $this->db->product($product)['stock_qty']);
        self::assertSame(5, (int) $this->db->pdo()->query('SELECT sum(quantity) FROM stock_movements WHERE operation_id = ' . $id . " AND movement_type = 'out'")->fetchColumn());
        $log = $this->db->pdo()->query("SELECT * FROM activity_logs WHERE action_type = 'operation_create' ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame($id, (int) $log['entity_id']);
        self::assertStringContainsString('Bon de commande créé', $log['description']);
        [$controller, $request] = $this->controller('/operations/' . $id . '/invoice', 2, 'fr', 'POST');
        $controller->invoiceOperation($id, $request, $this->db, new AccessControl());
        self::assertSame($stock - 5, (int) $this->db->product($product)['stock_qty']);
        self::assertSame([], $this->db->stockConsistencyIssues());
    }

    public function testManagerCanStillResetDirectOrderButOnlyAdminCanEditItsDraft(): void
    {
        $source = $this->db->operation($this->invoices[0]);
        $product = $source['items'][0]['product_id'];
        $stock = (int) $this->db->product($product)['stock_qty'];
        $order = $this->db->createPurchaseOrder($this->orderPayload($source, [2]), 2);
        [$controller, $request] = $this->controller('/operations/' . $order . '/reset-draft', 2, 'fr', 'POST');
        $controller->resetOrderDraft($order, $request, $this->db, new AccessControl());
        $draft = $this->db->operation($order);
        self::assertSame('quote', $draft['doc_type']);
        self::assertSame('draft', $draft['status']);
        self::assertSame($stock, (int) $this->db->product($product)['stock_qty']);
        self::assertFalse((new AccessControl())->canEditDocument(['role' => 'manager'], $draft));
        self::assertTrue((new AccessControl())->canEditDocument(['role' => 'admin'], $draft));
        [$controller, $request] = $this->controller('/operations/' . $order . '/confirm', 2, 'fr', 'POST');
        $controller->confirmOperation($order, $request, $this->db, new AccessControl());
        self::assertSame($stock - 2, (int) $this->db->product($product)['stock_qty'], implode(', ', $request->getSession()->getFlashBag()->get('error')));
        $receipts = $this->db->pdo()->query('SELECT receipt_no FROM operations')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(count($receipts), count(array_unique($receipts)));
        self::assertSame([], $this->db->stockConsistencyIssues());
    }

    public function testManagerOrderFailureRollsBackEverythingIncludingAggregatedStock(): void
    {
        $source = $this->db->operation($this->invoices[0]);
        $stock = (int) $this->db->product($source['items'][0]['product_id'])['stock_qty'];
        foreach ([$this->orderPayload($source, [$stock, 1]), $this->orderPayload($source, [1]) + ['simulate_failure' => '1']] as $payload) {
            $before = $this->snapshot();
            [$controller, $request] = $this->controller('/operations/new', 2, 'fr', 'POST', $payload);
            $response = $controller->newOperation($request, $this->db, new AccessControl());
            self::assertSame('/operations', $response->headers->get('Location'));
            self::assertSame($before, $this->snapshot());
        }
        $before = $this->snapshot();
        [$controller, $request] = $this->controller('/operations/new', 2, 'fr', 'POST', $this->orderPayload($source, [1]));
        $request->request->remove('_token');
        $controller->newOperation($request, $this->db, new AccessControl());
        self::assertSame($before, $this->snapshot());
        // A failure after stock has been updated must roll back both documents and movements.
        $this->db->pdo()->exec("CREATE TRIGGER reject_order_stock BEFORE INSERT ON stock_movements WHEN NEW.movement_type = 'out' BEGIN SELECT RAISE(ABORT, 'stock failure'); END");
        $before = $this->snapshot();
        [$controller, $request] = $this->controller('/operations/new', 2, 'fr', 'POST', $this->orderPayload($source, [1]));
        $controller->newOperation($request, $this->db, new AccessControl());
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $this->db->stockConsistencyIssues());
    }

    public function testManagerCannotForceQuoteCreationOrEditingAndAdminKeepsFullCycle(): void
    {
        $source = $this->db->operation($this->invoices[0]);
        $payload = $this->orderPayload($source, [1]);
        $before = $this->snapshot();
        [$controller, $request] = $this->controller('/operations/new', 2, 'fr', 'POST', $payload + ['doc_type' => 'quote']);
        $request->getSession()->set('user', ['id' => 2, 'role' => 'admin']);
        try {
            $controller->newOperation($request, $this->db, new AccessControl());
            self::fail('A manager cannot force creation of a quote.');
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
            self::assertSame($before, $this->snapshot());
        }
        [$controller, $request] = $this->controller('/operations/new', 1, 'fr', 'POST', $payload);
        $controller->newOperation($request, $this->db, new AccessControl());
        $quote = (int) $this->db->pdo()->query('SELECT max(id) FROM operations')->fetchColumn();
        self::assertSame('quote', $this->db->operation($quote)['doc_type']);
        foreach (['GET', 'POST'] as $method) {
            $before = $this->snapshot();
            [$controller, $request] = $this->controller('/operations/' . $quote . '/edit', 2, 'fr', $method, $payload);
            $response = $controller->operationEdit($quote, $request, $this->db, new AccessControl());
            self::assertSame('/operations/' . $quote, $response->headers->get('Location'));
            self::assertSame($before, $this->snapshot());
        }
        [$controller, $request] = $this->controller('/operations/' . $quote . '/edit', 1, 'fr', 'POST', $this->orderPayload($source, [2]));
        $controller->operationEdit($quote, $request, $this->db, new AccessControl());
        self::assertSame(2, (int) $this->db->operation($quote)['items'][0]['quantity']);
        [$controller, $request] = $this->controller('/operations/' . $quote . '/confirm', 1, 'fr', 'POST');
        $controller->confirmOperation($quote, $request, $this->db, new AccessControl());
        $order = (int) $this->db->pdo()->query('SELECT max(id) FROM operations')->fetchColumn();
        [$controller, $request] = $this->controller('/operations/' . $order . '/invoice', 1, 'fr', 'POST');
        $controller->invoiceOperation($order, $request, $this->db, new AccessControl());
        self::assertSame('invoice', $this->db->operation((int) $this->db->pdo()->query('SELECT max(id) FROM operations')->fetchColumn())['doc_type']);
        self::assertSame([], $this->db->stockConsistencyIssues());
    }

    public function testHistoryDefaultsHideQuotesAndManagerCannotForceQuoteOrAllFilter(): void
    {
        self::assertNotContains('quote', array_column($this->db->searchOperations([])['rows'], 'doc_type'));
        self::assertContains('quote', array_column($this->db->searchOperations(['doc_type' => 'all'])['rows'], 'doc_type'));
        foreach ([1, 2] as $userId) {
            foreach (['', 'quote', 'all', 'order', 'invoice'] as $type) {
                [$controller, $request] = $this->controller('/operations/history?doc_type=' . $type, $userId);
                $response = $controller->operationsHistory($request, $this->db, new AccessControl());
                $html = $response->getContent();
                self::assertSame(200, $response->getStatusCode());
                $showQuotes = $userId === 1 && in_array($type, ['quote', 'all'], true);
                $quote = $this->db->pdo()->query("SELECT invoice_no FROM operations WHERE doc_type = 'quote' LIMIT 1")->fetchColumn();
                if ($showQuotes) self::assertStringContainsString($quote, $html);
                else self::assertStringNotContainsString($quote, $html);
                if ($userId === 2) self::assertStringNotContainsString('<option value="quote"', $html);
            }
            [$controller, $request] = $this->controller('/operations', $userId);
            $html = $controller->operations($request, $this->db)->getContent();
            self::assertStringContainsString('name="doc_type" value="' . ($userId === 1 ? 'quote' : 'order') . '"', $html);
            [$controller, $request] = $this->controller('/billing', $userId);
            $html = $controller->billing($request, $this->db)->getContent();
            self::assertStringNotContainsString($quote, $html);
        }
        $quote = (int) $this->db->pdo()->query("SELECT id FROM operations WHERE doc_type = 'quote' LIMIT 1")->fetchColumn();
        [$controller, $request] = $this->controller('/operations/' . $quote, 2);
        self::assertSame(200, $controller->operationShow($quote, $request, $this->db, new AccessControl())->getStatusCode());
    }

    private function orderPayload(array $source, array $quantities): array
    {
        return ['client_id' => $source['client_id'], 'vehicle_id' => $source['vehicle_id'],
            'line_type' => array_fill(0, count($quantities), 'product'),
            'line_product_id' => array_fill(0, count($quantities), $source['items'][0]['product_id']),
            'line_label' => array_fill(0, count($quantities), ''),
            'line_quantity' => $quantities, 'line_unit_price' => array_fill(0, count($quantities), 40),
            'line_margin_mode' => array_fill(0, count($quantities), 'manual'), 'line_discount' => array_fill(0, count($quantities), 0)];
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['operations', 'operation_items', 'products', 'stock_movements', 'activity_logs'] as $table) {
            $snapshot[$table] = $this->db->pdo()->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
        }
        return $snapshot;
    }

    private function controller(string $uri, int $userId, string $locale = 'fr', string $method = 'GET', array $payload = []): array
    {
        $request = Request::create($uri, $method, $payload);
        $request->setLocale($locale);
        $request->attributes->set('_route', str_contains($uri, '/export/') ? 'app_knowledge_export_pdf' : 'app_knowledge');
        $request->setSession(new Session(new MockArraySessionStorage()));
        if ($userId) $request->getSession()->set('user', ['id' => $userId]);
        $request->getSession()->set('csrf_tokens', ['operation_form' => 'valid-token', 'operation_action' => 'valid-token']);
        if ($method === 'POST') $request->request->set('_token', 'valid-token');
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
        $twig->addFunction(new TwigFunction('can_edit_document', fn (array $user, array $operation) => (new AccessControl())->canEditDocument($user, $operation)));
        $twig->addExtension(new MoneyExtension());
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
