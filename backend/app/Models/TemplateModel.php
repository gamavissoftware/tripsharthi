<?php

declare(strict_types=1);

namespace App\Models;

class TemplateModel extends BaseModel
{
    protected $table      = 'templates';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'name', 'display_name', 'language', 'category',
        'header_type', 'header_content', 'body', 'footer', 'buttons', 'cards',
        'variables', 'meta_template_id', 'meta_status', 'rejection_reason',
        'submitted_at',
    ];

    /** Only approved templates may be used in campaigns or sent from the inbox. */
    public function findApproved(int $tenantId): array
    {
        return $this->setTenant($tenantId)
                    ->where('meta_status', 'approved')
                    ->orderBy('display_name', 'ASC')
                    ->findAll();
    }

    /**
     * Find by Meta's template ID — cross-tenant (used in webhook status updates).
     */
    public function findByMetaTemplateId(string $metaTemplateId): array|object|null
    {
        return $this->withoutTenantScope()
                    ->where('meta_template_id', $metaTemplateId)
                    ->first();
    }

    /**
     * Update status from a Meta webhook or sync call.
     * Maps Meta's uppercase status strings to our lowercase enum values.
     */
    public function updateMetaStatus(int $id, string $metaStatus, ?string $rejectionReason = null): void
    {
        $normalized = strtolower($metaStatus);
        $allowed    = ['draft', 'pending', 'approved', 'rejected', 'paused', 'disabled'];

        if (! in_array($normalized, $allowed, true)) {
            log_message('warning', "TemplateModel::updateMetaStatus — unknown status '{$metaStatus}' for template #{$id}");
            return;
        }

        $update = ['meta_status' => $normalized];
        if ($rejectionReason !== null) {
            $update['rejection_reason'] = $rejectionReason;
        }

        $this->withoutTenantScope()->update($id, $update);
    }
}
