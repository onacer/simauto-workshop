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

        return in_array($permission, [
            'dashboard',
            'products',
            'stock',
            'categories',
            'suppliers',
            'clients',
            'vehicles',
            'vehicle_settings',
            'operations',
            'billing',
            'reports.view',
            'imports',
            'edit',
            'create',
            'delete',
        ], true);
    }
}
