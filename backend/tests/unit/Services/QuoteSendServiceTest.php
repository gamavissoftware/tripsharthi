<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\QuoteModel;
use App\Services\Crm\QuoteSendService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase J1: windowed quote send reuses the media pipeline — sends inside the
 * 24h window, blocks + logs outside it (never calls the provider).
 */
class QuoteSendServiceTest extends CIUnitTestCase
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

    private function fakeClient(): object
    {
        return new class {
            public array $calls = [];
            public function uploadMedia(string $path, string $mime): array
            {
                $this->calls[] = 'upload';
                return ['id' => 'MEDIA_1'];
            }
            public function sendMedia(string $to, string $type, string $mediaId, string $caption = '', string $filename = ''): array
            {
                $this->calls[] = 'send:' . $type . ':' . $mediaId;
                return ['success' => true, 'message_id' => 'WAMID_1'];
            }
        };
    }

    private function quoteRow(): array
    {
        $id = (int) (new QuoteModel())->setTenant(1)->insert([
            'deal_id' => 1, 'number' => 'Q-1', 'status' => 'draft', 'currency' => 'INR',
            'subtotal' => 100000, 'total' => 100000, 'items' => json_encode([['name' => 'X', 'quantity' => 1, 'unit_price' => 100000, 'total' => 100000]]),
        ], true);
        $q = (new QuoteModel())->setTenant(1)->find($id);
        $q['items'] = json_decode($q['items'], true);
        return $q;
    }

    public function testSendsInsideWindow(): void
    {
        $client = $this->fakeClient();
        $quote  = $this->quoteRow();
        $conv   = ['id' => 1, 'window_expires_at' => date('Y-m-d H:i:s', time() + 3600)]; // open

        $res = (new QuoteSendService())->deliver(1, $quote, ['id' => 1, 'title' => 'D'], ['id' => 9, 'wa_number' => '+910000'], $conv, $client);

        $this->assertTrue($res['sent']);
        $this->assertFalse($res['blocked']);
        $this->assertSame(['upload', 'send:document:MEDIA_1'], $client->calls, 'reused uploadMedia + sendMedia');
        $this->assertSame('sent', (new QuoteModel())->setTenant(1)->find($quote['id'])['status']);
        $this->assertSame(1, db_connect()->table('messages')->where('type', 'document')->countAllResults());
    }

    public function testBlockedOutsideWindow(): void
    {
        $client = $this->fakeClient();
        $quote  = $this->quoteRow();
        $conv   = ['id' => 1, 'window_expires_at' => date('Y-m-d H:i:s', time() - 3600)]; // closed

        $res = (new QuoteSendService())->deliver(1, $quote, ['id' => 1, 'title' => 'D'], ['id' => 9, 'wa_number' => '+910000'], $conv, $client);

        $this->assertFalse($res['sent']);
        $this->assertTrue($res['blocked']);
        $this->assertSame([], $client->calls, 'provider never called outside the window');
        $this->assertSame('draft', (new QuoteModel())->setTenant(1)->find($quote['id'])['status'], 'status unchanged');
        // The blocked attempt is logged (auditable).
        $this->assertSame(1, db_connect()->table('messages')->where('status', 'failed')->countAllResults());
    }
}
