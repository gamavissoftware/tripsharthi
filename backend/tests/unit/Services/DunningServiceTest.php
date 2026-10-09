<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\BookingPaymentModel;
use App\Services\Travel\BookingService;
use App\Services\Travel\DunningService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration test for WhatsApp payment dunning against a REAL MySQL schema (all migrations).
 * Excluded from the default run; execute with:  composer test:mysql   (see composer.json)
 */
#[Group('mysql')]
final class DunningServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    // Fixed "now": 2026-10-08 06:00 UTC = 11:30 IST (inside 9–20 sending hours)
    private const NOW = 1_791_439_200;

    /** @var object Fake WhatsApp client recording calls. */
    private object $wa;

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['payment_reminders', 'dunning_settings', 'messages', 'conversations', 'tasks', 'activities', 'payment_links', 'templates', 'booking_services', 'booking_payments', 'bookings', 'contacts', 'tenants'] as $t) {
            $db->table($t)->truncate();
        }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $this->wa = new class {
            public array $texts = [];
            public array $templates = [];
            public function sendText(string $to, string $body): array { $this->texts[] = [$to, $body]; return ['success' => true, 'message_id' => 'wamid.T' . count($this->texts), 'error' => null]; }
            public function sendTemplate(string $to, string $name, string $lang, array $components = []): array { $this->templates[] = [$to, $name, $components]; return ['success' => true, 'message_id' => 'wamid.P' . count($this->templates), 'error' => null]; }
        };
    }

    private function svc(?int $now = null): DunningService
    {
        return new DunningService(fn () => $this->wa, $now ?? self::NOW);
    }

    /** Seed tenant 1 + a contact + a booking with ONE pending instalment due on $due. */
    private function seed(string $due, array $contact = [], bool $approvedTemplate = true, bool $enabled = true): int
    {
        $db = db_connect();
        $db->table('tenants')->insert(['id' => 1, 'name' => 'T', 'slug' => 't', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('contacts')->insert($contact + ['id' => 1, 'tenant_id' => 1, 'name' => 'Rohit Sharma', 'wa_number' => '+919876543210', 'status' => 'new', 'source' => 'manual', 'opt_in' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $db->table('bookings')->insert(['id' => 1, 'tenant_id' => 1, 'booking_ref' => 'TP-2026-0001', 'contact_id' => 1, 'title' => 'Bali', 'status' => 'confirmed', 'total_amount' => 5_000_000, 'created_at' => '2026-01-01 00:00:00']);
        $db->table('booking_payments')->insert(['id' => 1, 'tenant_id' => 1, 'booking_id' => 1, 'label' => 'Balance', 'due_date' => $due, 'amount' => 1_810_526, 'status' => 'pending', 'created_at' => '2026-09-01 00:00:00']);
        $tplId = 0;
        if ($approvedTemplate) {
            $db->table('templates')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'tp_payment_due', 'language' => 'en', 'category' => 'utility', 'header_type' => 'none',
                'body' => 'Hi {{1}}, {{3}} for {{2}} due {{4}}. {{5}}', 'variables' => json_encode(['a', 'b', 'c', 'd', 'e']), 'meta_status' => 'approved', 'created_at' => '2026-01-01 00:00:00']);
            $tplId = 1;
        }
        $steps = [['key' => 'due_today', 'offset_days' => 0, 'kind' => 'message', 'template_id' => $tplId ?: null], ['key' => 'escalate_7d', 'offset_days' => 7, 'kind' => 'task', 'template_id' => null]];
        $this->svc()->saveSettings(1, ['enabled' => $enabled, 'steps' => $steps]);
        return 1;
    }

    private function rows(string $table, array $where = []): int
    {
        return (int) db_connect()->table($table)->where($where)->countAllResults();
    }

    public function testDueTodaySendsUtilityTemplateOnceAndIsIdempotent(): void
    {
        $this->seed('2026-10-08');
        $r = $this->svc()->runTenant(1);
        $this->assertSame(1, $r['sent']);
        $this->assertCount(1, $this->wa->templates);
        $this->assertSame('tp_payment_due', $this->wa->templates[0][1]);
        $params = $this->wa->templates[0][2][0]['parameters'];
        $this->assertCount(5, $params);
        $this->assertSame('Rohit', $params[0]['text']);
        $this->assertSame('TP-2026-0001', $params[1]['text']);
        $this->assertSame('₹18,105', $params[2]['text']);
        $msg = db_connect()->table('messages')->get()->getRowArray();
        $this->assertSame('utility', $msg['category']);
        $this->assertSame('1', (string) $msg['billable']);          // window closed: utility is paid

        // Second run (and an overlapping cron) must not message the customer again.
        $this->svc()->runTenant(1);
        $this->assertCount(1, $this->wa->templates);
        $this->assertSame(1, $this->rows('payment_reminders', ['step' => 'due_today', 'status' => 'sent']));
        $this->assertSame(1, (int) (new BookingPaymentModel())->setTenant(1)->find(1)['reminder_count']);
    }

    public function testOpenWindowUsesFreeFormTextNotTemplate(): void
    {
        $this->seed('2026-10-08');
        db_connect()->table('conversations')->insert(['tenant_id' => 1, 'contact_id' => 1, 'wa_number' => '+919876543210', 'status' => 'open',
            'window_expires_at' => date('Y-m-d H:i:s', self::NOW + 3600), 'created_at' => '2026-10-08 00:00:00']);
        $this->svc()->runTenant(1);
        $this->assertCount(0, $this->wa->templates);
        $this->assertCount(1, $this->wa->texts);
        $this->assertStringContainsString('TP-2026-0001', $this->wa->texts[0][1]);
        $this->assertSame('free_form', db_connect()->table('messages')->get()->getRowArray()['category']);
    }

    public function testClosedWindowWithoutApprovedTemplateNeverSendsFreeFormAndRaisesTask(): void
    {
        $this->seed('2026-10-08', [], false);
        $r = $this->svc()->runTenant(1);
        $this->assertSame(0, $r['sent']);
        $this->assertCount(0, $this->wa->texts);          // spec §1: no free-form outside the window
        $this->assertCount(0, $this->wa->templates);
        $this->assertSame(1, $this->rows('tasks'));
    }

    public function testOptedOutCustomerIsNeverMessaged(): void
    {
        $this->seed('2026-10-08', ['opt_in' => 0]);
        $this->svc()->runTenant(1);
        $this->assertCount(0, $this->wa->templates);
        $this->assertCount(0, $this->wa->texts);
        $this->assertSame(1, $this->rows('tasks'));
    }

    public function testNothingSentOutsideSendingHours(): void
    {
        $this->seed('2026-10-08');
        // 2026-10-08 21:30 UTC = 03:00 IST
        $this->svc(1_791_495_000)->runTenant(1);
        $this->assertCount(0, $this->wa->templates);
        $this->assertSame(0, $this->rows('payment_reminders'));
    }

    public function testDisabledTenantIsLeftAlone(): void
    {
        $this->seed('2026-10-08', [], true, false);
        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0, 'tasks' => 0], $this->svc()->runTenant(1));
    }

    public function testEscalationCreatesExactlyOneTask(): void
    {
        $this->seed('2026-10-01');
        $this->svc()->runTenant(1);
        $this->svc()->runTenant(1);
        $this->assertSame(1, $this->rows('tasks'));
    }

    public function testPaidLinkSettlesInstalmentOnceAndSendsReceipt(): void
    {
        $this->seed('2026-10-08');
        db_connect()->table('payment_links')->insert(['id' => 5, 'tenant_id' => 1, 'contact_id' => 1, 'amount_paise' => 1_810_526, 'currency' => 'INR', 'description' => 'x', 'reference_id' => 'tp_pl_1_a', 'razorpay_link_id' => 'plink_1', 'short_url' => 'https://rzp.io/i/a', 'status' => 'created', 'created_at' => '2026-10-08 00:00:00']);
        db_connect()->table('booking_payments')->where('id', 1)->update(['payment_link_id' => 5]);

        $svc = new BookingService();
        $out = $svc->onPaymentLinkPaid(1, 5, 'pay_ABC');
        $this->assertSame('1810526', (string) $out['paid_amount']);
        $this->assertSame('paid', (new BookingPaymentModel())->setTenant(1)->find(1)['status']);

        $again = $svc->onPaymentLinkPaid(1, 5, 'pay_ABC');                 // duplicate webhook
        $this->assertSame('1810526', (string) $again['paid_amount']);      // not double counted
        $this->assertNull($svc->onPaymentLinkPaid(1, 999, 'pay_X'));       // unknown link ignored
    }
}
