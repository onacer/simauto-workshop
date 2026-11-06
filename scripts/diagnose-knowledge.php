<?php

// Standalone read-only diagnostic: no AppDatabase construction, migration or seed.
// php scripts/diagnose-knowledge.php /path/to/simauto.sqlite Hyundai [client-name]
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\TechnicalKnowledge;

if (!isset($argv[1]) || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php scripts/diagnose-knowledge.php <existing.sqlite> [brand] [client-name]\n");
    exit(1);
}
$pdo = new PDO('sqlite:' . realpath($argv[1]), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA query_only = ON');
$pdo->sqliteCreateFunction('knowledge_normalize', TechnicalKnowledge::normalize(...), 1, PDO::SQLITE_DETERMINISTIC);
$brand = TechnicalKnowledge::normalize($argv[2] ?? '');
$client = TechnicalKnowledge::normalize($argv[3] ?? '');
$query = $pdo->prepare(<<<'SQL'
SELECT o.id, o.doc_type, o.status, o.parent_id, o.vehicle_id,
       o.vehicle_brand AS historical_brand, o.vehicle_model AS historical_model,
       vb.name AS linked_brand, vm.name AS linked_model,
       (SELECT COUNT(*) FROM operation_items i WHERE i.operation_id = o.id) AS line_count,
       EXISTS(SELECT 1 FROM operations child WHERE child.parent_id = o.id AND child.doc_type IN ('quote', 'order', 'invoice')) AS has_successor,
       CASE WHEN o.doc_type = 'invoice' THEN 1 ELSE 0 END AS previously_eligible
FROM operations o
LEFT JOIN vehicles v ON v.id = o.vehicle_id
LEFT JOIN vehicle_brands vb ON vb.id = v.brand_id
LEFT JOIN vehicle_models vm ON vm.id = v.model_id
LEFT JOIN clients c ON c.id = o.client_id
WHERE (:brand = '' OR knowledge_normalize(vb.name) = :brand OR knowledge_normalize(o.vehicle_brand) = :brand)
  AND (:client = '' OR instr(knowledge_normalize(COALESCE(c.name, o.client_name)), :client) > 0)
ORDER BY o.id
SQL);
$query->execute(['brand' => $brand, 'client' => $client]);
echo json_encode(['database' => realpath($argv[1]),
    'document_counts' => $pdo->query('SELECT doc_type, count(*) AS count FROM operations GROUP BY doc_type')->fetchAll(),
    'matching_operations' => $query->fetchAll(),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
