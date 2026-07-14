<?php

namespace App\Service;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

class AppDatabase
{
    private PDO $pdo;

    public function __construct(string $projectDir, ?string $databasePath = null)
    {
        $dir = $projectDir . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . ($databasePath ?: $dir . '/simauto.sqlite'));
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->migrate();
        $this->seed();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function userByEmail(string $email, bool $activeOnly = false): ?array
    {
        $sql = 'SELECT * FROM users WHERE email = :email';
        if ($activeOnly) {
            $sql .= ' AND active = 1';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['email' => mb_strtolower(trim($email))]);
        return $stmt->fetch() ?: null;
    }

    public function userById(int $id): ?array
    {
        return $this->row('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    public function isPasswordValid(array $user, string $password): bool
    {
        $hash = (string) ($user['password'] ?? '');
        return password_verify($password, $hash) || hash_equals($hash, $password);
    }

    public function rehashUserPassword(int $id, string $password): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'password' => password_hash($password, PASSWORD_BCRYPT),
        ]);
    }

    public function dashboardData(array $filters = []): array
    {
        $products = $this->products($filters);
        $operations = $this->operations();
        $todayTotal = (float) $this->pdo->query("SELECT COALESCE(SUM(total_ttc), 0) FROM operations WHERE doc_type = 'invoice' AND date(created_at) = date('now')")->fetchColumn();
        $lowStock = (int) $this->pdo->query("SELECT COUNT(*) FROM products WHERE active = 1 AND product_type = 'stockable' AND stock_qty <= min_qty")->fetchColumn();
        $operationCount = (int) $this->pdo->query("SELECT COUNT(*) FROM operations WHERE doc_type IN ('quote', 'order', 'invoice')")->fetchColumn();

        return [
            'products' => $products,
            'operations' => $operations,
            'categories' => $this->categories(false),
            'suppliers' => $this->suppliers(false),
            'clients' => $this->clients(),
            'brands' => $this->vehicleBrands(),
            'models' => $this->vehicleModels(),
            'vehicles' => $this->vehicles(),
            'metrics' => [
                ['label' => 'إدخال المنتجات', 'value' => count($products), 'detail' => 'منتجات نشطة', 'tone' => 'yellow'],
                ['label' => 'حالة المخزون', 'value' => $lowStock, 'detail' => 'تحت الحد الأدنى', 'tone' => 'black'],
                ['label' => 'عمليات المرآب', 'value' => $operationCount, 'detail' => 'إصلاحات وخدمات', 'tone' => 'red'],
                ['label' => 'الفواتير والإيصالات', 'value' => number_format($todayTotal, 0, ',', ' ') . ' DH', 'detail' => 'مبيعات اليوم', 'tone' => 'gold'],
            ],
        ];
    }

    public function categories(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM categories';
        if ($activeOnly) {
            $sql .= ' WHERE active = 1';
        }
        return $this->pdo->query($sql . ' ORDER BY name')->fetchAll();
    }

    public function category(int $id): ?array
    {
        return $this->row('SELECT * FROM categories WHERE id = :id', ['id' => $id]);
    }

    public function categoryByName(string $name): ?array
    {
        return $this->row(
            'SELECT * FROM categories WHERE lower(trim(name)) = lower(trim(:name))',
            ['name' => $name]
        );
    }

    public function getOrCreateCategory(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('الصنف إجباري');
        }
        $category = $this->categoryByName($name);
        if ($category) {
            return (int) $category['id'];
        }
        $this->saveCategory(['name' => $name]);
        return (int) $this->categoryByName($name)['id'];
    }

    public function saveCategory(array $data, ?int $id = null): void
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('اسم الصنف إجباري');
        }

        if ($id) {
            $stmt = $this->pdo->prepare('UPDATE categories SET name = :name WHERE id = :id');
            $stmt->execute(['name' => $name, 'id' => $id]);
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO categories (name, active, created_at) VALUES (:name, 1, datetime("now"))');
        $stmt->execute(['name' => $name]);
    }

    public function deactivateCategory(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE categories SET active = 0 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function suppliers(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM suppliers';
        if ($activeOnly) {
            $sql .= ' WHERE active = 1';
        }
        return $this->pdo->query($sql . ' ORDER BY name')->fetchAll();
    }

    public function supplier(int $id): ?array
    {
        return $this->row('SELECT * FROM suppliers WHERE id = :id', ['id' => $id]);
    }

    public function supplierByName(string $name): ?array
    {
        return $this->row('SELECT * FROM suppliers WHERE lower(trim(name)) = lower(trim(:name))', ['name' => $name]);
    }

    public function saveSupplier(array $data, ?int $id = null): void
    {
        $payload = [
            'name' => trim((string) ($data['name'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'address' => trim((string) ($data['address'] ?? '')),
            'ice' => trim((string) ($data['ice'] ?? '')),
        ];
        if ($payload['name'] === '') {
            throw new InvalidArgumentException('اسم المورد إجباري');
        }

        if ($id) {
            $payload['id'] = $id;
            $stmt = $this->pdo->prepare('UPDATE suppliers SET name=:name, phone=:phone, email=:email, address=:address, ice=:ice WHERE id=:id');
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO suppliers (name, phone, email, address, ice, active, created_at) VALUES (:name, :phone, :email, :address, :ice, 1, datetime("now"))');
        }
        $stmt->execute($payload);
    }

    public function deactivateSupplier(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE suppliers SET active = 0 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function clients(string $state = 'active'): array
    {
        $where = $this->stateWhere($state);
        return $this->pdo->query('SELECT * FROM clients' . $where . ' ORDER BY name')->fetchAll();
    }

    public function client(int $id): ?array
    {
        return $this->row('SELECT * FROM clients WHERE id = :id', ['id' => $id]);
    }

    public function clientByPhoneOrEmail(string $value): ?array
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        return $this->row(
            'SELECT * FROM clients WHERE lower(trim(email)) = :value OR trim(phone) = :value ORDER BY id LIMIT 1',
            ['value' => $value]
        );
    }

    public function saveClient(array $data, ?int $id = null): void
    {
        $type = ($data['type'] ?? 'individual') === 'company' ? 'company' : 'individual';
        $payload = [
            'type' => $type,
            'name' => trim((string) ($data['name'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'address' => trim((string) ($data['address'] ?? '')),
            'ice' => $type === 'company' ? trim((string) ($data['ice'] ?? '')) : '',
            'vat' => $type === 'company' ? trim((string) ($data['vat'] ?? '')) : '',
            'rc' => $type === 'company' ? trim((string) ($data['rc'] ?? '')) : '',
        ];
        if ($payload['name'] === '') {
            throw new InvalidArgumentException('اسم العميل إجباري');
        }

        if ($id) {
            $payload['id'] = $id;
            $stmt = $this->pdo->prepare('UPDATE clients SET type=:type, name=:name, phone=:phone, email=:email, address=:address, ice=:ice, vat=:vat, rc=:rc WHERE id=:id');
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO clients (type, name, phone, email, address, ice, vat, rc, created_at) VALUES (:type, :name, :phone, :email, :address, :ice, :vat, :rc, datetime("now"))');
        }
        $stmt->execute($payload);
    }

    public function vehicleBrands(string $state = 'active'): array
    {
        $where = $this->stateWhere($state);
        return $this->pdo->query('SELECT * FROM vehicle_brands' . $where . ' ORDER BY name')->fetchAll();
    }

    public function vehicleBrandByName(string $name): ?array
    {
        return $this->row('SELECT * FROM vehicle_brands WHERE lower(trim(name)) = lower(trim(:name))', ['name' => $name]);
    }

    public function vehicleModels(?int $brandId = null, string $state = 'active'): array
    {
        $activeClause = $state === 'all' ? '' : ' AND vm.active = ' . ($state === 'inactive' ? '0' : '1');
        if ($brandId) {
            $stmt = $this->pdo->prepare('SELECT * FROM vehicle_models vm WHERE brand_id = :brand_id' . $activeClause . ' ORDER BY name');
            $stmt->execute(['brand_id' => $brandId]);
            return $stmt->fetchAll();
        }
        return $this->pdo->query('SELECT vm.*, vb.name AS brand_name FROM vehicle_models vm JOIN vehicle_brands vb ON vb.id = vm.brand_id WHERE 1=1' . $activeClause . ' ORDER BY vb.name, vm.name')->fetchAll();
    }

    public function saveVehicleBrand(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('اسم الماركة إجباري');
        }
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO vehicle_brands (name) VALUES (:name)');
        $stmt->execute(['name' => $name]);
    }

    public function getOrCreateVehicleBrand(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('اسم الماركة إجباري');
        }
        $brand = $this->vehicleBrandByName($name);
        if ($brand) {
            return (int) $brand['id'];
        }
        $this->saveVehicleBrand($name);
        return (int) $this->vehicleBrandByName($name)['id'];
    }

    public function saveVehicleModel(int $brandId, string $name): void
    {
        $name = trim($name);
        if ($brandId <= 0 || $name === '') {
            throw new InvalidArgumentException('الماركة والموديل إجباريان');
        }
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO vehicle_models (brand_id, name) VALUES (:brand_id, :name)');
        $stmt->execute(['brand_id' => $brandId, 'name' => $name]);
    }

    public function vehicleModelByName(int $brandId, string $name): ?array
    {
        return $this->row(
            'SELECT * FROM vehicle_models WHERE brand_id = :brand_id AND lower(trim(name)) = lower(trim(:name))',
            ['brand_id' => $brandId, 'name' => $name]
        );
    }

    public function getOrCreateVehicleModel(int $brandId, string $name): int
    {
        $name = trim($name);
        if ($brandId <= 0 || $name === '') {
            throw new InvalidArgumentException('الماركة والموديل إجباريان');
        }
        $model = $this->vehicleModelByName($brandId, $name);
        if ($model) {
            return (int) $model['id'];
        }
        $this->saveVehicleModel($brandId, $name);
        return (int) $this->vehicleModelByName($brandId, $name)['id'];
    }

    public function vehicles(string $state = 'active'): array
    {
        $where = $this->stateWhere($state, 'v');
        return $this->pdo->query(
            'SELECT v.*, c.name AS client_name, vb.name AS brand_name, vm.name AS model_name
             FROM vehicles v
             JOIN clients c ON c.id = v.client_id
             JOIN vehicle_brands vb ON vb.id = v.brand_id
             JOIN vehicle_models vm ON vm.id = v.model_id
             ' . $where . '
             ORDER BY v.id DESC'
        )->fetchAll();
    }

    public function vehicleByPlate(string $plate): ?array
    {
        return $this->row('SELECT * FROM vehicles WHERE lower(trim(plate)) = lower(trim(:plate)) ORDER BY id LIMIT 1', ['plate' => $plate]);
    }

    public function saveVehicle(array $data, ?int $id = null): void
    {
        $payload = [
            'client_id' => (int) ($data['client_id'] ?? 0),
            'plate' => trim((string) ($data['plate'] ?? '')),
            'brand_id' => (int) ($data['brand_id'] ?? 0),
            'model_id' => (int) ($data['model_id'] ?? 0),
            'year' => (int) ($data['year'] ?? 0) ?: null,
            'mileage' => (int) ($data['mileage'] ?? 0) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')),
        ];
        if ($payload['client_id'] <= 0 || $payload['plate'] === '' || $payload['brand_id'] <= 0 || $payload['model_id'] <= 0) {
            throw new InvalidArgumentException('العميل والترقيم والماركة والموديل إجبارية');
        }

        if ($id) {
            $payload['id'] = $id;
            $stmt = $this->pdo->prepare('UPDATE vehicles SET client_id=:client_id, plate=:plate, brand_id=:brand_id, model_id=:model_id, year=:year, mileage=:mileage, notes=:notes WHERE id=:id');
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO vehicles (client_id, plate, brand_id, model_id, year, mileage, notes, created_at) VALUES (:client_id, :plate, :brand_id, :model_id, :year, :mileage, :notes, datetime("now"))');
        }
        $stmt->execute($payload);
    }

    public function products(array $filters = []): array
    {
        $state = (string) ($filters['state'] ?? 'active');
        $where = [$state === 'inactive' ? 'p.active = 0' : ($state === 'all' ? '1 = 1' : 'p.active = 1')];
        $params = [];

        if (($filters['q'] ?? '') !== '') {
            $where[] = '(p.name LIKE :q OR p.sku LIKE :q)';
            $params['q'] = '%' . trim($filters['q']) . '%';
        }
        if ((int) ($filters['category_id'] ?? 0) > 0) {
            $where[] = 'p.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }
        if (($filters['stock_state'] ?? '') === 'low') {
            $where[] = 'p.stock_qty <= p.min_qty AND p.stock_qty > 0';
        }
        if (($filters['stock_state'] ?? '') === 'empty') {
            $where[] = 'p.stock_qty <= 0';
        }

        $sql = 'SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function product(int $id): ?array
    {
        return $this->row('SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = :id', ['id' => $id]);
    }

    public function productBySku(string $sku): ?array
    {
        return $this->row(
            'SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE lower(trim(p.sku)) = lower(trim(:sku))',
            ['sku' => $sku]
        );
    }

    public function productMovements(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sm.*, s.name AS supplier_name
             FROM stock_movements sm
             LEFT JOIN suppliers s ON s.id = sm.supplier_id
             WHERE sm.product_id = :product_id
             ORDER BY sm.id DESC'
        );
        $stmt->execute(['product_id' => $productId]);
        return $stmt->fetchAll();
    }

    public function saveProduct(array $data, int $userId, ?int $id = null): void
    {
        $payload = $this->validateProduct($data, $id);

        if ($id) {
            $payload['id'] = $id;
            $stmt = $this->pdo->prepare(
                'UPDATE products SET sku=:sku, name=:name, category_id=:category_id, category=:category, min_qty=:min_qty, purchase_price=:purchase_price, sale_price=:sale_price, product_type=:product_type, margin_rate=:margin_rate WHERE id=:id'
            );
            $stmt->execute($payload);
            return;
        }

        $payload['stock_qty'] = $payload['product_type'] === 'service' ? 0 : (int) ($data['stock_qty'] ?? 0);
        if ($payload['stock_qty'] < 0) {
            throw new InvalidArgumentException('الكمية يجب أن تكون 0 أو أكثر');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO products (sku, name, category, category_id, stock_qty, min_qty, purchase_price, sale_price, product_type, margin_rate, active, created_at)
                 VALUES (:sku, :name, :category, :category_id, :stock_qty, :min_qty, :purchase_price, :sale_price, :product_type, :margin_rate, 1, datetime("now"))'
            );
            $stmt->execute($payload);
            $productId = (int) $this->pdo->lastInsertId();
            if ($payload['product_type'] === 'stockable' && $payload['stock_qty'] > 0) {
                $this->addMovement($productId, 'in', $payload['stock_qty'], 'رصيد البداية', $userId, null, $payload['purchase_price']);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function deactivateProduct(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE products SET active = 0 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function reactivate(string $entity, int $id): void
    {
        $table = $this->entityTable($entity);
        $stmt = $this->pdo->prepare('UPDATE ' . $table . ' SET active = 1 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function deactivate(string $entity, int $id): void
    {
        $table = $this->entityTable($entity);
        $stmt = $this->pdo->prepare('UPDATE ' . $table . ' SET active = 0 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function deleteRecord(string $entity, int $id): void
    {
        $table = $this->entityTable($entity);
        $refs = $this->referenceCounts($entity, $id);
        $blocking = array_filter($refs, fn (int $count): bool => $count > 0);
        if ($blocking) {
            $parts = [];
            foreach ($blocking as $label => $count) {
                $parts[] = $count . ' ' . $label;
            }
            throw new InvalidArgumentException('لا يمكن الحذف: ' . implode('، ', $parts));
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function addStock(int $productId, int $quantity, string $note, int $userId, ?int $supplierId = null, ?float $unitCost = null): void
    {
        if ($productId <= 0 || $quantity <= 0) {
            throw new InvalidArgumentException('المنتج والكمية إجبارية');
        }
        if ($unitCost !== null && $unitCost < 0) {
            throw new InvalidArgumentException('ثمن الشراء يجب أن يكون 0 أو أكثر');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("UPDATE products SET stock_qty = stock_qty + :qty WHERE id = :id AND active = 1 AND product_type = 'stockable'");
            $stmt->execute(['qty' => $quantity, 'id' => $productId]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('المنتج غير موجود');
            }
            $this->addMovement($productId, 'in', $quantity, $note ?: 'إدخال مخزون', $userId, $supplierId, $unitCost);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function createOperation(array $data, int $userId): int
    {
        $clientId = (int) ($data['client_id'] ?? 0);
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        if ($clientId <= 0 || $vehicleId <= 0) {
            throw new InvalidArgumentException('العميل والسيارة إجباريان');
        }

        $lines = $this->buildOperationLines($data);
        $subtotalHt = round(array_sum(array_column($lines, 'total')), 2);
        $vatRate = (float) ($data['vat_rate'] ?? 20);
        $vatRate = $vatRate >= 0 ? $vatRate : 20;
        $vatAmount = round($subtotalHt * ($vatRate / 100), 2);
        $totalTtc = round($subtotalHt + $vatAmount, 2);
        $paymentMethod = $this->normalizePaymentMethod((string) ($data['payment_method'] ?? 'ESP'));
        $checkNumber = $paymentMethod === 'CHQ' ? trim((string) ($data['check_number'] ?? '')) : '';

        $this->pdo->beginTransaction();
        try {
            foreach ([] as $line) {
                if ($line['product_id']) {
                    $product = $this->product((int) $line['product_id']);
                    if (!$product || (int) $product['stock_qty'] < (int) $line['quantity']) {
                        throw new InvalidArgumentException('المخزون غير كاف للمنتج: ' . $line['label']);
                    }
                }
            }

            $count = (int) $this->pdo->query('SELECT COUNT(*) + 1 FROM operations')->fetchColumn();
            $quoteNo = $this->nextDocumentNumber('quote');
            $invoiceNo = $quoteNo;
            $receiptNo = 'REC-' . date('Ymd-His') . '-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);

            $client = $this->client($clientId);
            $vehicle = $this->vehicle($vehicleId);

            $stmt = $this->pdo->prepare(
                'INSERT INTO operations
                (invoice_no, receipt_no, doc_type, quote_no, order_no, parent_id, client_id, client_name, client_address, vehicle_id, vehicle_plate, vehicle_brand, vehicle_model, payment_method, check_number, subtotal_ht, vat_rate, vat_amount, total_ttc, total, status, created_by, created_at)
                VALUES (:invoice_no, :receipt_no, "quote", :quote_no, NULL, NULL, :client_id, :client_name, :client_address, :vehicle_id, :vehicle_plate, :vehicle_brand, :vehicle_model, :payment_method, :check_number, :subtotal_ht, :vat_rate, :vat_amount, :total_ttc, :total, "draft", :created_by, datetime("now"))'
            );
            $stmt->execute([
                'invoice_no' => $invoiceNo,
                'receipt_no' => $receiptNo,
                'quote_no' => $quoteNo,
                'client_id' => $clientId,
                'client_name' => $client['name'] ?? '',
                'client_address' => $client['address'] ?? '',
                'vehicle_id' => $vehicleId,
                'vehicle_plate' => $vehicle['plate'] ?? '',
                'vehicle_brand' => $vehicle['brand_name'] ?? '',
                'vehicle_model' => $vehicle['model_name'] ?? '',
                'payment_method' => $paymentMethod,
                'check_number' => $checkNumber,
                'subtotal_ht' => $subtotalHt,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total_ttc' => $totalTtc,
                'total' => $totalTtc,
                'created_by' => $userId,
            ]);

            $operationId = (int) $this->pdo->lastInsertId();
            foreach ($lines as $line) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO operation_items (operation_id, product_id, line_type, label, quantity, unit_price, discount_rate, total_ht, total)
                     VALUES (:operation_id, :product_id, :line_type, :label, :quantity, :unit_price, :discount_rate, :total_ht, :total)'
                );
                $stmt->execute([
                    'operation_id' => $operationId,
                    'product_id' => $line['product_id'],
                    'line_type' => $line['line_type'],
                    'label' => $line['label'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_rate' => $line['discount_rate'],
                    'total_ht' => $line['total'],
                    'total' => $line['total'],
                ]);

                if (false && $line['product_id']) {
                    $update = $this->pdo->prepare('UPDATE products SET stock_qty = stock_qty - :qty WHERE id = :id');
                    $update->execute(['qty' => $line['quantity'], 'id' => $line['product_id']]);
                    $this->addMovement((int) $line['product_id'], 'out', (int) $line['quantity'], 'عملية مرآب ' . $invoiceNo, $userId);
                }
            }

            if (($data['simulate_failure'] ?? '') === '1') {
                throw new RuntimeException('Simulated failure');
            }

            $this->pdo->commit();
            return $operationId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function operations(): array
    {
        $operations = $this->pdo->query(
            'SELECT o.*, c.type AS client_type, c.name AS client_real_name, c.address AS client_real_address,
                    c.ice AS client_ice, c.vat AS client_vat, c.rc AS client_rc,
                    v.plate AS vehicle_real_plate, vb.name AS brand_name, vm.name AS model_name
             FROM operations o
             LEFT JOIN clients c ON c.id = o.client_id
             LEFT JOIN vehicles v ON v.id = o.vehicle_id
             LEFT JOIN vehicle_brands vb ON vb.id = v.brand_id
             LEFT JOIN vehicle_models vm ON vm.id = v.model_id
             ORDER BY o.id DESC LIMIT 25'
        )->fetchAll();

        return array_map(fn (array $operation): array => $this->decorateOperation($operation), $operations);
    }

    public function confirmQuote(int $id, int $userId): int
    {
        return $this->copyDocument($id, 'order', $userId, false);
    }

    public function invoiceDocument(int $id, int $userId): int
    {
        $source = $this->operation($id);
        if (!$source) {
            throw new InvalidArgumentException('Document introuvable');
        }

        $this->pdo->beginTransaction();
        try {
            if ($source['doc_type'] === 'quote') {
                $orderId = $this->copyDocument($id, 'order', $userId, false, true);
                $invoiceId = $this->copyDocument($orderId, 'invoice', $userId, true, true);
            } else {
                $invoiceId = $this->copyDocument($id, 'invoice', $userId, true, true);
            }
            $this->pdo->commit();
            return $invoiceId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function copyDocument(int $sourceId, string $targetType, int $userId, bool $decrementStock, bool $insideTransaction = false): int
    {
        $source = $this->operation($sourceId);
        if (!$source) {
            throw new InvalidArgumentException('Document introuvable');
        }
        if (!in_array($targetType, ['order', 'invoice'], true)) {
            throw new InvalidArgumentException('Type de document invalide');
        }
        if ($targetType === 'order' && $source['doc_type'] !== 'quote') {
            throw new InvalidArgumentException('Seul un devis peut etre confirme en commande');
        }
        if ($targetType === 'invoice' && !in_array($source['doc_type'], ['quote', 'order'], true)) {
            throw new InvalidArgumentException('Ce document ne peut pas etre facture');
        }

        if (!$insideTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            if ($decrementStock) {
                foreach ($source['items'] as $line) {
                    if ((int) ($line['product_id'] ?? 0) <= 0) {
                        continue;
                    }
                    $product = $this->product((int) $line['product_id']);
                    if (!$product || (string) ($product['product_type'] ?? 'stockable') === 'service') {
                        continue;
                    }
                    if ((int) $product['stock_qty'] < (int) $line['quantity']) {
                        throw new InvalidArgumentException('Stock insuffisant pour: ' . $line['label']);
                    }
                }
            }

            $documentNo = $this->nextDocumentNumber($targetType);
            $receiptNo = 'REC-' . date('Ymd-His') . '-' . str_pad((string) ((int) $this->pdo->query('SELECT COUNT(*) + 1 FROM operations')->fetchColumn()), 4, '0', STR_PAD_LEFT);
            $quoteNo = $targetType === 'order' ? (string) $source['quote_no'] : (string) ($source['quote_no'] ?: null);
            $orderNo = $targetType === 'order' ? $documentNo : (string) ($source['order_no'] ?: null);
            if ($targetType === 'invoice' && $source['doc_type'] === 'order') {
                $orderNo = (string) $source['order_no'];
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO operations
                (invoice_no, receipt_no, doc_type, quote_no, order_no, parent_id, client_id, client_name, client_address, vehicle_id, vehicle_plate, vehicle_brand, vehicle_model, payment_method, check_number, subtotal_ht, vat_rate, vat_amount, total_ttc, total, status, created_by, created_at)
                VALUES (:invoice_no, :receipt_no, :doc_type, :quote_no, :order_no, :parent_id, :client_id, :client_name, :client_address, :vehicle_id, :vehicle_plate, :vehicle_brand, :vehicle_model, :payment_method, :check_number, :subtotal_ht, :vat_rate, :vat_amount, :total_ttc, :total, :status, :created_by, datetime("now"))'
            );
            $stmt->execute([
                'invoice_no' => $documentNo,
                'receipt_no' => $receiptNo,
                'doc_type' => $targetType,
                'quote_no' => $targetType === 'order' ? $source['invoice_no'] : $quoteNo,
                'order_no' => $orderNo,
                'parent_id' => $sourceId,
                'client_id' => $source['client_id'],
                'client_name' => $source['client_name'],
                'client_address' => $source['client_address'],
                'vehicle_id' => $source['vehicle_id'],
                'vehicle_plate' => $source['vehicle_plate'],
                'vehicle_brand' => $source['vehicle_brand'],
                'vehicle_model' => $source['vehicle_model'],
                'payment_method' => $source['payment_method'],
                'check_number' => $source['check_number'],
                'subtotal_ht' => $source['subtotal_ht'],
                'vat_rate' => $source['vat_rate'],
                'vat_amount' => $source['vat_amount'],
                'total_ttc' => $source['total_ttc'],
                'total' => $source['total_ttc'],
                'status' => $targetType === 'invoice' ? 'issued' : 'draft',
                'created_by' => $userId,
            ]);

            $newId = (int) $this->pdo->lastInsertId();
            foreach ($source['items'] as $line) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO operation_items (operation_id, product_id, line_type, label, quantity, unit_price, discount_rate, total_ht, total)
                     VALUES (:operation_id, :product_id, :line_type, :label, :quantity, :unit_price, :discount_rate, :total_ht, :total)'
                );
                $insert->execute([
                    'operation_id' => $newId,
                    'product_id' => $line['product_id'],
                    'line_type' => $line['line_type'],
                    'label' => $line['label'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_rate' => $line['discount_rate'] ?? 0,
                    'total_ht' => $line['total_ht'] ?? $line['total'],
                    'total' => $line['total_ht'] ?? $line['total'],
                ]);

                if ($decrementStock && (int) ($line['product_id'] ?? 0) > 0) {
                    $product = $this->product((int) $line['product_id']);
                    if ($product && (string) ($product['product_type'] ?? 'stockable') !== 'service') {
                        $update = $this->pdo->prepare('UPDATE products SET stock_qty = stock_qty - :qty WHERE id = :id');
                        $update->execute(['qty' => (int) $line['quantity'], 'id' => (int) $line['product_id']]);
                        $this->addMovement((int) $line['product_id'], 'out', (int) $line['quantity'], 'Facture ' . $documentNo, $userId);
                    }
                }
            }

            $status = $targetType === 'order' ? 'confirmed' : 'invoiced';
            $this->pdo->prepare('UPDATE operations SET status = :status WHERE id = :id')->execute(['status' => $status, 'id' => $sourceId]);

            if (!$insideTransaction) {
                $this->pdo->commit();
            }
            return $newId;
        } catch (Throwable $e) {
            if (!$insideTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function operation(int $id): ?array
    {
        $operation = $this->row(
            'SELECT o.*, c.type AS client_type, c.name AS client_real_name, c.address AS client_real_address, c.ice AS client_ice, c.vat AS client_vat, c.rc AS client_rc,
                    v.plate AS vehicle_real_plate, vb.name AS brand_name, vm.name AS model_name
             FROM operations o
             LEFT JOIN clients c ON c.id = o.client_id
             LEFT JOIN vehicles v ON v.id = o.vehicle_id
             LEFT JOIN vehicle_brands vb ON vb.id = v.brand_id
             LEFT JOIN vehicle_models vm ON vm.id = v.model_id
             WHERE o.id = :id',
            ['id' => $id]
        );
        if (!$operation) {
            return null;
        }

        $operation = $this->decorateOperation($operation);
        $items = $this->pdo->prepare('SELECT * FROM operation_items WHERE operation_id = :id ORDER BY id');
        $items->execute(['id' => $id]);
        $operation['items'] = $items->fetchAll();

        return $operation;
    }

    public function clientVehicles(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.*, vb.name AS brand_name, vm.name AS model_name
             FROM vehicles v
             JOIN vehicle_brands vb ON vb.id = v.brand_id
             JOIN vehicle_models vm ON vm.id = v.model_id
             WHERE v.client_id = :client_id
             ORDER BY v.id DESC'
        );
        $stmt->execute(['client_id' => $clientId]);
        return $stmt->fetchAll();
    }

    public function clientOperations(int $clientId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, v.plate AS vehicle_real_plate, vb.name AS brand_name, vm.name AS model_name
             FROM operations o
             LEFT JOIN vehicles v ON v.id = o.vehicle_id
             LEFT JOIN vehicle_brands vb ON vb.id = v.brand_id
             LEFT JOIN vehicle_models vm ON vm.id = v.model_id
             WHERE o.client_id = :client_id
             ORDER BY o.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue('client_id', $clientId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn (array $operation): array => $this->decorateOperation($operation), $stmt->fetchAll());
    }

    public function vehicleOperations(int $vehicleId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, c.name AS client_real_name
             FROM operations o
             LEFT JOIN clients c ON c.id = o.client_id
             WHERE o.vehicle_id = :vehicle_id
             ORDER BY o.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue('vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn (array $operation): array => $this->decorateOperation($operation), $stmt->fetchAll());
    }

    public function supplierMovements(int $supplierId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sm.*, p.name AS product_name, p.sku
             FROM stock_movements sm
             JOIN products p ON p.id = sm.product_id
             WHERE sm.supplier_id = :supplier_id
             ORDER BY sm.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue('supplier_id', $supplierId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function categoryProducts(int $categoryId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM products WHERE category_id = :category_id ORDER BY name LIMIT :limit'
        );
        $stmt->bindValue('category_id', $categoryId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function vehicleBrand(int $id): ?array
    {
        return $this->row('SELECT * FROM vehicle_brands WHERE id = :id', ['id' => $id]);
    }

    public function vehicleModel(int $id): ?array
    {
        return $this->row(
            'SELECT vm.*, vb.name AS brand_name
             FROM vehicle_models vm
             JOIN vehicle_brands vb ON vb.id = vm.brand_id
             WHERE vm.id = :id',
            ['id' => $id]
        );
    }

    public function brandModels(int $brandId): array
    {
        return $this->vehicleModels($brandId);
    }

    public function users(): array
    {
        return $this->pdo->query('SELECT id, email, name, role, active, created_at FROM users ORDER BY role, name')->fetchAll();
    }

    public function createUser(array $data): int
    {
        $payload = $this->validateUserPayload($data);
        $password = (string) ($data['password'] ?? '');
        $confirmation = (string) ($data['password_confirm'] ?? '');
        $this->validateNewPassword($password, $confirmation);

        $payload['password'] = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password, name, role, active, created_at)
             VALUES (:email, :password, :name, :role, :active, datetime("now"))'
        );
        $stmt->execute($payload);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateUser(int $id, array $data, int $currentUserId): void
    {
        $user = $this->userById($id);
        if (!$user) {
            throw new InvalidArgumentException('المستخدم غير موجود');
        }

        $payload = $this->validateUserPayload($data, $id);
        if ($id === $currentUserId && (int) $payload['active'] === 0) {
            throw new InvalidArgumentException('لا يمكن تعطيل حسابك الحالي');
        }

        $keepsActiveAdmin = $payload['role'] === 'admin' && (int) $payload['active'] === 1;
        if ((int) $user['active'] === 1 && $user['role'] === 'admin' && !$keepsActiveAdmin && $this->activeAdminCount() <= 1) {
            throw new InvalidArgumentException('يجب الاحتفاظ بمدير نشط واحد على الأقل');
        }

        $payload['id'] = $id;
        $stmt = $this->pdo->prepare('UPDATE users SET email=:email, name=:name, role=:role, active=:active WHERE id=:id');
        $stmt->execute($payload);
    }

    public function changeUserPassword(int $id, string $password, string $confirmation): void
    {
        if (!$this->userById($id)) {
            throw new InvalidArgumentException('المستخدم غير موجود');
        }
        $this->validateNewPassword($password, $confirmation);
        $this->rehashUserPassword($id, $password);
    }

    public function changeOwnPassword(int $id, string $oldPassword, string $password, string $confirmation): void
    {
        $user = $this->userById($id);
        if (!$user || (int) $user['active'] !== 1) {
            throw new InvalidArgumentException('المستخدم غير موجود أو غير نشط');
        }
        if (!$this->isPasswordValid($user, $oldPassword)) {
            throw new InvalidArgumentException('كلمة المرور الحالية غير صحيحة');
        }
        $this->changeUserPassword($id, $password, $confirmation);
    }

    public function toggleUser(int $id, int $currentUserId): void
    {
        $user = $this->userById($id);
        if (!$user) {
            throw new InvalidArgumentException('المستخدم غير موجود');
        }
        if ($id === $currentUserId) {
            throw new InvalidArgumentException('لا يمكن تعطيل حسابك الحالي');
        }
        if ((int) $user['active'] === 1 && $user['role'] === 'admin' && $this->activeAdminCount() <= 1) {
            throw new InvalidArgumentException('يجب الاحتفاظ بمدير نشط واحد على الأقل');
        }

        $stmt = $this->pdo->prepare('UPDATE users SET active = :active WHERE id = :id');
        $stmt->execute(['active' => (int) $user['active'] === 1 ? 0 : 1, 'id' => $id]);
    }

    private function validateUserPayload(array $data, ?int $id = null): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $role = (string) ($data['role'] ?? 'manager');
        $active = ((string) ($data['active'] ?? '0')) === '1' ? 1 : 0;

        if ($name === '') {
            throw new InvalidArgumentException('اسم المستخدم إجباري');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('البريد الإلكتروني غير صحيح');
        }
        if (!in_array($role, ['admin', 'manager'], true)) {
            throw new InvalidArgumentException('الدور غير صحيح');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email AND (:id IS NULL OR id != :id)');
        $stmt->execute(['email' => $email, 'id' => $id]);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException('البريد الإلكتروني مستعمل مسبقا');
        }

        return [
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'active' => $active,
        ];
    }

    private function validateNewPassword(string $password, string $confirmation): void
    {
        if (mb_strlen($password) < 8) {
            throw new InvalidArgumentException('كلمة المرور يجب أن تحتوي على 8 أحرف على الأقل');
        }
        if ($password !== $confirmation) {
            throw new InvalidArgumentException('تأكيد كلمة المرور غير مطابق');
        }
    }

    private function activeAdminCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
    }

    private function validateProduct(array $data, ?int $id): array
    {
        $sku = trim((string) ($data['sku'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $categoryId = (int) ($data['category_id'] ?? 0);
        $minQty = (int) ($data['min_qty'] ?? 0);
        $purchasePrice = (float) ($data['purchase_price'] ?? 0);
        $salePrice = (float) ($data['sale_price'] ?? 0);
        $productType = (string) ($data['product_type'] ?? 'stockable');
        $productType = in_array($productType, ['stockable', 'service'], true) ? $productType : 'stockable';
        $marginMode = (string) ($data['margin_mode'] ?? 'manual');
        $marginRate = in_array($marginMode, ['135', '145', '155'], true) ? (float) $marginMode : null;
        if ($marginRate !== null) {
            $expected = round($purchasePrice * ($marginRate / 100), 2);
            if (abs($salePrice - $expected) <= 0.01 || $salePrice <= 0) {
                $salePrice = $expected;
            }
        }

        if ($sku === '' || $name === '' || $categoryId <= 0) {
            throw new InvalidArgumentException('الكود والاسم والصنف إجبارية');
        }
        if ($minQty < 0 || $purchasePrice < 0 || $salePrice < 0) {
            throw new InvalidArgumentException('الكميات والأسعار يجب أن تكون 0 أو أكثر');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM products WHERE sku = :sku AND (:id IS NULL OR id != :id)');
        $stmt->execute(['sku' => $sku, 'id' => $id]);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException('كود المنتج موجود مسبقا');
        }

        $category = $this->category($categoryId);
        if (!$category) {
            throw new InvalidArgumentException('الصنف غير موجود');
        }

        return [
            'sku' => $sku,
            'name' => $name,
            'category_id' => $categoryId,
            'category' => $category['name'],
            'min_qty' => $minQty,
            'purchase_price' => $purchasePrice,
            'sale_price' => $salePrice,
            'product_type' => $productType,
            'margin_rate' => $marginRate,
        ];
    }

    private function buildOperationLines(array $data): array
    {
        $lines = [];
        $lineProducts = $data['line_product_id'] ?? [];
        $lineLabels = $data['line_label'] ?? [];
        $lineQuantities = $data['line_quantity'] ?? [];
        $linePrices = $data['line_unit_price'] ?? [];
        $lineDiscounts = $data['line_discount'] ?? [];

        if (is_array($lineProducts) || is_array($lineLabels)) {
            $count = max(
                is_array($lineProducts) ? count($lineProducts) : 0,
                is_array($lineLabels) ? count($lineLabels) : 0,
                is_array($lineQuantities) ? count($lineQuantities) : 0
            );
            for ($i = 0; $i < $count; $i++) {
                $productId = (int) ($lineProducts[$i] ?? 0);
                $label = trim((string) ($lineLabels[$i] ?? ''));
                $qty = (float) ($lineQuantities[$i] ?? 0);
                $price = (float) ($linePrices[$i] ?? 0);
                $discount = (float) ($lineDiscounts[$i] ?? 0);

                if ($productId > 0) {
                    $product = $this->product($productId);
                    if (!$product || (int) ($product['active'] ?? 0) !== 1) {
                        throw new InvalidArgumentException('Produit invalide');
                    }
                    $label = $label !== '' ? $label : (string) $product['name'];
                    if ($price <= 0) {
                        $price = (float) $product['sale_price'];
                    }
                }

                if ($productId <= 0 && $label === '' && $qty <= 0 && $price <= 0) {
                    continue;
                }
                if ($qty <= 0 || $price < 0 || $discount < 0 || $discount > 100 || $label === '') {
                    throw new InvalidArgumentException('Ligne operation invalide');
                }

                $total = round($qty * $price * (1 - ($discount / 100)), 2);
                $product = $productId > 0 ? $this->product($productId) : null;
                $lines[] = [
                    'product_id' => $productId > 0 ? $productId : null,
                    'line_type' => $product && ($product['product_type'] ?? 'stockable') !== 'service' ? 'product' : 'service',
                    'label' => $label,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'discount_rate' => $discount,
                    'total' => $total,
                ];
            }
        }

        foreach ([1, 2, 3] as $i) {
            $productId = (int) ($data['product_' . $i] ?? 0);
            $qty = (int) ($data['product_qty_' . $i] ?? 0);
            if ($productId > 0 && $qty > 0) {
                $product = $this->product($productId);
                if ($product) {
                    $price = (float) $product['sale_price'];
                    $lines[] = [
                        'product_id' => $productId,
                        'line_type' => 'product',
                        'label' => $product['name'],
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'discount_rate' => 0,
                        'total' => $qty * $price,
                    ];
                }
            }
        }

        foreach ([1, 2] as $i) {
            $label = trim((string) ($data['service_label_' . $i] ?? ''));
            $price = (float) ($data['service_price_' . $i] ?? 0);
            if ($label !== '') {
                if ($price < 0) {
                    throw new InvalidArgumentException('ثمن الخدمة يجب أن يكون 0 أو أكثر');
                }
                $lines[] = [
                    'product_id' => null,
                    'line_type' => 'service',
                    'label' => $label,
                    'quantity' => 1,
                    'unit_price' => $price,
                    'discount_rate' => 0,
                    'total' => $price,
                ];
            }
        }

        if (!$lines) {
            throw new InvalidArgumentException('يجب إدخال قطعة أو خدمة واحدة على الأقل');
        }

        return $lines;
    }

    public function vehicle(int $id): ?array
    {
        return $this->row(
            'SELECT v.*, c.name AS client_name, c.phone AS client_phone, c.email AS client_email,
                    vb.name AS brand_name, vm.name AS model_name
             FROM vehicles v
             JOIN clients c ON c.id = v.client_id
             JOIN vehicle_brands vb ON vb.id = v.brand_id
             JOIN vehicle_models vm ON vm.id = v.model_id
             WHERE v.id = :id',
            ['id' => $id]
        );
    }

    private function normalizePaymentMethod(string $method): string
    {
        $method = strtoupper(trim($method));
        return match ($method) {
            'CHQ', 'CHEQUE', 'CHÈQUE' => 'CHQ',
            'CB', 'CARTE', 'TPE' => 'CB',
            'VIR', 'VIREMENT' => 'VIR',
            default => 'ESP',
        };
    }

    private function paymentLabel(string $method): string
    {
        return match ($this->normalizePaymentMethod($method)) {
            'CHQ' => 'CHEQUE',
            'CB' => 'CB',
            'VIR' => 'VIR',
            default => 'ESP',
        };
    }

    private function decorateOperation(array $operation): array
    {
        $operation['payment_method'] = $this->normalizePaymentMethod((string) ($operation['payment_method'] ?? 'ESP'));
        $operation['payment_label'] = $this->paymentLabel($operation['payment_method']);
        $operation['doc_type'] = (string) ($operation['doc_type'] ?? 'invoice');
        $operation['document_no'] = match ($operation['doc_type']) {
            'quote' => $operation['quote_no'] ?: $operation['invoice_no'],
            'order' => $operation['order_no'] ?: $operation['invoice_no'],
            default => $operation['invoice_no'],
        };
        $operation['subtotal_ht'] = (float) ($operation['subtotal_ht'] ?? ((float) ($operation['total'] ?? 0) / 1.2));
        $operation['vat_rate'] = (float) ($operation['vat_rate'] ?? 20);
        $operation['vat_amount'] = (float) ($operation['vat_amount'] ?? ((float) ($operation['total'] ?? 0) - $operation['subtotal_ht']));
        $operation['total_ttc'] = (float) ($operation['total_ttc'] ?? ($operation['total'] ?? 0));
        return $operation;
    }

    private function addMovement(int $productId, string $type, int $quantity, string $note, int $userId, ?int $supplierId = null, ?float $unitCost = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stock_movements (product_id, movement_type, quantity, note, created_by, supplier_id, unit_cost, created_at)
             VALUES (:product_id, :movement_type, :quantity, :note, :created_by, :supplier_id, :unit_cost, datetime("now"))'
        );
        $stmt->execute([
            'product_id' => $productId,
            'movement_type' => $type,
            'quantity' => $quantity,
            'note' => $note,
            'created_by' => $userId,
            'supplier_id' => $supplierId,
            'unit_cost' => $unitCost,
        ]);
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    name TEXT NOT NULL,
    role TEXT NOT NULL CHECK(role IN ('admin', 'manager')),
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    phone TEXT,
    email TEXT,
    address TEXT,
    ice TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL CHECK(type IN ('individual', 'company')),
    name TEXT NOT NULL,
    phone TEXT,
    email TEXT,
    address TEXT,
    ice TEXT,
    vat TEXT,
    rc TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS vehicle_brands (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE
);
CREATE TABLE IF NOT EXISTS vehicle_models (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brand_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    UNIQUE(brand_id, name),
    FOREIGN KEY(brand_id) REFERENCES vehicle_brands(id)
);
CREATE TABLE IF NOT EXISTS vehicles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    plate TEXT NOT NULL,
    brand_id INTEGER NOT NULL,
    model_id INTEGER NOT NULL,
    year INTEGER,
    mileage INTEGER,
    notes TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY(client_id) REFERENCES clients(id),
    FOREIGN KEY(brand_id) REFERENCES vehicle_brands(id),
    FOREIGN KEY(model_id) REFERENCES vehicle_models(id)
);
CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    category TEXT,
    category_id INTEGER,
    stock_qty INTEGER NOT NULL DEFAULT 0,
    min_qty INTEGER NOT NULL DEFAULT 1,
    purchase_price REAL NOT NULL DEFAULT 0,
    sale_price REAL NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS stock_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    movement_type TEXT NOT NULL,
    quantity INTEGER NOT NULL,
    note TEXT,
    supplier_id INTEGER,
    unit_cost REAL,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(product_id) REFERENCES products(id),
    FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY(created_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS operations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_no TEXT NOT NULL UNIQUE,
    receipt_no TEXT NOT NULL UNIQUE,
    client_id INTEGER,
    client_name TEXT,
    client_address TEXT,
    vehicle_id INTEGER,
    vehicle_plate TEXT,
    vehicle_brand TEXT,
    vehicle_model TEXT,
    payment_method TEXT NOT NULL,
    check_number TEXT,
    total REAL NOT NULL DEFAULT 0,
    status TEXT NOT NULL,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(client_id) REFERENCES clients(id),
    FOREIGN KEY(vehicle_id) REFERENCES vehicles(id),
    FOREIGN KEY(created_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS operation_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    operation_id INTEGER NOT NULL,
    product_id INTEGER,
    line_type TEXT NOT NULL,
    label TEXT NOT NULL,
    quantity INTEGER NOT NULL,
    unit_price REAL NOT NULL,
    total REAL NOT NULL,
    FOREIGN KEY(operation_id) REFERENCES operations(id) ON DELETE CASCADE,
    FOREIGN KEY(product_id) REFERENCES products(id)
);
SQL);

        $this->addColumnIfMissing('users', 'created_at', 'TEXT');
        $this->pdo->exec("UPDATE users SET created_at = datetime('now') WHERE created_at IS NULL OR created_at = ''");
        $this->addColumnIfMissing('products', 'category_id', 'INTEGER');
        $this->addColumnIfMissing('products', 'active', 'INTEGER NOT NULL DEFAULT 1');
        $this->addColumnIfMissing('products', 'product_type', "TEXT NOT NULL DEFAULT 'stockable'");
        $this->addColumnIfMissing('products', 'margin_rate', 'REAL');
        $this->addColumnIfMissing('clients', 'active', 'INTEGER NOT NULL DEFAULT 1');
        $this->addColumnIfMissing('vehicles', 'active', 'INTEGER NOT NULL DEFAULT 1');
        $this->addColumnIfMissing('vehicle_brands', 'active', 'INTEGER NOT NULL DEFAULT 1');
        $this->addColumnIfMissing('vehicle_models', 'active', 'INTEGER NOT NULL DEFAULT 1');
        $this->addColumnIfMissing('stock_movements', 'supplier_id', 'INTEGER');
        $this->addColumnIfMissing('stock_movements', 'unit_cost', 'REAL');
        $this->addColumnIfMissing('operations', 'client_id', 'INTEGER');
        $this->addColumnIfMissing('operations', 'vehicle_id', 'INTEGER');
        $this->addColumnIfMissing('operations', 'check_number', 'TEXT');
        $this->addColumnIfMissing('operations', 'doc_type', "TEXT NOT NULL DEFAULT 'invoice'");
        $this->addColumnIfMissing('operations', 'quote_no', 'TEXT');
        $this->addColumnIfMissing('operations', 'order_no', 'TEXT');
        $this->addColumnIfMissing('operations', 'subtotal_ht', 'REAL');
        $this->addColumnIfMissing('operations', 'vat_rate', 'REAL NOT NULL DEFAULT 20');
        $this->addColumnIfMissing('operations', 'vat_amount', 'REAL');
        $this->addColumnIfMissing('operations', 'total_ttc', 'REAL');
        $this->addColumnIfMissing('operations', 'parent_id', 'INTEGER');
        $this->addColumnIfMissing('operation_items', 'discount_rate', 'REAL NOT NULL DEFAULT 0');
        $this->addColumnIfMissing('operation_items', 'total_ht', 'REAL');
        $this->pdo->exec("UPDATE operations SET doc_type = 'invoice' WHERE doc_type IS NULL OR doc_type = ''");
        $this->pdo->exec("UPDATE operations SET total_ttc = total WHERE total_ttc IS NULL");
        $this->pdo->exec("UPDATE operations SET subtotal_ht = ROUND(COALESCE(total_ttc, total) / 1.2, 2) WHERE subtotal_ht IS NULL");
        $this->pdo->exec("UPDATE operations SET vat_amount = ROUND(COALESCE(total_ttc, total) - subtotal_ht, 2) WHERE vat_amount IS NULL");
        $this->pdo->exec("UPDATE operation_items SET total_ht = total WHERE total_ht IS NULL");

        $this->migrateCategories();
        $this->migrateClientsAndVehicles();
    }

    private function migrateCategories(): void
    {
        $legacy = $this->pdo->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(category), ''), 'قطع الغيار') AS name FROM products")->fetchAll();
        $insert = $this->pdo->prepare('INSERT OR IGNORE INTO categories (name, active, created_at) VALUES (:name, 1, datetime("now"))');
        foreach ($legacy as $row) {
            $insert->execute(['name' => $row['name']]);
        }

        $products = $this->pdo->query('SELECT id, COALESCE(NULLIF(TRIM(category), ""), "قطع الغيار") AS category FROM products WHERE category_id IS NULL')->fetchAll();
        $update = $this->pdo->prepare('UPDATE products SET category_id = :category_id WHERE id = :id');
        foreach ($products as $product) {
            $category = $this->row('SELECT id FROM categories WHERE name = :name', ['name' => $product['category']]);
            if ($category) {
                $update->execute(['category_id' => $category['id'], 'id' => $product['id']]);
            }
        }
    }

    private function migrateClientsAndVehicles(): void
    {
        $operations = $this->pdo->query('SELECT * FROM operations WHERE client_id IS NULL OR vehicle_id IS NULL')->fetchAll();
        foreach ($operations as $operation) {
            $clientName = trim((string) ($operation['client_name'] ?? 'Client'));
            $clientAddress = trim((string) ($operation['client_address'] ?? ''));
            $client = $this->row('SELECT id FROM clients WHERE name = :name AND COALESCE(address, "") = :address', ['name' => $clientName, 'address' => $clientAddress]);
            if (!$client) {
                $stmt = $this->pdo->prepare('INSERT INTO clients (type, name, phone, email, address, ice, vat, rc, created_at) VALUES ("individual", :name, "", "", :address, "", "", "", datetime("now"))');
                $stmt->execute(['name' => $clientName, 'address' => $clientAddress]);
                $clientId = (int) $this->pdo->lastInsertId();
            } else {
                $clientId = (int) $client['id'];
            }

            $brandName = trim((string) ($operation['vehicle_brand'] ?? 'Autre')) ?: 'Autre';
            $modelName = trim((string) ($operation['vehicle_model'] ?? 'Modele')) ?: 'Modele';
            $plate = trim((string) ($operation['vehicle_plate'] ?? 'N/A')) ?: 'N/A';
            $brandId = $this->ensureBrand($brandName);
            $modelId = $this->ensureModel($brandId, $modelName);
            $vehicle = $this->row('SELECT id FROM vehicles WHERE client_id = :client_id AND plate = :plate', ['client_id' => $clientId, 'plate' => $plate]);
            if (!$vehicle) {
                $stmt = $this->pdo->prepare('INSERT INTO vehicles (client_id, plate, brand_id, model_id, year, mileage, notes, created_at) VALUES (:client_id, :plate, :brand_id, :model_id, NULL, NULL, "", datetime("now"))');
                $stmt->execute(['client_id' => $clientId, 'plate' => $plate, 'brand_id' => $brandId, 'model_id' => $modelId]);
                $vehicleId = (int) $this->pdo->lastInsertId();
            } else {
                $vehicleId = (int) $vehicle['id'];
            }

            $stmt = $this->pdo->prepare('UPDATE operations SET client_id = :client_id, vehicle_id = :vehicle_id WHERE id = :id');
            $stmt->execute(['client_id' => $clientId, 'vehicle_id' => $vehicleId, 'id' => $operation['id']]);
        }
    }

    private function seed(): void
    {
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            $stmt = $this->pdo->prepare('INSERT INTO users (email, password, name, role, active, created_at) VALUES (?, ?, ?, ?, 1, datetime("now"))');
            $stmt->execute(['admin@simauto.ma', password_hash('admin123', PASSWORD_BCRYPT), 'مدير النظام', 'admin']);
            $stmt->execute(['manager@simauto.ma', password_hash('manager123', PASSWORD_BCRYPT), 'مسير الورشة', 'manager']);
        }

        if ((int) $this->pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0) {
            foreach (['زيوت', 'فلاتر', 'بطاريات', 'سوائل', 'قطع الغيار'] as $name) {
                $this->saveCategory(['name' => $name]);
            }
        }

        if ((int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0) {
            $category = $this->row('SELECT id FROM categories WHERE name = :name', ['name' => 'قطع الغيار']) ?: ['id' => 1];
            $products = [
                ['HUILE-5W30', 'HUILE MOTEUR 5W30', 18, 4, 250, 400],
                ['FILT-HUILE', 'FILTRE A HUILE', 30, 8, 40, 70],
                ['FILT-AIR', 'FILTRE A AIR', 24, 6, 35, 60],
                ['FILT-GASOIL', 'FILTRE A GASOIL', 12, 4, 180, 280],
                ['BAT-70AH', 'BATTERIE 70AH', 8, 2, 900, 1200],
                ['ANTIGEL-5L', 'ANTIGEL 5L', 15, 5, 80, 120],
            ];
            foreach ($products as $product) {
                $this->saveProduct([
                    'sku' => $product[0],
                    'name' => $product[1],
                    'category_id' => $category['id'],
                    'stock_qty' => $product[2],
                    'min_qty' => $product[3],
                    'purchase_price' => $product[4],
                    'sale_price' => $product[5],
                ], 1);
            }
        }
    }

    private function ensureBrand(string $name): int
    {
        $this->saveVehicleBrand($name);
        return (int) $this->row('SELECT id FROM vehicle_brands WHERE name = :name', ['name' => $name])['id'];
    }

    private function ensureModel(int $brandId, string $name): int
    {
        $this->saveVehicleModel($brandId, $name);
        return (int) $this->row('SELECT id FROM vehicle_models WHERE brand_id = :brand_id AND name = :name', ['brand_id' => $brandId, 'name' => $name])['id'];
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $columns = $this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        foreach ($columns as $existing) {
            if ($existing['name'] === $column) {
                return;
            }
        }
        $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }

    private function stateWhere(string $state, string $alias = ''): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        return match ($state) {
            'inactive' => ' WHERE ' . $prefix . 'active = 0',
            'all' => '',
            default => ' WHERE ' . $prefix . 'active = 1',
        };
    }

    private function nextDocumentNumber(string $type): string
    {
        $prefix = match ($type) {
            'order' => 'BC',
            'invoice' => 'FAC',
            default => 'DEV',
        };
        $column = match ($type) {
            'order' => 'order_no',
            'invoice' => 'invoice_no',
            default => 'quote_no',
        };
        $date = date('Ymd');
        $stmt = $this->pdo->prepare("SELECT COUNT(*) + 1 FROM operations WHERE doc_type = :doc_type AND date(created_at) = date('now')");
        $stmt->execute(['doc_type' => $type]);
        $next = (int) $stmt->fetchColumn();
        do {
            $number = $prefix . '-' . $date . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $exists = $this->row("SELECT id FROM operations WHERE invoice_no = :number OR $column = :number", ['number' => $number]);
            $next++;
        } while ($exists);

        return $number;
    }

    private function entityTable(string $entity): string
    {
        return match ($entity) {
            'product' => 'products',
            'category' => 'categories',
            'supplier' => 'suppliers',
            'client' => 'clients',
            'vehicle' => 'vehicles',
            'brand' => 'vehicle_brands',
            'model' => 'vehicle_models',
            default => throw new InvalidArgumentException('Entite invalide'),
        };
    }

    private function referenceCounts(string $entity, int $id): array
    {
        return match ($entity) {
            'product' => [
                'mouvements de stock' => $this->countWhere('stock_movements', 'product_id', $id),
                'lignes operation' => $this->countWhere('operation_items', 'product_id', $id),
            ],
            'category' => ['produits' => $this->countWhere('products', 'category_id', $id)],
            'supplier' => ['mouvements de stock' => $this->countWhere('stock_movements', 'supplier_id', $id)],
            'client' => [
                'vehicules' => $this->countWhere('vehicles', 'client_id', $id),
                'operations' => $this->countWhere('operations', 'client_id', $id),
            ],
            'vehicle' => ['operations' => $this->countWhere('operations', 'vehicle_id', $id)],
            'brand' => ['modeles' => $this->countWhere('vehicle_models', 'brand_id', $id)],
            'model' => ['vehicules' => $this->countWhere('vehicles', 'model_id', $id)],
            default => [],
        };
    }

    private function countWhere(string $table, string $column, int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    private function row(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }
}
