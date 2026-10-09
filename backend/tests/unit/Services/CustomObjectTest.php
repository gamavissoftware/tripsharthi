<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AssociationModel;
use App\Models\CustomObjectFieldModel;
use App\Models\CustomObjectModel;
use App\Models\CustomObjectRecordModel;
use App\Services\Tenancy\IndustryTemplateService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase E: industry templates, custom objects/records, and the polymorphic
 * association model (with tenant isolation).
 */
class CustomObjectTest extends CIUnitTestCase
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

    // ── Industry templates ─────────────────────────────────────────────

    public function testListReturnsTemplates(): void
    {
        $keys = array_column((new IndustryTemplateService())->list(), 'key');
        $this->assertContains('real_estate', $keys);
        $this->assertContains('healthcare', $keys);
        $this->assertContains('insurance', $keys);
    }

    public function testApplyProvisionsObjectAndFields(): void
    {
        $object = (new IndustryTemplateService())->apply(1, 'real_estate');

        $this->assertSame('property', $object['api_name']);
        $this->assertSame('Properties', $object['label_plural']);

        $fields = (new CustomObjectFieldModel())->forObject(1, (int) $object['id']);
        $this->assertSame(6, count($fields));
        $this->assertSame('address', $fields[0]['field_key']);
        $this->assertSame(1, (int) $fields[0]['required'], 'Address is required');
        // select field options are stored as JSON.
        $type = array_values(array_filter($fields, static fn ($f) => $f['field_key'] === 'type'))[0];
        $this->assertSame(['Apartment', 'Villa', 'Plot', 'Commercial'], json_decode($type['options'], true));
    }

    public function testApplyIsIdempotent(): void
    {
        $svc = new IndustryTemplateService();
        $a   = $svc->apply(1, 'real_estate');
        $b   = $svc->apply(1, 'real_estate');

        $this->assertSame((int) $a['id'], (int) $b['id'], 'Re-apply returns the same object');
        $this->assertSame(1, (new CustomObjectModel())->setTenant(1)->where('api_name', 'property')->countAllResults());
        $this->assertSame(6, (new CustomObjectFieldModel())->setTenant(1)->countAllResults(), 'No duplicate fields');
    }

    public function testApplyUnknownTemplateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new IndustryTemplateService())->apply(1, 'no_such_vertical');
    }

    public function testApplyIsTenantScoped(): void
    {
        (new IndustryTemplateService())->apply(1, 'healthcare');
        (new IndustryTemplateService())->apply(2, 'healthcare');

        $this->assertCount(1, (new CustomObjectModel())->setTenant(1)->findAll());
        $this->assertCount(1, (new CustomObjectModel())->setTenant(2)->findAll());
    }

    // ── Records with JSON data ─────────────────────────────────────────

    public function testRecordStoresAndReadsJsonData(): void
    {
        $object = (new IndustryTemplateService())->apply(1, 'real_estate');
        $id = (int) (new CustomObjectRecordModel())->setTenant(1)->insert([
            'custom_object_id' => (int) $object['id'],
            'name'             => 'Sea View',
            'data'             => json_encode(['address' => '12 Marine Drive', 'bedrooms' => '3']),
        ], true);

        $rec  = (new CustomObjectRecordModel())->setTenant(1)->find($id);
        $data = json_decode($rec['data'], true);
        $this->assertSame('12 Marine Drive', $data['address']);
        $this->assertSame('3', $data['bedrooms']);
    }

    // ── Associations ───────────────────────────────────────────────────

    public function testAssociationLinkIsIdempotent(): void
    {
        $am = new AssociationModel();
        $am->link(1, 'contact', 5, 'custom_object_record', 9);
        $am->link(1, 'contact', 5, 'custom_object_record', 9); // duplicate

        $this->assertSame(1, (new AssociationModel())->setTenant(1)->countAllResults(), 'Duplicate link is a no-op');
    }

    public function testAssociationForRecordReturnsBothDirections(): void
    {
        (new AssociationModel())->link(1, 'contact', 5, 'custom_object_record', 9, 'interested in');

        // Queried from the contact side (it is the "from").
        $fromContact = (new AssociationModel())->forRecord(1, 'contact', 5);
        $this->assertCount(1, $fromContact);

        // Queried from the record side (it is the "to") — same association.
        $fromRecord = (new AssociationModel())->forRecord(1, 'custom_object_record', 9);
        $this->assertCount(1, $fromRecord);
    }

    public function testAssociationsAreTenantScoped(): void
    {
        (new AssociationModel())->link(1, 'contact', 5, 'deal', 7);
        (new AssociationModel())->link(2, 'contact', 5, 'deal', 7);

        // Tenant 1 must not see tenant 2's link even with the same record ids.
        $this->assertCount(1, (new AssociationModel())->forRecord(1, 'contact', 5));
        $this->assertCount(1, (new AssociationModel())->forRecord(2, 'contact', 5));
    }
}
