<?php

namespace App\Service;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ImportService
{
    private const LIMIT = 2000;

    private const DEFINITIONS = [
        'clients' => [
            'label' => 'import.entities.clients',
            'headers' => ['type', 'name', 'surname', 'phone', 'email', 'address', 'ice', 'vat', 'rc'],
            'examples' => [
                ['individual', 'Ahmed', 'Alami', '0611111111', 'ahmed@example.com', 'Agadir', '', '', ''],
                ['company', 'SIM Client', '', '0622222222', 'client@example.com', 'Agadir', '003151412000001', '53237142', '53425'],
            ],
        ],
        'suppliers' => [
            'label' => 'import.entities.suppliers',
            'headers' => ['name', 'phone', 'email', 'address', 'ice'],
            'examples' => [
                ['Pieces Auto Sud', '0633333333', 'supplier@example.com', 'Agadir', '003151412000002'],
                ['Huile Maroc', '0644444444', '', 'Casablanca', ''],
            ],
        ],
        'brands' => [
            'label' => 'import.entities.brands',
            'headers' => ['name'],
            'examples' => [['Dacia'], ['Volkswagen']],
        ],
        'models' => [
            'label' => 'import.entities.models',
            'headers' => ['brand_name', 'name'],
            'examples' => [['Dacia', 'Logan'], ['Volkswagen', 'Tiguan']],
        ],
        'vehicles' => [
            'label' => 'import.entities.vehicles',
            'headers' => ['client_phone_ou_email', 'plate', 'brand_name', 'model_name', 'year', 'mileage', 'notes'],
            'examples' => [
                ['0611111111', '15428-A-32', 'VW', 'Tiguan', '2021', '84000', ''],
                ['client@example.com', '1000-A-1', 'Dacia', 'Logan', '2019', '120000', ''],
            ],
        ],
        'products' => [
            'label' => 'import.entities.products',
            'headers' => ['sku', 'ref_universal', 'ref_company', 'name', 'category_name', 'stock_qty', 'min_qty', 'purchase_price', 'sale_price'],
            'examples' => [
                ['FILT-AIR-001', 'OEM-AIR-001', 'SIM-AIR-001', 'FILTRE A AIR', 'Filtres', '10', '2', '35', '60'],
                ['HUILE-5W30-001', 'OEM-5W30-001', 'SIM-HUILE-001', 'HUILE MOTEUR 5W30', 'Huiles', '5', '1', '250', '400'],
            ],
        ],
    ];

    public function __construct(private readonly AppDatabase $db)
    {
    }

    public function entities(): array
    {
        return self::DEFINITIONS;
    }

