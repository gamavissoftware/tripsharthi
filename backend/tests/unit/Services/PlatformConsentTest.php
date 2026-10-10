<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Marketing\PlatformConsent;
use CodeIgniter\Test\CIUnitTestCase;

/** The rule "an opt-in needs a usable WhatsApp number and is never assumed" (no database needed). */
final class PlatformConsentTest extends CIUnitTestCase
{
    public function testNumbersAreNormalisedToInternationalDigitsAssumingIndia(): void
    {
        $this->assertSame('919876543210', PlatformConsent::normalizePhone('98765 43210'));
        $this->assertSame('919876543210', PlatformConsent::normalizePhone('+91 98765-43210'));
        $this->assertSame('919876543210', PlatformConsent::normalizePhone('09876543210'), 'a leading 0 is a trunk prefix');
        $this->assertSame('971501234567', PlatformConsent::normalizePhone('+971 50 123 4567'), 'other countries keep their own code');
        foreach ([null, '', '   ', 'abc', '12', '+1', str_repeat('9', 20)] as $bad) { $this->assertNull(PlatformConsent::normalizePhone($bad), 'rejects ' . var_export($bad, true)); }
    }

    public function testNormalisingAStoredNumberAgainChangesNothing(): void
    {
        foreach (['98765 43210', '+91 98765 43210', '09876543210', '0091 98765 43210', '+971 50 123 4567', '+44 7911 123456'] as $raw) {
            $once = PlatformConsent::normalizePhone($raw);
            $this->assertNotNull($once, $raw);
            $this->assertSame($once, PlatformConsent::normalizePhone($once), "normalising {$once} a second time must not change it (e.g. no second +91)");
            $this->assertSame($once, PlatformConsent::fields($once, true)['phone']);
        }
        $this->assertSame('919876543210', PlatformConsent::normalizePhone('919876543210'), 'a stored Indian number is already international');
    }

    public function testAnOptInIsOnlyValidTogetherWithAUsableNumber(): void
    {
        $f = PlatformConsent::fields('+91 98765 43210', true, 1_800_000_000);
        $this->assertSame(['919876543210', 1, '2027-01-15 08:00:00'], [$f['phone'], $f['wa_marketing_opt_in'], $f['wa_opt_in_at']]);

        $no = PlatformConsent::fields('9876543210', false);
        $this->assertSame([1, 0, null], [(int) ($no['phone'] !== null), $no['wa_marketing_opt_in'], $no['wa_opt_in_at']], 'a number without a yes stores no consent and no timestamp');

        $none = PlatformConsent::fields('', false);
        $this->assertSame([null, 0], [$none['phone'], $none['wa_marketing_opt_in']]);

        $this->expectException(\InvalidArgumentException::class);
        PlatformConsent::fields('', true);                      // "yes" with no number is not a consent we can act on
    }

    public function testAGarbageNumberIsRefusedEvenWithoutOptIn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not look right');
        PlatformConsent::fields('not a phone', false);
    }
}
