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
        $dir = $databasePath ? dirname($databasePath) : DesktopPaths::dataDir($projectDir);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . ($databasePath ?: DesktopPaths::databasePath($projectDir)));
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

    public function updateVehicleBrand(int $id, string $name): void
    {
        $name = trim($name);
        if ($id <= 0 || $name === '') {
            throw new InvalidArgumentException('اسم الماركة إجباري');
        }
        $stmt = $this->pdo->prepare('UPDATE vehicle_brands SET name = :name WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $id]);
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

    public function updateVehicleModel(int $id, int $brandId, string $name): void
    {
        $name = trim($name);
        if ($id <= 0 || $brandId <= 0 || $name === '') {
            throw new InvalidArgumentException('الماركة والموديل إجباريان');
        }
        $stmt = $this->pdo->prepare('UPDATE vehicle_models SET brand_id = :brand_id, name = :name WHERE id = :id');
        $stmt->execute(['brand_id' => $brandId, 'name' => $name, 'id' => $id]);
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
        $order = 'p.name';

        if (($filters['q'] ?? '') !== '') {
            $search = $this->normalizeSearchTerm((string) $filters['q']);
            $where[] = '(
                lower(trim(COALESCE(p.ref_company, ""))) = :q_exact
                OR lower(trim(COALESCE(p.ref_universal, ""))) = :q_exact
                OR lower(trim(p.sku)) = :q_exact
                OR lower(trim(COALESCE(p.ref_company, ""))) LIKE :q_prefix
                OR lower(trim(COALESCE(p.ref_universal, ""))) LIKE :q_prefix
                OR lower(trim(p.sku)) LIKE :q_prefix
                OR lower(p.name) LIKE :q_contains
                OR lower(COALESCE(c.name, p.category, "")) LIKE :q_contains
            )';
            $params['q_exact'] = $search;
            $params['q_prefix'] = $search . '%';
            $params['q_contains'] = '%' . $search . '%';
            $order = 'CASE
                WHEN lower(trim(COALESCE(p.ref_company, ""))) = :q_exact THEN 1
                WHEN lower(trim(COALESCE(p.ref_universal, ""))) = :q_exact THEN 2
                WHEN lower(trim(p.sku)) = :q_exact THEN 3
                WHEN lower(trim(COALESCE(p.ref_company, ""))) LIKE :q_prefix THEN 4
                WHEN lower(trim(COALESCE(p.ref_universal, ""))) LIKE :q_prefix THEN 5
                WHEN lower(trim(p.sku)) LIKE :q_prefix THEN 6
                WHEN lower(p.name) LIKE :q_contains THEN 7
                WHEN lower(COALESCE(c.name, p.category, "")) LIKE :q_contains THEN 8
                ELSE 9
            END, p.name';
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

        $sql = 'SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order;
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

    public function productByCompanyReference(string $reference): ?array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        return $this->row(
            'SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE lower(trim(COALESCE(p.ref_company, ""))) = lower(trim(:reference))',
            ['reference' => $reference]
        );
    }

    public function productMovements(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sm.*, s.name AS supplier_name, u.name AS created_by_name
             FROM stock_movements sm
             LEFT JOIN suppliers s ON s.id = sm.supplier_id
             LEFT JOIN users u ON u.id = sm.created_by
             WHERE sm.product_id = :product_id
             ORDER BY sm.id DESC'
        );
        $stmt->execute(['product_id' => $productId]);
        return $stmt->fetchAll();
    }

    public function stockMovement(int $id): ?array
    {
        return $this->row(
            'SELECT sm.*, p.name AS product_name, p.sku, p.stock_qty, s.name AS supplier_name
             FROM stock_movements sm
             JOIN products p ON p.id = sm.product_id
             LEFT JOIN suppliers s ON s.id = sm.supplier_id
             WHERE sm.id = :id',
            ['id' => $id]
        );
    }

    public function saveProduct(array $data, int $userId, ?int $id = null): void
    {
        $payload = $this->validateProduct($data, $id);

        if ($id) {
            $payload['id'] = $id;
            $stmt = $this->pdo->prepare(
                'UPDATE products SET sku=:sku, ref_universal=:ref_universal, ref_company=:ref_company, name=:name, category_id=:category_id, category=:category, min_qty=:min_qty, purchase_price=:purchase_price, sale_price=:sale_price, product_type=:product_type, margin_rate=:margin_rate WHERE id=:id'
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
                'INSERT INTO products (sku, ref_universal, ref_company, name, category, category_id, stock_qty, min_qty, purchase_price, sale_price, product_type, margin_rate, active, created_at)
                 VALUES (:sku, :ref_universal, :ref_company, :name, :category, :category_id, :stock_qty, :min_qty, :purchase_price, :sale_price, :product_type, :margin_rate, 1, datetime("now"))'
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
        if ($entity === 'operation') {
            $operation = $this->operation($id);
            if (!$operation) {
                throw new InvalidArgumentException('العملية غير موجودة');
            }
            if (($operation['doc_type'] ?? '') !== 'quote' || ($operation['status'] ?? '') !== 'draft') {
                throw new InvalidArgumentException('لا يمكن حذف وثيقة مؤكدة أو مفوترة');
            }
        }
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

    public function adjustStock(int $productId, int $realQuantity, string $reason, int $userId): int
    {
        $reason = trim($reason);
        if ($productId <= 0) {
            throw new InvalidArgumentException('المنتج إجباري');
        }
        if ($realQuantity < 0) {
            throw new InvalidArgumentException('الكمية يجب أن تكون 0 أو أكثر');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('سبب التعديل إجباري');
        }

        $this->pdo->beginTransaction();
        try {
            $product = $this->row("SELECT id, stock_qty FROM products WHERE id = :id AND active = 1 AND product_type = 'stockable'", ['id' => $productId]);
            if (!$product) {
                throw new RuntimeException('المنتج غير موجود');
            }
            $current = (int) $product['stock_qty'];
            $delta = $realQuantity - $current;
            if ($delta === 0) {
                $this->pdo->commit();
                return 0;
            }

            $stmt = $this->pdo->prepare('UPDATE products SET stock_qty = :qty WHERE id = :id');
            $stmt->execute(['qty' => $realQuantity, 'id' => $productId]);
            $this->addMovement($productId, 'adjustment', $delta, $reason, $userId);
            $this->pdo->commit();
            return $delta;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function updateStockMovement(int $id, array $data, int $userId): void
    {
        $movement = $this->stockMovement($id);
        if (!$movement) {
            throw new InvalidArgumentException('حركة المخزون غير موجودة');
        }
        $payload = $this->validateStockMovementPayload($data, (int) $movement['product_id']);
        $oldEffect = $this->movementEffect((string) $movement['movement_type'], (int) $movement['quantity']);
        $newEffect = $this->movementEffect($payload['movement_type'], $payload['quantity']);

        $this->pdo->beginTransaction();
        try {
            $product = $this->row("SELECT stock_qty FROM products WHERE id = :id AND product_type = 'stockable'", ['id' => $movement['product_id']]);
            if (!$product) {
                throw new RuntimeException('المنتج غير موجود');
            }
            $newStock = (int) $product['stock_qty'] - $oldEffect + $newEffect;
            if ($newStock < 0) {
                throw new InvalidArgumentException('المخزون لا يمكن أن يكون سالبا');
            }

            $this->pdo->prepare('UPDATE products SET stock_qty = :qty WHERE id = :id')->execute([
                'qty' => $newStock,
                'id' => $movement['product_id'],
            ]);
            $stmt = $this->pdo->prepare(
                'UPDATE stock_movements
                 SET movement_type = :movement_type, quantity = :quantity, note = :note, supplier_id = :supplier_id, unit_cost = :unit_cost, created_by = :created_by
                 WHERE id = :id'
            );
            $stmt->execute([
                'movement_type' => $payload['movement_type'],
                'quantity' => $payload['quantity'],
                'note' => $payload['note'],
                'supplier_id' => $payload['supplier_id'],
                'unit_cost' => $payload['unit_cost'],
                'created_by' => $userId,
                'id' => $id,
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function deleteStockMovement(int $id): void
    {
        $movement = $this->stockMovement($id);
        if (!$movement) {
            throw new InvalidArgumentException('حركة المخزون غير موجودة');
        }
        $effect = $this->movementEffect((string) $movement['movement_type'], (int) $movement['quantity']);

        $this->pdo->beginTransaction();
        try {
            $product = $this->row("SELECT stock_qty FROM products WHERE id = :id AND product_type = 'stockable'", ['id' => $movement['product_id']]);
            if (!$product) {
                throw new RuntimeException('المنتج غير موجود');
            }
            $newStock = (int) $product['stock_qty'] - $effect;
            if ($newStock < 0) {
                throw new InvalidArgumentException('المخزون لا يمكن أن يكون سالبا');
            }
            $this->pdo->prepare('UPDATE products SET stock_qty = :qty WHERE id = :id')->execute([
                'qty' => $newStock,
                'id' => $movement['product_id'],
            ]);
            $this->pdo->prepare('DELETE FROM stock_movements WHERE id = :id')->execute(['id' => $id]);
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
        $vatRate = (float) ($data['vat_rate'] ?? 20);
        $vatRate = $vatRate >= 0 ? $vatRate : 20;
        $totalTtc = round(array_sum(array_column($lines, 'total')), 2);
        $totals = $this->splitIncludedTax($totalTtc, $vatRate);
        $subtotalHt = $totals['ht'];
        $vatAmount = $totals['vat'];
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
                    'total_ht' => $this->splitIncludedTax((float) $line['total'], $vatRate)['ht'],
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

    public function updateDraftOperation(int $id, array $data, int $userId): void
    {
        $operation = $this->operation($id);
        if (!$operation) {
            throw new InvalidArgumentException('العملية غير موجودة');
        }
        if (($operation['doc_type'] ?? '') !== 'quote' || ($operation['status'] ?? '') !== 'draft') {
            throw new InvalidArgumentException('يمكن تعديل devis في حالة المسودة فقط');
        }

        $clientId = (int) ($data['client_id'] ?? 0);
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        if ($clientId <= 0 || $vehicleId <= 0) {
            throw new InvalidArgumentException('العميل والسيارة إجباريان');
        }

        $lines = $this->buildOperationLines($data);
        $vatRate = (float) ($data['vat_rate'] ?? 20);
        $vatRate = $vatRate >= 0 ? $vatRate : 20;
        $totalTtc = round(array_sum(array_column($lines, 'total')), 2);
        $totals = $this->splitIncludedTax($totalTtc, $vatRate);
        $paymentMethod = $this->normalizePaymentMethod((string) ($data['payment_method'] ?? 'ESP'));
        $checkNumber = $paymentMethod === 'CHQ' ? trim((string) ($data['check_number'] ?? '')) : '';
        $client = $this->client($clientId);
        $vehicle = $this->vehicle($vehicleId);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE operations
                 SET client_id = :client_id, client_name = :client_name, client_address = :client_address,
                     vehicle_id = :vehicle_id, vehicle_plate = :vehicle_plate, vehicle_brand = :vehicle_brand, vehicle_model = :vehicle_model,
                     payment_method = :payment_method, check_number = :check_number,
                     subtotal_ht = :subtotal_ht, vat_rate = :vat_rate, vat_amount = :vat_amount, total_ttc = :total_ttc, total = :total,
                     created_by = :created_by
                 WHERE id = :id AND doc_type = "quote" AND status = "draft"'
            );
            $stmt->execute([
                'client_id' => $clientId,
                'client_name' => $client['name'] ?? '',
                'client_address' => $client['address'] ?? '',
                'vehicle_id' => $vehicleId,
                'vehicle_plate' => $vehicle['plate'] ?? '',
                'vehicle_brand' => $vehicle['brand_name'] ?? '',
                'vehicle_model' => $vehicle['model_name'] ?? '',
                'payment_method' => $paymentMethod,
                'check_number' => $checkNumber,
                'subtotal_ht' => $totals['ht'],
                'vat_rate' => $vatRate,
                'vat_amount' => $totals['vat'],
                'total_ttc' => $totalTtc,
                'total' => $totalTtc,
                'created_by' => $userId,
                'id' => $id,
            ]);
            if ($stmt->rowCount() === 0) {
                throw new InvalidArgumentException('يمكن تعديل devis في حالة المسودة فقط');
            }

            $this->pdo->prepare('DELETE FROM operation_items WHERE operation_id = :id')->execute(['id' => $id]);
            foreach ($lines as $line) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO operation_items (operation_id, product_id, line_type, label, quantity, unit_price, discount_rate, total_ht, total)
                     VALUES (:operation_id, :product_id, :line_type, :label, :quantity, :unit_price, :discount_rate, :total_ht, :total)'
                );
                $insert->execute([
                    'operation_id' => $id,
                    'product_id' => $line['product_id'],
                    'line_type' => $line['line_type'],
                    'label' => $line['label'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_rate' => $line['discount_rate'],
                    'total_ht' => $this->splitIncludedTax((float) $line['total'], $vatRate)['ht'],
                    'total' => $line['total'],
                ]);
            }

            $this->pdo->commit();
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

    public function searchOperations(array $filters): array
    {
        $where = ['o.doc_type IN ("quote", "order", "invoice")'];
        $params = [];

        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        if ($q !== '') {
            $where[] = '(lower(COALESCE(o.invoice_no, "")) LIKE :q
                OR lower(COALESCE(o.quote_no, "")) LIKE :q
                OR lower(COALESCE(o.order_no, "")) LIKE :q
                OR lower(COALESCE(c.name, o.client_name, "")) LIKE :q
                OR lower(COALESCE(v.plate, o.vehicle_plate, "")) LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }

        $docType = (string) ($filters['doc_type'] ?? '');
        if (in_array($docType, ['quote', 'order', 'invoice'], true)) {
            $where[] = 'o.doc_type = :doc_type';
            $params['doc_type'] = $docType;
        }

        $paymentMethod = $this->normalizePaymentMethod((string) ($filters['payment_method'] ?? ''));
        if (in_array((string) ($filters['payment_method'] ?? ''), ['ESP', 'CHQ', 'CB', 'VIR'], true)) {
            $where[] = 'o.payment_method = :payment_method';
            $params['payment_method'] = $paymentMethod;
        }

        if (($filters['date_from'] ?? '') !== '') {
            $where[] = 'DATE(o.created_at) >= :date_from';
            $params['date_from'] = (string) $filters['date_from'];
        }

        if (($filters['date_to'] ?? '') !== '') {
            $where[] = 'DATE(o.created_at) <= :date_to';
            $params['date_to'] = (string) $filters['date_to'];
        }

        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM operations o LEFT JOIN clients c ON c.id = o.client_id LEFT JOIN vehicles v ON v.id = o.vehicle_id WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT o.id, o.doc_type, o.invoice_no, o.quote_no, o.order_no, o.created_at,
                    COALESCE(c.name, o.client_name) AS client_name,
                    COALESCE(v.plate, o.vehicle_plate) AS vehicle_plate,
                    o.payment_method, o.total_ttc, o.total, o.status
             FROM operations o
             LEFT JOIN clients c ON c.id = o.client_id
             LEFT JOIN vehicles v ON v.id = o.vehicle_id
             WHERE ' . $whereSql . '
             ORDER BY o.created_at DESC, o.id DESC
             LIMIT 200'
        );
        $stmt->execute($params);
        $rows = array_map(fn (array $operation): array => $this->decorateOperation($operation), $stmt->fetchAll());

        return [
            'rows' => $rows,
            'total' => $total,
            'limited' => $total > 200,
        ];
    }

    public function getFinancialSummary(string $dateFrom, string $dateTo): array
    {
        $operations = $this->getFinancialOperations($dateFrom, $dateTo);
        $paymentMethods = $this->emptyPaymentBreakdown();
        $summary = [
            'invoice_count' => count($operations),
            'total_ttc' => 0.0,
            'subtotal_ht' => 0.0,
            'vat_amount' => 0.0,
            'total_cost_ht' => 0.0,
            'total_margin' => 0.0,
            'margin_rate' => 0.0,
            'payments' => $paymentMethods,
        ];

        foreach ($operations as $operation) {
            $summary['total_ttc'] += (float) $operation['total_ttc'];
            $summary['subtotal_ht'] += (float) $operation['subtotal_ht'];
            $summary['vat_amount'] += (float) $operation['vat_amount'];
            $summary['total_cost_ht'] += (float) $operation['cost_ht'];
            $summary['total_margin'] += (float) $operation['margin'];
            $method = $this->normalizePaymentMethod((string) ($operation['payment_method'] ?? 'ESP'));
            $summary['payments'][$method]['count']++;
            $summary['payments'][$method]['total_ttc'] += (float) $operation['total_ttc'];
        }

        foreach ($summary['payments'] as $method => $payment) {
            $summary['payments'][$method]['total_ttc'] = round((float) $payment['total_ttc'], 2);
        }

        $summary['total_ttc'] = round($summary['total_ttc'], 2);
        $summary['subtotal_ht'] = round($summary['subtotal_ht'], 2);
        $summary['vat_amount'] = round($summary['vat_amount'], 2);
        $summary['total_cost_ht'] = round($summary['total_cost_ht'], 2);
        $summary['total_margin'] = round($summary['total_margin'], 2);
        $summary['margin_rate'] = $summary['subtotal_ht'] > 0 ? round(($summary['total_margin'] / $summary['subtotal_ht']) * 100, 2) : 0.0;

        return $summary;
    }

    public function getFinancialOperations(string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id, o.invoice_no, o.doc_type, o.created_at, o.client_name, o.vehicle_plate, o.payment_method,
                    o.subtotal_ht, o.vat_rate, o.vat_amount, o.total_ttc, o.total,
                    c.name AS client_real_name, v.plate AS vehicle_real_plate
             FROM operations o
             LEFT JOIN clients c ON c.id = o.client_id
             LEFT JOIN vehicles v ON v.id = o.vehicle_id
             WHERE o.doc_type = "invoice"
               AND DATE(o.created_at) >= :date_from
               AND DATE(o.created_at) <= :date_to
             ORDER BY o.created_at DESC, o.id DESC'
        );
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $operations = $stmt->fetchAll();
        if (!$operations) {
            return [];
        }

        $linesByOperation = $this->financialLinesForOperations(array_column($operations, 'id'));
        foreach ($operations as &$operation) {
            $operation = $this->decorateOperation($operation);
            $lines = $linesByOperation[(int) $operation['id']] ?? [];
            $totals = $this->financialTotalsForLines($lines, (float) $operation['vat_rate']);
            $operation['client_display_name'] = $operation['client_real_name'] ?: $operation['client_name'];
            $operation['vehicle_display_plate'] = $operation['vehicle_real_plate'] ?: $operation['vehicle_plate'];
            $operation['cost_ht'] = $totals['cost_ht'];
            $operation['margin'] = $totals['margin'];
            $operation['margin_rate'] = (float) $operation['subtotal_ht'] > 0 ? round(($totals['margin'] / (float) $operation['subtotal_ht']) * 100, 2) : 0.0;
            $operation['has_estimated_lines'] = $totals['has_estimated_lines'];
        }
        unset($operation);

        return $operations;
    }

    public function getOperationMarginDetails(int $operationId): array
    {
        $operation = $this->row(
            'SELECT o.*, c.name AS client_real_name, v.plate AS vehicle_real_plate
             FROM operations o
             LEFT JOIN clients c ON c.id = o.client_id
             LEFT JOIN vehicles v ON v.id = o.vehicle_id
             WHERE o.id = :id AND o.doc_type = "invoice"',
            ['id' => $operationId]
        );
        if (!$operation) {
            return [];
        }

        $operation = $this->decorateOperation($operation);
        $operation['client_display_name'] = $operation['client_real_name'] ?: $operation['client_name'];
        $operation['vehicle_display_plate'] = $operation['vehicle_real_plate'] ?: $operation['vehicle_plate'];
        $lines = $this->financialLinesForOperations([$operationId])[$operationId] ?? [];
        $operation['margin_lines'] = array_map(
            fn (array $line): array => $this->decorateFinancialLine($line, (float) $operation['vat_rate']),
            $lines
        );
        $totals = $this->financialTotalsForLines($lines, (float) $operation['vat_rate']);
        $operation['cost_ht'] = $totals['cost_ht'];
        $operation['margin'] = $totals['margin'];
        $operation['margin_rate'] = (float) $operation['subtotal_ht'] > 0 ? round(($totals['margin'] / (float) $operation['subtotal_ht']) * 100, 2) : 0.0;
        $operation['has_estimated_lines'] = $totals['has_estimated_lines'];

        return $operation;
    }

    public function getDailySessionReport(string $date): array
    {
        return [
            'date' => $date,
            'summary' => $this->getFinancialSummary($date, $date),
            'operations' => $this->getFinancialOperations($date, $date),
            'printed_at' => date('Y-m-d H:i:s'),
        ];
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
                    'total' => $line['total'] ?? $line['total_ht'],
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
        $items = $this->pdo->prepare(
            'SELECT oi.*, p.sku AS product_sku
             FROM operation_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.operation_id = :id
             ORDER BY oi.id'
        );
        $items->execute(['id' => $id]);
        $operation['items'] = $items->fetchAll();

        return $operation;
    }

    public function operationDocumentChain(int $id): array
    {
        $operation = $this->operation($id);
        if (!$operation) {
            return ['parents' => [], 'children' => []];
        }

        $parents = [];
        $parentId = (int) ($operation['parent_id'] ?? 0);
        while ($parentId > 0) {
            $parent = $this->operationSummary($parentId);
            if (!$parent) {
                break;
            }
            array_unshift($parents, $parent);
            $parentId = (int) ($parent['parent_id'] ?? 0);
        }

        return [
            'parents' => $parents,
            'children' => $this->operationChildren($id),
        ];
    }

    private function operationSummary(int $id): ?array
    {
        $operation = $this->row(
            'SELECT id, doc_type, invoice_no, quote_no, order_no, parent_id, created_at, status, total_ttc, total
             FROM operations
             WHERE id = :id',
            ['id' => $id]
        );

        return $operation ? $this->decorateOperation($operation) : null;
    }

    private function operationChildren(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, doc_type, invoice_no, quote_no, order_no, parent_id, created_at, status, total_ttc, total
             FROM operations
             WHERE parent_id = :id
             ORDER BY id'
        );
        $stmt->execute(['id' => $id]);
        $children = [];
        foreach ($stmt->fetchAll() as $child) {
            $child = $this->decorateOperation($child);
            $child['children'] = $this->operationChildren((int) $child['id']);
            $children[] = $child;
        }

        return $children;
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
        $refUniversal = trim((string) ($data['ref_universal'] ?? ''));
        $refCompany = trim((string) ($data['ref_company'] ?? ''));
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
        if ($refCompany !== '') {
            $existingRef = $this->productByCompanyReference($refCompany);
            if ($existingRef && (!$id || (int) $existingRef['id'] !== $id)) {
                throw new InvalidArgumentException('مرجع الشركة مستعمل مسبقا');
            }
        }

        $category = $this->category($categoryId);
        if (!$category) {
            throw new InvalidArgumentException('الصنف غير موجود');
        }

        return [
            'sku' => $sku,
            'ref_universal' => $refUniversal !== '' ? $refUniversal : null,
            'ref_company' => $refCompany !== '' ? $refCompany : null,
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

    private function normalizeSearchTerm(string $term): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $term) ?: ''));
    }

    private function buildOperationLines(array $data): array
    {
        $lines = [];
        $lineProducts = $data['line_product_id'] ?? [];
        $lineLabels = $data['line_label'] ?? [];
        $lineQuantities = $data['line_quantity'] ?? [];
        $linePrices = $data['line_unit_price'] ?? [];
        $lineDiscounts = $data['line_discount'] ?? [];
        $lineMarginModes = $data['line_margin_mode'] ?? [];

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
                $marginMode = (string) ($lineMarginModes[$i] ?? 'manual');

                if ($productId > 0) {
                    $product = $this->product($productId);
                    if (!$product || (int) ($product['active'] ?? 0) !== 1) {
                        throw new InvalidArgumentException('Produit invalide');
                    }
                    $label = $label !== '' ? $label : (string) $product['name'];
                    $canUseMargin = (string) ($product['product_type'] ?? 'stockable') !== 'service' && (float) ($product['purchase_price'] ?? 0) > 0;
                    if ($canUseMargin && in_array($marginMode, ['135', '145', '155'], true)) {
                        $price = round((float) $product['purchase_price'] * ((float) $marginMode / 100), 2);
                    }
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

    private function splitIncludedTax(float $totalTtc, float $vatRate): array
    {
        $totalTtc = round(max(0, $totalTtc), 2);
        $factor = 1 + (max(0, $vatRate) / 100);
        $subtotalHt = $factor > 0 ? round($totalTtc / $factor, 2) : $totalTtc;

        return [
            'ht' => $subtotalHt,
            'vat' => round($totalTtc - $subtotalHt, 2),
            'ttc' => $totalTtc,
        ];
    }

    private function emptyPaymentBreakdown(): array
    {
        return [
            'ESP' => ['count' => 0, 'total_ttc' => 0.0],
            'CHQ' => ['count' => 0, 'total_ttc' => 0.0],
            'CB' => ['count' => 0, 'total_ttc' => 0.0],
            'VIR' => ['count' => 0, 'total_ttc' => 0.0],
        ];
    }

    private function financialLinesForOperations(array $operationIds): array
    {
        $operationIds = array_values(array_unique(array_map('intval', $operationIds)));
        if (!$operationIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($operationIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT oi.*, p.product_type, p.purchase_price, p.sku AS product_sku
             FROM operation_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.operation_id IN (' . $placeholders . ')
             ORDER BY oi.operation_id, oi.id'
        );
        $stmt->execute($operationIds);

        $lines = [];
        foreach ($stmt->fetchAll() as $line) {
            $lines[(int) $line['operation_id']][] = $line;
        }

        return $lines;
    }

    private function financialTotalsForLines(array $lines, float $vatRate): array
    {
        $totals = ['cost_ht' => 0.0, 'margin' => 0.0, 'has_estimated_lines' => false];
        foreach ($lines as $line) {
            $decorated = $this->decorateFinancialLine($line, $vatRate);
            $totals['cost_ht'] += (float) $decorated['cost_ht'];
            $totals['margin'] += (float) $decorated['margin'];
            $totals['has_estimated_lines'] = $totals['has_estimated_lines'] || (bool) $decorated['is_estimated'];
        }

        $totals['cost_ht'] = round($totals['cost_ht'], 2);
        $totals['margin'] = round($totals['margin'], 2);

        return $totals;
    }

    private function decorateFinancialLine(array $line, float $vatRate): array
    {
        $quantity = (float) ($line['quantity'] ?? 0);
        $totalHt = round((float) ($line['total_ht'] ?? $line['total'] ?? 0), 2);
        $productId = (int) ($line['product_id'] ?? 0);
        $productType = (string) ($line['product_type'] ?? '');
        $isMissingProduct = $productId <= 0 || $productType === '';
        $isStockableProduct = !$isMissingProduct
            && (string) ($line['line_type'] ?? '') === 'product'
            && $productType === 'stockable';

        $costHt = 0.0;
        if ($isStockableProduct) {
            $purchasePriceTtc = (float) ($line['purchase_price'] ?? 0);
            $factor = 1 + (max(0, $vatRate) / 100);
            $unitCostHt = $factor > 0 ? $purchasePriceTtc / $factor : $purchasePriceTtc;
            $costHt = round($unitCostHt * $quantity, 2);
        }

        $margin = round($totalHt - $costHt, 2);
        $line['product_type'] = $productType ?: null;
        $line['quantity'] = $quantity;
        $line['unit_price'] = (float) ($line['unit_price'] ?? 0);
        $line['total_ht'] = $totalHt;
        $line['cost_ht'] = $costHt;
        $line['margin'] = $margin;
        $line['margin_rate'] = $totalHt > 0 ? round(($margin / $totalHt) * 100, 2) : 0.0;
        $line['is_estimated'] = $isMissingProduct;

        return $line;
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
        $operation['document_title'] = match ($operation['doc_type']) {
            'quote' => 'DEVIS N°',
            'order' => 'BON DE COMMANDE N°',
            default => 'FACTURE N°',
        };
        $operation['amount_intro'] = match ($operation['doc_type']) {
            'quote' => 'Arrete le present devis a la somme de :',
            'order' => 'Arrete le present bon de commande a la somme de :',
            default => 'Arretee la presente facture a la somme de :',
        };
        $operation['legal_notice'] = match ($operation['doc_type']) {
            'quote' => 'Devis valable 30 jours',
            'order' => 'Bon de commande',
            default => '',
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

    private function validateStockMovementPayload(array $data, int $productId): array
    {
        $type = (string) ($data['movement_type'] ?? 'in');
        if (!in_array($type, ['in', 'out', 'adjustment'], true)) {
            throw new InvalidArgumentException('نوع الحركة غير صحيح');
        }
        $quantity = (int) ($data['quantity'] ?? 0);
        if ($type === 'adjustment') {
            if ($quantity === 0) {
                throw new InvalidArgumentException('كمية التعديل لا يمكن أن تكون 0');
            }
        } elseif ($quantity <= 0) {
            throw new InvalidArgumentException('الكمية يجب أن تكون أكبر من 0');
        }
        $note = trim((string) ($data['note'] ?? ''));
        if ($note === '') {
            throw new InvalidArgumentException('سبب التعديل إجباري');
        }
        $supplierId = (int) ($data['supplier_id'] ?? 0);
        $unitCost = ($data['unit_cost'] ?? '') !== '' ? (float) $data['unit_cost'] : null;
        if ($unitCost !== null && $unitCost < 0) {
            throw new InvalidArgumentException('ثمن الشراء يجب أن يكون 0 أو أكثر');
        }
        if ($supplierId > 0 && !$this->supplier($supplierId)) {
            throw new InvalidArgumentException('المورد غير موجود');
        }
        if (!$this->product($productId)) {
            throw new InvalidArgumentException('المنتج غير موجود');
        }

        return [
            'movement_type' => $type,
            'quantity' => $quantity,
            'note' => $note,
            'supplier_id' => $supplierId ?: null,
            'unit_cost' => $unitCost,
        ];
    }

    private function movementEffect(string $type, int $quantity): int
    {
        return match ($type) {
            'in' => abs($quantity),
            'out' => -abs($quantity),
            'adjustment' => $quantity,
            default => 0,
        };
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
    ref_universal TEXT,
    ref_company TEXT,
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
        $this->addColumnIfMissing('products', 'ref_universal', 'TEXT');
        $this->addColumnIfMissing('products', 'ref_company', 'TEXT');
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
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_ref_universal ON products(ref_universal)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_ref_company ON products(ref_company)');
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_products_ref_company_unique ON products(ref_company) WHERE ref_company IS NOT NULL AND trim(ref_company) <> ''");

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
            'invoice' => 'INV',
            default => 'DEV',
        };
        $column = match ($type) {
            'order' => 'order_no',
            'invoice' => 'invoice_no',
            default => 'quote_no',
        };
        $period = date('Ym');
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) + 1
             FROM operations
             WHERE doc_type = :doc_type
               AND strftime('%Y%m', created_at) = :period"
        );
        $stmt->execute(['doc_type' => $type, 'period' => $period]);
        $next = (int) $stmt->fetchColumn();
        do {
            $number = $prefix . '/' . $period . '/' . $next;
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
            'operation' => 'operations',
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
            'operation' => ['documents lies' => $this->countWhere('operations', 'parent_id', $id)],
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