    public function template(string $entity): string
    {
        $definition = $this->definition($entity);
        $rows = [$definition['headers'], ...$definition['examples']];
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }
        rewind($handle);
        return "\xEF\xBB\xBF" . stream_get_contents($handle);
    }

    public function import(string $entity, string $path, int $userId): array
    {
        $definition = $this->definition($entity);
        $rows = $this->readRows($path, $definition['headers']);
        $report = [
            'entity' => $entity,
            'label' => $definition['label'],
            'processed' => count($rows),
            'created' => 0,
            'updated' => 0,
            'ignored' => 0,
            'errors' => [],
        ];

        $seenProductRefs = [];
        foreach ($rows as $lineNumber => $row) {
            try {
                if ($entity === 'products') {
                    $refCompany = mb_strtolower(trim((string) ($row['ref_company'] ?? '')));
                    if ($refCompany !== '' && isset($seenProductRefs[$refCompany])) {
                        throw new InvalidArgumentException('مرجع الشركة مكرر في الملف');
                    }
                    if ($refCompany !== '') {
                        $seenProductRefs[$refCompany] = true;
                    }
                }
                $status = $this->importRow($entity, $row, $userId);
                $report[$status]++;
            } catch (Throwable $e) {
                $report['errors'][] = [
                    'line' => $lineNumber,
                    'column' => '-',
                    'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'تعذر استيراد السطر',
                ];
                $report['ignored']++;
            }
        }

        return $report;
    }

    private function definition(string $entity): array
    {
        if (!isset(self::DEFINITIONS[$entity])) {
            throw new InvalidArgumentException('نوع الاستيراد غير معروف');
        }
        return self::DEFINITIONS[$entity];
    }

    private function readRows(string $path, array $expectedHeaders): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new RuntimeException('تعذر قراءة الملف');
        }

        $headers = fgetcsv($handle, 0, ';');
        if (!$headers) {
            throw new InvalidArgumentException('الملف فارغ');
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $headers = array_map(fn ($value) => trim((string) $value), $headers);
        if ($headers !== $expectedHeaders) {
            throw new InvalidArgumentException('رؤوس الأعمدة غير صحيحة');
        }

        $rows = [];
        $line = 1;
        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $line++;
            if ($line > self::LIMIT + 1) {
                throw new InvalidArgumentException('الملف يتجاوز الحد الأقصى 2000 سطر');
            }
            if ($this->isEmptyRow($data)) {
                continue;
            }
            $data = array_pad($data, count($headers), '');
            $rows[$line] = array_combine($headers, array_map(fn ($value) => trim((string) $value), array_slice($data, 0, count($headers))));
        }

        fclose($handle);
        return $rows;
    }

    private function importRow(string $entity, array $row, int $userId): string
    {
        return match ($entity) {
            'clients' => $this->importClient($row),
            'suppliers' => $this->importSupplier($row),
            'brands' => $this->importBrand($row),
            'models' => $this->importModel($row),
            'vehicles' => $this->importVehicle($row),
            'products' => $this->importProduct($row, $userId),
        };
    }

    private function importClient(array $row): string
    {
        $name = trim($row['name'] . ' ' . ($row['surname'] ?? ''));
        $payload = [
            'type' => $row['type'] === 'company' ? 'company' : 'individual',
            'name' => $name,
            'phone' => $row['phone'],
            'email' => mb_strtolower($row['email']),
            'address' => $row['address'],
            'ice' => $row['ice'],
            'vat' => $row['vat'],
            'rc' => $row['rc'],
        ];
        $existing = $this->db->clientByPhoneOrEmail($payload['phone'] ?: $payload['email']);
        if ($existing && $this->same($existing, $payload, ['type', 'name', 'phone', 'email', 'address', 'ice', 'vat', 'rc'])) {
            return 'ignored';
        }
        $this->db->saveClient($payload, $existing ? (int) $existing['id'] : null);
        return $existing ? 'updated' : 'created';
    }

    private function importSupplier(array $row): string
    {
        $existing = $this->db->supplierByName($row['name']);
        $payload = [
            'name' => $row['name'],
            'phone' => $row['phone'],
            'email' => mb_strtolower($row['email']),
            'address' => $row['address'],
            'ice' => $row['ice'],
        ];
        if ($existing && $this->same($existing, $payload, ['name', 'phone', 'email', 'address', 'ice'])) {
            return 'ignored';
        }
        $this->db->saveSupplier($payload, $existing ? (int) $existing['id'] : null);
        return $existing ? 'updated' : 'created';
    }

    private function importBrand(array $row): string
    {
        $existing = $this->db->vehicleBrandByName($row['name']);
        $this->db->getOrCreateVehicleBrand($row['name']);
        return $existing ? 'ignored' : 'created';
    }

    private function importModel(array $row): string
    {
        $brandId = $this->db->getOrCreateVehicleBrand($row['brand_name']);
        $existing = $this->db->vehicleModelByName($brandId, $row['name']);
        $this->db->getOrCreateVehicleModel($brandId, $row['name']);
        return $existing ? 'ignored' : 'created';
    }

    private function importVehicle(array $row): string
    {
        $client = $this->db->clientByPhoneOrEmail($row['client_phone_ou_email']);
        if (!$client) {
            throw new InvalidArgumentException('الزبون غير موجود');
        }
        $brandId = $this->db->getOrCreateVehicleBrand($row['brand_name']);
        $modelId = $this->db->getOrCreateVehicleModel($brandId, $row['model_name']);
        $payload = [
            'client_id' => (int) $client['id'],
            'plate' => $row['plate'],
            'brand_id' => $brandId,
            'model_id' => $modelId,
            'year' => (int) $row['year'],
            'mileage' => (int) $row['mileage'],
            'notes' => $row['notes'],
        ];
        $existing = $this->db->vehicleByPlate($row['plate']);
        if ($existing && $this->same($existing, $payload, ['client_id', 'plate', 'brand_id', 'model_id', 'year', 'mileage', 'notes'])) {
            return 'ignored';
        }
        $this->db->saveVehicle($payload, $existing ? (int) $existing['id'] : null);
        return $existing ? 'updated' : 'created';
    }

    private function importProduct(array $row, int $userId): string
    {
        foreach (['stock_qty', 'min_qty', 'purchase_price', 'sale_price'] as $number) {
            if ((float) $row[$number] < 0) {
                throw new InvalidArgumentException('القيمة يجب أن تكون 0 أو أكثر');
            }
        }
        $categoryId = $this->db->getOrCreateCategory($row['category_name']);
        $payload = [
            'sku' => $row['sku'],
            'ref_universal' => $row['ref_universal'] ?? '',
            'ref_company' => $row['ref_company'] ?? '',
            'name' => $row['name'],
            'category_id' => $categoryId,
            'stock_qty' => (int) $row['stock_qty'],
            'min_qty' => (int) $row['min_qty'],
            'purchase_price' => (float) $row['purchase_price'],
            'sale_price' => (float) $row['sale_price'],
        ];
        $existing = $this->db->productBySku($row['sku']);
        if ($existing) {
            $compare = $payload;
            unset($compare['stock_qty']);
            if ($this->same($existing, $compare, ['sku', 'ref_universal', 'ref_company', 'name', 'category_id', 'min_qty', 'purchase_price', 'sale_price'])) {
                return 'ignored';
            }
            $this->db->saveProduct($payload, $userId, (int) $existing['id']);
            return 'updated';
        }

        $this->db->saveProduct($payload, $userId);
        return 'created';
    }

    private function same(array $existing, array $payload, array $keys): bool
    {
        foreach ($keys as $key) {
            if (trim((string) ($existing[$key] ?? '')) !== trim((string) ($payload[$key] ?? ''))) {
                return false;
            }
        }
        return true;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }
}
