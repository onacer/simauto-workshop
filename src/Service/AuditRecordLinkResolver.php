<?php

namespace App\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Resolve current records at display time; never store URLs in audit rows. */
final class AuditRecordLinkResolver
{
    private const TARGETS = [
        'operation' => ['operations', 'app_operation_show'],
        'product' => ['products', 'app_product_show'],
        'client' => ['clients', 'app_client_show'],
        'supplier' => ['suppliers', 'app_supplier_show'],
        'vehicle' => ['vehicles', 'app_vehicle_show'],
        'category' => ['categories', 'app_category_show'],
        'user' => ['users', 'app_users'],
    ];

    public function __construct(
        private readonly AppDatabase $db,
        private readonly UrlGeneratorInterface $router,
        private readonly AccessControl $access,
    ) {
    }

    public function resolve(?string $entityType, ?int $entityId, array $user): ?string
    {
        if (!$this->access->can('audit.view', $user) || $entityId === null || $entityId <= 0) {
            return null;
        }

        if ($entityType === 'stock_movement') {
            $stmt = $this->db->pdo()->prepare('SELECT p.id FROM stock_movements sm JOIN products p ON p.id = sm.product_id WHERE sm.id = :id');
            $stmt->execute(['id' => $entityId]);
            $productId = $stmt->fetchColumn();
            return $productId === false ? null
                : $this->router->generate('app_product_show', ['id' => (int) $productId]) . '#mouvement-' . $entityId;
        }

        $target = self::TARGETS[$entityType ?? ''] ?? null;
        if ($target === null) {
            return null;
        }
        [$table, $route] = $target;
        // Table and route names come exclusively from the fixed mapping above.
        $stmt = $this->db->pdo()->prepare('SELECT id FROM ' . $table . ' WHERE id = :id');
        $stmt->execute(['id' => $entityId]);
        if ($stmt->fetchColumn() === false) {
            return null;
        }
        return $this->router->generate($route, $entityType === 'user' ? [] : ['id' => $entityId]);
    }
}
