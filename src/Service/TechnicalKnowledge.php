<?php

namespace App\Service;

/** Read-only history, one surviving document per chain, grouped primarily by brand. */
class TechnicalKnowledge
{
    public function __construct(private AppDatabase $db)
    {
        $db->pdo()->sqliteCreateFunction('knowledge_normalize', self::normalize(...), 1, \PDO::SQLITE_DETERMINISTIC);
    }

    public static function normalize(?string $value): string
    {
        $value = mb_strtolower(preg_replace('/\s+/u', ' ', trim($value ?? '')));
        if (class_exists(\Normalizer::class)) {
            $value = preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($value, \Normalizer::FORM_D));
        }
        return trim($value);
    }

    public function filters(array $query): array
    {
        $filters = [];
        foreach (['brand', 'model', 'q', 'from', 'to', 'plate'] as $key) {
            $filters[$key] = is_scalar($query[$key] ?? '') ? trim((string) ($query[$key] ?? '')) : '';
        }
        $valid = true;
        foreach (['from', 'to'] as $key) {
            $date = $filters[$key];
            if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))) {
                $valid = false;
            }
        }
        if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) {
            $valid = false;
        }
        if (!$valid) {
            $filters['from'] = $filters['to'] = '';
        }
        return ['filters' => $filters, 'invalid_dates' => !$valid];
    }

    private function historyLinesSql(): string
    {
        $sql = <<<'SQL'
SELECT o.id AS operation_id, o.doc_type, o.invoice_no, o.created_at,
       COALESCE(NULLIF(trim(vb.name), ''), trim(o.vehicle_brand), '') AS brand,
       COALESCE(NULLIF(trim(vm.name), ''), trim(o.vehicle_model), '') AS model,
       COALESCE(NULLIF(trim(v.plate), ''), o.vehicle_plate, '') AS plate,
       COALESCE(NULLIF(o.client_name, ''), c.name, '') AS client_name,
       i.id AS item_id, i.product_id, i.label, i.quantity,
       CASE WHEN i.line_type = 'service' OR p.product_type = 'service' THEN 'service' ELSE 'product' END AS line_type,
       COALESCE(NULLIF(p.name, ''), i.label) AS name,
       COALESCE(NULLIF(p.ref_company, ''), p.sku, '') AS reference
FROM operations o
JOIN operation_items i ON i.operation_id = o.id
LEFT JOIN vehicles v ON v.id = o.vehicle_id
LEFT JOIN vehicle_brands vb ON vb.id = v.brand_id
LEFT JOIN vehicle_models vm ON vm.id = v.model_id
LEFT JOIN clients c ON c.id = o.client_id
LEFT JOIN products p ON p.id = i.product_id
WHERE o.doc_type IN ('quote', 'order', 'invoice')
SQL;
        // Do not count quote + order + invoice copies of the same intervention.
        // Apply this before dates/search so a filtered-out child never resurrects its parent.
        $sql .= " AND NOT EXISTS (SELECT 1 FROM operations child WHERE child.parent_id = o.id AND child.doc_type IN ('quote', 'order', 'invoice'))";
        return $sql;
    }

    public function options(array $filters = []): array
    {
        // Master records and historical text both participate, even without an invoice or a model.
        $rows = $this->db->pdo()->query("SELECT name AS brand, '' AS model FROM vehicle_brands ORDER BY name")->fetchAll();
        $rows = array_merge($rows, $this->db->pdo()->query('SELECT vb.name AS brand, vm.name AS model FROM vehicle_models vm LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id ORDER BY vm.name')->fetchAll(),
            $this->db->pdo()->query("SELECT DISTINCT COALESCE(NULLIF(trim(vb.name), ''), o.vehicle_brand, '') AS brand,
                COALESCE(NULLIF(trim(vm.name), ''), o.vehicle_model, '') AS model
                FROM operations o LEFT JOIN vehicles v ON v.id = o.vehicle_id
                LEFT JOIN vehicle_brands vb ON vb.id = v.brand_id LEFT JOIN vehicle_models vm ON vm.id = v.model_id
                ORDER BY brand, model")->fetchAll());
        $brands = $models = [];
        foreach ($rows as $row) {
            $brand = trim(preg_replace('/\s+/u', ' ', $row['brand'] ?? ''));
            $model = trim(preg_replace('/\s+/u', ' ', $row['model'] ?? ''));
            if ($brand !== '') $brands[self::normalize($brand)] ??= $brand;
            if ($model !== '') $models[self::normalize($model)] ??= $model;
        }
        natcasesort($brands);
        natcasesort($models);
        return ['brands' => array_values($brands), 'models' => array_values($models),
            'brand_selected' => $brands[self::normalize($filters['brand'] ?? '')] ?? ($filters['brand'] ?? ''),
            'model_selected' => $models[self::normalize($filters['model'] ?? '')] ?? ($filters['model'] ?? '')];
    }

    public function search(array $query = []): array
    {
        $filters = $this->filters($query)['filters'];
        $where = [];
        $params = [];
        foreach (['brand', 'model', 'plate'] as $key) {
            if ($filters[$key] !== '') {
                $where[] = 'knowledge_normalize(' . $key . ') = :' . $key;
                $params[$key] = self::normalize($filters[$key]);
            }
        }
        if ($filters['q'] !== '') {
            // Escape LIKE wildcards: a part name is a literal substring.
            $where[] = "(knowledge_normalize(name) LIKE :q ESCAPE '!' OR knowledge_normalize(label) LIKE :q ESCAPE '!')";
            $params['q'] = '%' . strtr(self::normalize($filters['q']), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        if ($filters['from'] !== '') {
            $where[] = 'created_at >= :from';
            $params['from'] = $filters['from'];
        }
        if ($filters['to'] !== '') {
            $where[] = 'created_at < :until';
            $params['until'] = (new \DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d');
        }
        $stmt = $this->db->pdo()->prepare('SELECT * FROM (' . $this->historyLinesSql() . ')'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC, operation_id DESC, item_id');
        $stmt->execute($params);
        $groups = [];
        $canonicalBrands = [];
        foreach ($this->options()['brands'] as $brand) $canonicalBrands[self::normalize($brand)] = $brand;
        // Stream one query for both totals and detail, without per-model or per-operation queries.
        while ($row = $stmt->fetch()) {
            $key = self::normalize($row['brand']);
            if (!isset($groups[$key])) {
                $groups[$key] = ['brand' => $canonicalBrands[$key] ?? $row['brand'], 'model' => '', 'models' => [], 'products' => [], 'services' => [], 'operations' => []];
            }
            $group = &$groups[$key];
            if (self::normalize($row['model']) !== '') $group['models'][self::normalize($row['model'])] = $row['model'];
            $bucket = $row['line_type'] === 'service' ? 'services' : 'products';
            $itemKey = $row['product_id'] !== null ? 'id:' . $row['product_id'] : 'label:' . self::normalize($row['label']);
            if (!isset($group[$bucket][$itemKey])) {
                $group[$bucket][$itemKey] = ['name' => $row['name'], 'reference' => $row['reference'], 'quantity' => 0, 'operation_ids' => []];
            }
            $item = &$group[$bucket][$itemKey];
            $item['quantity'] += (float) $row['quantity'];
            $item['operation_ids'][$row['operation_id']] = true;
            $id = (int) $row['operation_id'];
            if (!isset($group['operations'][$id])) {
                $group['operations'][$id] = ['id' => $id, 'number' => $row['invoice_no'], 'doc_type' => $row['doc_type'], 'model' => $row['model'], 'date' => $row['created_at'], 'plate' => $row['plate'], 'client' => $row['client_name'], 'lines' => []];
            }
            $group['operations'][$id]['lines'][] = ['label' => $row['label'], 'quantity' => (float) $row['quantity'], 'line_type' => $row['line_type']];
            unset($item, $group);
        }
        foreach ($groups as &$group) {
            natcasesort($group['models']);
            $group['models'] = array_values($group['models']);
            $group['model'] = implode(' / ', $group['models']);
            $group['uses'] = count($group['operations']);
            foreach (['products', 'services'] as $bucket) {
                foreach ($group[$bucket] as &$item) {
                    $item['uses'] = count($item['operation_ids']);
                    unset($item['operation_ids']);
                }
                unset($item);
                $group[$bucket] = array_values($group[$bucket]);
                usort($group[$bucket], static fn (array $a, array $b): int => ($b['uses'] <=> $a['uses']) ?: strnatcasecmp($a['name'], $b['name']));
            }
            $group['operations'] = array_values($group['operations']);
        }
        unset($group);
        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => ($b['uses'] <=> $a['uses']) ?: strnatcasecmp($a['brand'] . ' ' . $a['model'], $b['brand'] . ' ' . $b['model']));
        return $groups;
    }
}
