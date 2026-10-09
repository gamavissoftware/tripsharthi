<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Crm\MentionService;
use App\Services\Crm\NotificationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * Phase L: @mention detection (teammates only, not the author, boundary-aware)
 * and in-app notification creation.
 */
class MentionServiceTest extends CIUnitTestCase
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

    private function user(int $tenant, int $id, string $name): void
    {
        db_connect()->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'name' => $name, 'role' => 'agent']);
    }

    public function testDetectsTeammatesExcludingAuthor(): void
    {
        $this->user(1, 1, 'Ravi Kumar');
        $this->user(1, 2, 'Asha Singh');
        $this->user(1, 3, 'Author Person');

        $ids = (new MentionService())->mentionedUserIds(1, 'Hey @Ravi Kumar and @Asha Singh please review', 3);
        sort($ids);
        $this->assertSame([1, 2], $ids);
    }

    public function testExcludesTheAuthorAndUnknownNames(): void
    {
        $this->user(1, 1, 'Ravi Kumar');
        $ids = (new MentionService())->mentionedUserIds(1, '@Ravi Kumar self-note, cc @Nobody Here', 1);
        $this->assertSame([], $ids, 'author excluded; unknown name ignored');
    }

    public function testBoundaryAwareNoPartialMatch(): void
    {
        $this->user(1, 1, 'Ann');
        $this->user(1, 2, 'Annabel');
        // "@Annabel" must match Annabel (id 2), NOT Ann (id 1).
        $ids = (new MentionService())->mentionedUserIds(1, 'ping @Annabel', 0);
        $this->assertSame([2], $ids);
    }

    public function testTenantScoped(): void
    {
        $this->user(2, 5, 'Ravi Kumar'); // different tenant
        $this->assertSame([], (new MentionService())->mentionedUserIds(1, '@Ravi Kumar', 0));
    }

    public function testNotificationServiceCreatesRow(): void
    {
        NotificationService::notify(1, 7, 'mention', 'Asha mentioned you', '/contacts/9');
        $row = db_connect()->table('notifications')->where('user_id', 7)->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('/contacts/9', $row['link']);
        $this->assertNull($row['read_at']);
        // user 0 is a no-op.
        NotificationService::notify(1, 0, 'mention', 'x', null);
        $this->assertSame(1, db_connect()->table('notifications')->countAllResults());
    }
}
