<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Leads\LeadFieldExtractor as X;
use CodeIgniter\Test\CIUnitTestCase;

final class LeadFieldExtractorTest extends CIUnitTestCase
{
    private const TODAY = '2026-10-08';

    public function testPhoneNormalisationIsIndiaFirstAndRejectsJunk(): void
    {
        foreach (['98765 43210', '+91-9876543210', '09876543210', '919876543210', '(+91) 98765-43210'] as $raw) { $this->assertSame('+919876543210', X::phone($raw), $raw); }
        $this->assertSame('+442071234567', X::phone('+44 20 7123 4567'));
        foreach (['', 'abc', '12345', '5876543210', '98765432101234567890'] as $bad) { $this->assertSame('', X::phone($bad), "'$bad'"); }
    }

    public function testDatesAreDayFirstAndPastDatesRejected(): void
    {
        $this->assertSame('2027-01-12', X::date('12/01/2027', self::TODAY));
        $this->assertSame('2027-01-12', X::date('12 Jan 2027', self::TODAY));
        $this->assertSame('2027-01-12', X::date('2027-01-12', self::TODAY));
        $this->assertSame('2027-01-12', X::date('12-1-27', self::TODAY));
        $this->assertNull(X::date('31/02/2027', self::TODAY));      // not a real date
        $this->assertNull(X::date('01/01/2020', self::TODAY));      // past
        $this->assertNull(X::date('sometime soon', self::TODAY));
    }

    public function testBudgetUnderstandsIndianNotation(): void
    {
        $this->assertSame(['paise' => 15_000_000, 'basis' => 'total'], X::budget('₹1,50,000'));
        $this->assertSame(['paise' => 15_000_000, 'basis' => 'total'], X::budget('1.5 lakh'));
        $this->assertSame(['paise' => 8_000_000, 'basis' => 'total'], X::budget('80k'));
        $this->assertSame(['paise' => 20_000_000, 'basis' => 'per_person'], X::budget('2 lac per person'));
        $this->assertNull(X::budget('flexible'));
        $this->assertNull(X::budget('50'));                          // below ₹1,000 is noise
    }

    public function testWebhookPayloadsWithDifferentFieldNamesMapToTheSameLead(): void
    {
        $a = X::normalise(X::fromPayload(['Customer Name' => 'Asha Rao', 'Mobile No' => '98765 43210', 'Email Id' => 'Asha@Example.com', 'Destination' => 'Bali', 'Travel Date' => '15/01/2027', 'Adults' => '2', 'Kids' => '1', 'Budget' => '2 lakh']), self::TODAY);
        $b = X::normalise(X::fromPayload(['lead' => ['full_name' => 'Asha Rao', 'contact_number' => '+919876543210', 'email' => 'asha@example.com'], 'enquiry_for' => 'Bali', 'departure_date' => '2027-01-15', 'pax' => 2, 'children' => 1, 'budget' => '200000']), self::TODAY);
        foreach ([$a, $b] as $l) {
            $this->assertSame(['Asha Rao', '+919876543210', 'asha@example.com'], [$l['name'], $l['phone'], $l['email']]);
            $this->assertSame(['Bali', '2027-01-15', 2, 1, 20_000_000], [$l['trip']['destination_text'], $l['trip']['start_date'], $l['trip']['adults'], $l['trip']['children'], $l['trip']['budget_max']]);
            $this->assertSame(1, $l['trip']['is_international']);    // inferred from the destination
        }
    }

    public function testCustomAliasesOverrideAndOnlyKnownFieldsAreAccepted(): void
    {
        $r = X::fromPayload(['Guest Handle' => 'Meera', 'ph' => '9876543210'], ['guest handle' => 'name', 'ph' => 'phone', 'x' => 'not_a_field']);
        $this->assertSame(['Meera', '9876543210'], [$r['fields']['name'], $r['fields']['phone']]);
        $r2 = X::fromPayload(['x' => 'val'], ['x' => 'not_a_field']);
        $this->assertSame([], $r2['fields']);
        $this->assertSame(['x' => 'val'], $r2['extras']);            // unknown data is kept, not silently dropped
    }

    public function testEmailBodiesPlainTextAndHtmlTables(): void
    {
        $plain = "New enquiry received\nName: Rohit Verma\nMobile: 9811122233\nEmail: rohit@x.in\nDestination: Kashmir\nNo of Adults: 4\nDuration: 6 days\nMessage: Need a family package in December";
        $l = X::normalise(X::fromEmail('New lead', $plain), self::TODAY);
        $this->assertSame(['Rohit Verma', '+919811122233', 'rohit@x.in', 'Kashmir', 4, 5], [$l['name'], $l['phone'], $l['email'], $l['trip']['destination_text'], $l['trip']['adults'], $l['trip']['nights']]);
        $this->assertSame('Need a family package in December', $l['message']);
        $this->assertSame('Dec', $l['trip']['travel_month']);

        $html = '<table><tr><td>Customer Name</td><td>Priya&nbsp;Nair</td></tr><tr><td>Phone</td><td>+91 99887 76655</td></tr><tr><td>Package</td><td>Goa 3N/4D</td></tr></table>';
        $h = X::normalise(X::fromEmail('Lead from portal', $html), self::TODAY);
        $this->assertSame(['Priya Nair', '+919988776655', 'Goa 3N/4D'], [$h['name'], $h['phone'], $h['trip']['destination_text']]);
    }

    public function testEmailFallbacksFindAPhoneInProseAndIgnoreNoReplyAddresses(): void
    {
        $body = "Hi team, a customer called Sunil wants Kerala backwaters. Call him on 98450 12345. Sent via noreply@portal.com, reply to sunil.k@gmail.com";
        $l = X::normalise(X::fromEmail('Enquiry for Kerala backwaters - Sunil', $body), self::TODAY);
        $this->assertSame(['+919845012345', 'sunil.k@gmail.com'], [$l['phone'], $l['email']]);
        $this->assertStringContainsString('Kerala backwaters', $l['trip']['destination_text']);
    }

    public function testBadValuesAreReportedNotGuessed(): void
    {
        $l = X::normalise(X::fromPayload(['name' => 'A', 'phone' => '12', 'email' => 'nope', 'travel date' => '01/01/2020']), self::TODAY);
        $this->assertSame('', $l['phone']);
        $this->assertNull($l['email']);
        $this->assertArrayNotHasKey('start_date', $l['trip']);
        $this->assertCount(3, $l['issues']);
        $n = X::normalise(X::fromPayload(['name' => '<script>alert(1)</script>Evil']), self::TODAY);
        $this->assertStringNotContainsString('<', (string) $n['name']);
    }
}
