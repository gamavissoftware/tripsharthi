<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ContactModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * Lead qualification fields (CRM richness phase 1) — the business profile a lead
 * carries across industries persists and is validated.
 */
class ContactQualificationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CrmTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCrmSchema();
    }

    public function testQualificationFieldsPersist(): void
    {
        $model = (new ContactModel())->setTenant(1);
        $id = (int) $model->insert([
            'wa_number' => '+919990001111', 'name' => 'Lead', 'status' => 'new', 'source' => 'manual',
            'city' => 'Ahmedabad', 'state' => 'Gujarat', 'country' => 'India',
            'business_type' => 'Manufacturing', 'requirement_type' => 'CRM migration',
            'current_process' => 'Excel + Tally', 'budget_amount' => 250000,
            'timeline' => '3 months', 'qualification_status' => 'qualified',
            'ai_call_status' => 'completed', 'priority' => 'high',
            'remarks' => 'Looking for CRM and Project Management tool',
        ], true);

        $c = (new ContactModel())->setTenant(1)->find($id);
        $this->assertSame('Ahmedabad', $c['city']);
        $this->assertSame('Manufacturing', $c['business_type']);
        $this->assertSame('CRM migration', $c['requirement_type']);
        $this->assertSame(250000, (int) $c['budget_amount']);
        $this->assertSame('3 months', $c['timeline']);
        $this->assertSame('qualified', $c['qualification_status']);
        $this->assertSame('high', $c['priority']);
        $this->assertStringContainsString('Project Management', $c['remarks']);
    }

    public function testPriorityIsValidated(): void
    {
        $model = (new ContactModel())->setTenant(1);
        $ok = $model->insert([
            'wa_number' => '+919990002222', 'name' => 'Bad', 'status' => 'new', 'source' => 'manual',
            'priority' => 'urgent',
        ]);
        $this->assertFalse($ok, 'an out-of-list priority is rejected');
        $this->assertArrayHasKey('priority', $model->errors());
    }

    public function testPriorityDefaultsToMedium(): void
    {
        $model = (new ContactModel())->setTenant(1);
        $id = (int) $model->insert(['wa_number' => '+919990003333', 'name' => 'Def', 'status' => 'new', 'source' => 'manual'], true);
        $this->assertSame('medium', (new ContactModel())->setTenant(1)->find($id)['priority']);
    }
}
