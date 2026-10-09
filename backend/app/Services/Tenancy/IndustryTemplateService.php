<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\CustomObjectFieldModel;
use App\Models\CustomObjectModel;

/**
 * Industry templates — one-click vertical setup. Each template provisions a
 * custom object (with its fields) for an industry, turning the generic CRM into
 * a fit-for-purpose system (real estate Properties, healthcare Patients, …).
 *
 * This is the engine behind "world-class for all industries": the same code
 * serves every vertical because the differences are data, not code.
 */
class IndustryTemplateService
{
    private const DEFINITIONS = [
        'real_estate' => [
            'name'        => 'Real Estate',
            'description' => 'Track Properties — listings, viewings and offers.',
            'object'      => ['label_singular' => 'Property', 'label_plural' => 'Properties', 'api_name' => 'property', 'icon' => '🏠', 'color' => '#0ea5e9'],
            'fields'      => [
                ['field_key' => 'address',  'label' => 'Address',     'type' => 'text',     'required' => true],
                ['field_key' => 'type',     'label' => 'Type',        'type' => 'select',   'options' => ['Apartment', 'Villa', 'Plot', 'Commercial']],
                ['field_key' => 'bedrooms', 'label' => 'Bedrooms',    'type' => 'number'],
                ['field_key' => 'area_sqft','label' => 'Area (sqft)', 'type' => 'number'],
                ['field_key' => 'price',    'label' => 'Price (₹)',   'type' => 'number'],
                ['field_key' => 'status',   'label' => 'Status',      'type' => 'select',   'options' => ['Available', 'Under Offer', 'Sold']],
            ],
        ],
        'healthcare' => [
            'name'        => 'Healthcare / Clinics',
            'description' => 'Track Patients — demographics and visit history.',
            'object'      => ['label_singular' => 'Patient', 'label_plural' => 'Patients', 'api_name' => 'patient', 'icon' => '🩺', 'color' => '#10b981'],
            'fields'      => [
                ['field_key' => 'dob',         'label' => 'Date of birth', 'type' => 'date'],
                ['field_key' => 'gender',      'label' => 'Gender',        'type' => 'select', 'options' => ['Male', 'Female', 'Other']],
                ['field_key' => 'blood_group', 'label' => 'Blood group',   'type' => 'select', 'options' => ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-']],
                ['field_key' => 'allergies',   'label' => 'Allergies',     'type' => 'textarea'],
                ['field_key' => 'last_visit',  'label' => 'Last visit',    'type' => 'date'],
            ],
        ],
        'education' => [
            'name'        => 'Education / Coaching',
            'description' => 'Track Students — admissions and enrolment.',
            'object'      => ['label_singular' => 'Student', 'label_plural' => 'Students', 'api_name' => 'student', 'icon' => '🎓', 'color' => '#8b5cf6'],
            'fields'      => [
                ['field_key' => 'course',          'label' => 'Course',          'type' => 'text'],
                ['field_key' => 'enrollment_year', 'label' => 'Enrolment year',  'type' => 'number'],
                ['field_key' => 'status',          'label' => 'Status',          'type' => 'select', 'options' => ['Inquiry', 'Applied', 'Enrolled', 'Graduated']],
                ['field_key' => 'guardian_phone',  'label' => 'Guardian phone',  'type' => 'phone'],
            ],
        ],
        'insurance' => [
            'name'        => 'Insurance',
            'description' => 'Track Policies — coverage and renewals.',
            'object'      => ['label_singular' => 'Policy', 'label_plural' => 'Policies', 'api_name' => 'policy', 'icon' => '🛡️', 'color' => '#f59e0b'],
            'fields'      => [
                ['field_key' => 'policy_number', 'label' => 'Policy number', 'type' => 'text',   'required' => true],
                ['field_key' => 'type',          'label' => 'Type',          'type' => 'select', 'options' => ['Life', 'Health', 'Motor', 'Home']],
                ['field_key' => 'premium',       'label' => 'Premium (₹)',   'type' => 'number'],
                ['field_key' => 'renewal_date',  'label' => 'Renewal date',  'type' => 'date'],
                ['field_key' => 'status',        'label' => 'Status',        'type' => 'select', 'options' => ['Active', 'Lapsed', 'Claimed']],
            ],
        ],
    ];

    /** Available templates (for the picker). */
    public function list(): array
    {
        return array_map(static fn (string $key, array $d) => [
            'key'          => $key,
            'name'         => $d['name'],
            'description'  => $d['description'],
            'object_label' => $d['object']['label_plural'],
            'icon'         => $d['object']['icon'],
        ], array_keys(self::DEFINITIONS), array_values(self::DEFINITIONS));
    }

    /**
     * Provision a template's custom object + fields for a tenant. Idempotent:
     * if the object's api_name already exists, returns it untouched.
     */
    public function apply(int $tenantId, string $key): array
    {
        $def = self::DEFINITIONS[$key] ?? null;
        if ($def === null) {
            throw new \InvalidArgumentException("Unknown industry template '{$key}'.");
        }

        $objectModel = new CustomObjectModel();
        $existing    = $objectModel->findByApiName($tenantId, $def['object']['api_name']);
        if ($existing !== null) {
            return is_array($existing) ? $existing : (array) $existing;
        }

        $objectId = (int) $objectModel->setTenant($tenantId)->insert($def['object'], true);

        $fieldModel = new CustomObjectFieldModel();
        foreach ($def['fields'] as $i => $f) {
            $fieldModel->setTenant($tenantId)->insert([
                'custom_object_id' => $objectId,
                'field_key'        => $f['field_key'],
                'label'            => $f['label'],
                'type'             => $f['type'] ?? 'text',
                'options'          => isset($f['options']) ? json_encode($f['options']) : null,
                'required'         => ! empty($f['required']) ? 1 : 0,
                'position'         => $i,
            ]);
        }

        return (array) $objectModel->setTenant($tenantId)->find($objectId);
    }
}
