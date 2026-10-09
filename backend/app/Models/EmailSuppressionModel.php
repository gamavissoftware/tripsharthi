<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Addresses a tenant must never email in bulk again. Keyed on the lower-cased
 * address, not the contact, so a re-imported contact with the same email stays
 * suppressed. Rows are removed outright (no soft delete) when the owner lifts a
 * suppression.
 */
class EmailSuppressionModel extends BaseModel
{
    protected $table          = 'email_suppressions';
    protected $primaryKey     = 'id';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['tenant_id', 'email', 'contact_id', 'reason', 'source_email_id'];
}
