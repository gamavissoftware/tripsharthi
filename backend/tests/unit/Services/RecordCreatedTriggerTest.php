<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Flow\FlowTriggerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * Custom-object `record_created` flow trigger.
 *  - Fires an active flow, optionally scoped to one object via trigger_config.
 *  - config.custom_object_id scoping: 0/absent = any object; N = that object only.
 */
class RecordCreatedTriggerTest extends CIUnitTestCase
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

    private function seedFlow(?array $config): int
    {
        db_connect()->table('flows')->insert([
            'tenant_id'      => 1,
            'name'           => 'On record created',
            'status'         => 'active',
            'trigger_type'   => 'record_created',
            'trigger_config' => $config ? json_encode($config) : null,
            'reentry_policy' => 'always',
            'graph'          => json_encode(['nodes' => [], 'edges' => []]),
            'version'        => 1,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        return (int) db_connect()->insertID();
    }

    public function testFiresForAnyObjectWhenUnscoped(): void
    {
        $contactId = $this->seedContact('+919996000001');
        $this->seedFlow(null); // any object

        $count = FlowTriggerService::fire('record_created', 1, $contactId, ['custom_object_id' => 7]);

        $this->assertSame(1, $count);
        $this->assertSame(1, (int) db_connect()->table('jobs')->where('type', 'flow_start')->countAllResults());
    }

    public function testScopedFlowFiresOnlyForItsObject(): void
    {
        $contactId = $this->seedContact('+919996000002');
        $this->seedFlow(['custom_object_id' => 5]);

        $this->assertSame(1, FlowTriggerService::fire('record_created', 1, $contactId, ['custom_object_id' => 5]), 'matching object fires');
    }

    public function testScopedFlowSkipsOtherObjects(): void
    {
        $contactId = $this->seedContact('+919996000003');
        $this->seedFlow(['custom_object_id' => 5]);

        $this->assertSame(0, FlowTriggerService::fire('record_created', 1, $contactId, ['custom_object_id' => 6]), 'different object does not fire');
    }

    public function testNoContactNoFire(): void
    {
        $this->seedFlow(null);
        // No contact to enrol → fire() short-circuits (contact-centric engine).
        $this->assertSame(0, FlowTriggerService::fire('record_created', 1, 0, ['custom_object_id' => 5]));
    }
}
