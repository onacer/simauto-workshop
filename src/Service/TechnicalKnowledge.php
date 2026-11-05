<?php

namespace App\Service;

/** Read-only reference derived exclusively from invoice lines. */
class TechnicalKnowledge
{
    public function __construct(private AppDatabase $db)
    {
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

    private function invoiceLinesSql(): string
    {
        return <<<'SQL'
SELECT o.id AS operation_id, o.invoice_no, o.created_at,
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
WHERE o.doc_type = 'invoice'
SQL;
    }

    public function options(): array
    {
        $rows = $this->db->pdo()->query('SELECT DISTINCT brand, model FROM (' . $this->invoiceLinesSql() . ') ORDER BY brand, model')->fetchAll();
        $brands = $models = [];
        foreach ($rows as $row) {
            if ($row['brand'] !== '') $brands[$row['brand']] = $row['brand'];
            if ($row['model'] !== '') $models[$row['model']] = $row['model'];
        }
        natcasesort($brands);
        natcasesort($models);
        return ['brands' => array_values($brands), 'models' => array_values($models)];
    }

    public function search(array $query = []): array
    {
        $filters = $this->filters($query)['filters'];
        $where = [];
        $params = [];
        foreach (['brand', 'model', 'plate'] as $key) {
            if ($filters[$key] !== '') {
                $where[] = 'lower(trim(' . $key . ')) = lower(:' . $key . ')';
                $params[$key] = $filters[$key];
            }
        }
        if ($filters['q'] !== '') {
            // Escape LIKE wildcards: a part name is a literal substring.
            $where[] = "(name LIKE :q ESCAPE '!' OR label LIKE :q ESCAPE '!')";
            $params['q'] = '%' . strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        if ($filters['from'] !== '') {
            $where[] = 'created_at >= :from';
            $params['from'] = $filters['from'];
        }
        if ($filters['to'] !== '') {
            $where[] = 'created_at < :until';
            $params['until'] = (new \DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d');
        }
        $stmt = $this->db->pdo()->prepare('SELECT * FROM (' . $this->invoiceLinesSql() . ')'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC, operation_id DESC, item_id');
        $stmt->execute($params);
        $groups = [];
        // Stream one query for both totals and detail, without per-model or per-operation queries.
        while ($row = $stmt->fetch()) {
            $key = json_encode([mb_strtolower($row['brand']), mb_strtolower($row['model'])]);
            if (!isset($groups[$key])) {
                $groups[$key] = ['brand' => $row['brand'], 'model' => $row['model'], 'products' => [], 'services' => [], 'operations' => []];
            }
            $group = &$groups[$key];
            $bucket = $row['line_type'] === 'service' ? 'services' : 'products';
            $itemKey = $row['product_id'] !== null ? 'id:' . $row['product_id'] : 'label:' . mb_strtolower(trim($row['label']));
            if (!isset($group[$bucket][$itemKey])) {
                $group[$bucket][$itemKey] = ['name' => $row['name'], 'reference' => $row['reference'], 'quantity' => 0, 'operation_ids' => []];
            }
            $item = &$group[$bucket][$itemKey];
            $item['quantity'] += (float) $row['quantity'];
            $item['operation_ids'][$row['operation_id']] = true;
            $id = (int) $row['operation_id'];
            if (!isset($group['operations'][$id])) {
                $group['operations'][$id] = ['id' => $id, 'number' => $row['invoice_no'], 'date' => $row['created_at'], 'plate' => $row['plate'], 'client' => $row['client_name'], 'lines' => []];
            }
            $group['operations'][$id]['lines'][] = ['label' => $row['label'], 'quantity' => (float) $row['quantity'], 'line_type' => $row['line_type']];
            unset($item, $group);
        }
        foreach ($groups as &$group) {
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
