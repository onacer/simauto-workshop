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
            'edit.reference',
            'edit.quote_draft',
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

    public function canEditDocument(array $user, array $operation): bool
    {
        $isDraftQuote = ($operation['doc_type'] ?? '') === 'quote'
            && ($operation['status'] ?? '') === 'draft';

        if (!$isDraftQuote) {
            return false;
        }

        return $this->can('edit.quote_draft', $user);
    }
}
