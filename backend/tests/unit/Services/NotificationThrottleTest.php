<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Notifications\NotificationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Reply alerts must fire only on the FIRST unread message of a conversation
 * (so a customer sending five lines = one alert, not five). When the previous
 * unread count is > 0 the service returns immediately — no settings lookup,
 * no channel sends.
 */
class NotificationThrottleTest extends CIUnitTestCase
{
    public function testSkipsWhenNotFirstUnread(): void
    {
        // previousUnread = 3 → must return without touching anything (no exception,
        // no DB/network even with garbage conversation data).
        (new NotificationService())->notifyInbound(1, ['id' => 1], ['body' => 'hi'], 3);
        $this->assertTrue(true);
    }
}
