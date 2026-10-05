<?php

namespace App\Service;

use Throwable;

/** Audit writes run after business commits and must never invalidate them. */
class ActivityLogger
{
    private const TABLES = [
        'product' => 'products', 'client' => 'clients', 'supplier' => 'suppliers',
        'vehicle' => 'vehicles', 'category' => 'categories', 'user' => 'users',
        'vehicle_brand' => 'vehicle_brands', 'vehicle_model' => 'vehicle_models',
        'brand' => 'vehicle_brands', 'model' => 'vehicle_models',
        'stock_movement' => 'stock_movements',
    ];

    public function __construct(private AppDatabase $db)
    {
    }

    public function log(?array $user, string $actionType, ?string $entityType, ?int $entityId, string $description): void
    {
        try {
            // Do not participate in a business transaction, even if accidentally called early.
            if ($this->db->pdo()->inTransaction()) {
                return;
            }
            $stmt = $this->db->pdo()->prepare('INSERT INTO activity_logs (user_id, user_name, action_type, entity_type, entity_id, description) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$user['id'] ?? null, $user['name'] ?? 'System', $actionType, $entityType, $entityId, $description]);
        } catch (Throwable) {
            // Best effort: stock, invoices and other successful writes remain valid.
        }
    }

    public function logRecord(?array $user, string $actionType, string $entityType, ?int $entityId, string $description, array $identity = []): void
    {
        try {
            $table = self::TABLES[$entityType] ?? null;
            if ($table) {
                $where = []; $params = [];
                if ($entityId !== null) {
                    $where[] = 'id = ?'; $params[] = $entityId;
                } elseif ($identity !== []) {
                    $key = match ($entityType) {
                        'vehicle' => 'plate', 'user' => 'email', 'stock_movement' => 'product_id',
                        default => 'name',
                    };
                    $value = trim((string) ($identity[$key] ?? ''));
                    $where[] = $key . ' = ?'; $params[] = $key === 'email' ? mb_strtolower($value) : $value;
                    if ($entityType === 'stock_movement') {
                        $where[] = 'created_by = ?'; $params[] = $user['id'] ?? null;
                        $where[] = "movement_type = 'in'";
                    }
                    if ($entityType === 'vehicle_model') {
                        $where[] = 'brand_id = ?'; $params[] = (int) ($identity['brand_id'] ?? 0);
                    }
                }
                $stmt = $this->db->pdo()->prepare('SELECT * FROM ' . $table . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 1');
                $stmt->execute($params);
                $record = $stmt->fetch();
                if ($record) {
                    $entityId = (int) $record['id'];
                    $description .= ' — ' . ($record['name'] ?? $record['plate'] ?? ('#' . $entityId));
                    if ($entityType === 'stock_movement') {
                        $product = $this->db->product((int) $record['product_id']);
                        $description .= ' — ' . ($product['name'] ?? '') . ' — ' . $record['quantity'];
                    }
                }
            }
            $this->log($user, $actionType, $entityType, $entityId, $description);
        } catch (Throwable) {
        }
    }

    public function logOperation(?array $user, string $actionType, int $id, string $description): void
    {
        try {
            $operation = $this->db->operation($id);
            if ($operation) {
                $description .= ' ' . $operation['document_no'] . ' — ' . $operation['client_name']
                    . ' — ' . number_format((float) $operation['total_ttc'], 2, ',', ' ') . ' DH';
            }
            $this->log($user, $actionType, 'operation', $id, $description);
        } catch (Throwable) {
        }
    }

    public function search(array $filters): array
    {
        $where = []; $params = [];
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(description LIKE :q OR user_name LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        foreach (['action_type', 'user_id'] as $key) {
            if (($filters[$key] ?? '') !== '') {
                $where[] = $key . ' = :' . $key;
                $params[$key] = $filters[$key];
            }
        }
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'created_at >= :date_from';
            $params['date_from'] = $filters['from'] . ' 00:00:00';
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = 'created_at < :date_until';
            $params['date_until'] = (new \DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        $sql = ' FROM activity_logs' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $count = $this->db->pdo()->prepare('SELECT COUNT(*)' . $sql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $stmt = $this->db->pdo()->prepare('SELECT *' . $sql . ' ORDER BY created_at DESC, id DESC LIMIT 200');
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'limited' => $total > 200];
    }

    public function actors(): array
    {
        return $this->db->pdo()->query('SELECT user_id, user_name FROM activity_logs WHERE id IN (SELECT MAX(id) FROM activity_logs WHERE user_id IS NOT NULL GROUP BY user_id) ORDER BY user_name')->fetchAll();
    }

    public function actionTypes(): array
    {
        return $this->db->pdo()->query('SELECT DISTINCT action_type FROM activity_logs ORDER BY action_type')->fetchAll(\PDO::FETCH_COLUMN);
    }
}
