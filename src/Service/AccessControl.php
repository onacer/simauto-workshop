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

        $managerPermissions = [
            'dashboard',
            'view',
            'create',
            'progress_document',
            'products',
            'stock',
            'categories',
            'suppliers',
            'clients',
            'vehicles',
            'operations',
            'billing',
        ];

        return in_array($permission, $managerPermissions, true);
    }
}
