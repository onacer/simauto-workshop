<?php

namespace App\Service;

class AccessControl
{
    public function can(string $permission, array $user): bool
    {
        $role = (string) ($user['role'] ?? '');

        if ($role === 'admin') {
            return true;
        }

        if ($role !== 'manager') {
            return false;
        }

        $managerViewPermissions = [
            'view.dashboard',
            'view.products',
            'view.stock',
            'view.categories',
            'view.suppliers',
            'view.clients',
            'view.vehicles',
            'view.vehicle_settings',
            'view.operations',
            'view.billing',
            'view.documents',
        ];

        $managerActionPermissions = [
            'create',
            'progress_document',
        ];

        $legacyViewAliases = [
            'dashboard',
            'view',
            'products',
            'stock',
            'categories',
            'suppliers',
            'clients',
            'vehicles',
            'operations',
            'billing',
        ];

        return in_array($permission, $managerViewPermissions, true)
            || in_array($permission, $managerActionPermissions, true)
            || in_array($permission, $legacyViewAliases, true);
    }
}
