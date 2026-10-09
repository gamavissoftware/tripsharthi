<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\Docs\Gst;
use App\Services\Einvoice\EinvoiceBuilder;
use App\Services\Einvoice\EinvoiceException;
use App\Services\Einvoice\EinvoiceValidator;
use App\Services\Einvoice\GspIrpClient;
use PHPUnit\Framework\TestCase;

final class EinvoicePureTest extends TestCase
{
    public static function gstin(string $first14): string { return $first14 . Gst::checkChar($first14); }

    private function profile(array $o = []): array
    {
        return array_merge(['gstin' => self::gstin('27ABCDE1234F1Z'), 'legal_name' => 'Demo Travels Private Limited', 'trade_name' => 'Demo Travels', 'address_line1' => 'Shop 12, Andheri', 'address_line2' => null, 'city' => 'Mumbai',
            'pincode' => '400093', 'state_code' => '27', 'phone' => '+919820011111', 'email' => 'hello@demo.in', 'sac_code' => '998554'], $o);
    }

    private function doc(array $o = []): array
    {
        return array_merge(['doc_type' => 'tax_invoice', 'number' => 'INV/26-27/00001', 'issue_date' => '2026-10-08', 'place_of_supply' => '29', 'sac' => '998554', 'gst_rate' => 5, 'taxable_value' => 4_830_000,
            'cgst' => 0, 'sgst' => 0, 'igst' => 241_500, 'tcs' => 101_430, 'total' => 5_172_930, 'lines' => ['items' => [['title' => 'Tour package: Bali honeymoon']]],
            'buyer' => ['name' => 'Acme Holidays LLP', 'gstin' => self::gstin('29AAAPL1234C1Z'), 'state_code' => '29', 'address_line1' => '12 MG Road', 'city' => 'Bengaluru', 'pincode' => '560001', 'phone' => '98450 11122', 'email' => 'a@acme.in']], $o);
    }

    private function payload(array $doc = [], array $profile = [], ?array $orig = null): array { return EinvoiceBuilder::build($this->doc($doc), $this->profile($profile), $orig); }

    public function testAnInterStateInvoiceBuildsAValidInv01PayloadWithTcsInOtherCharges(): void
    {
        $p = $this->payload();
        $this->assertSame('1.1', $p['Version']);
        $this->assertSame(['TaxSch' => 'GST', 'SupTyp' => 'B2B', 'RegRev' => 'N', 'IgstOnIntra' => 'N'], $p['TranDtls']);
        $this->assertSame(['Typ' => 'INV', 'No' => 'INV/26-27/00001', 'Dt' => '08/10/2026'], $p['DocDtls']);
        $this->assertSame(['27', '400093', 'Demo Travels'], [$p['SellerDtls']['Stcd'], (string) $p['SellerDtls']['Pin'], $p['SellerDtls']['TrdNm']]);
        $this->assertSame(['29', '29', 560001, '9845011122'], [$p['BuyerDtls']['Stcd'], $p['BuyerDtls']['Pos'], $p['BuyerDtls']['Pin'], $p['BuyerDtls']['Ph']]);
        $it = $p['ItemList'][0];
        $this->assertSame(['Y', '998554', 48300.0, 2415.0, 0.0, 50715.0], [$it['IsServc'], $it['HsnCd'], $it['AssAmt'], $it['IgstAmt'], $it['CgstAmt'], $it['TotItemVal']]);
        $this->assertSame([1014.3, 51729.3, 48300.0], [$p['ValDtls']['OthChrg'], $p['ValDtls']['TotInvVal'], $p['ValDtls']['AssVal']]);   // TCS is not GST: it rides in OthChrg
        $this->assertSame([], EinvoiceValidator::check($p, '2026-10-08'));
    }

    public function testAnIntraStateInvoiceUsesCgstAndSgst(): void
    {
        $p = $this->payload(['place_of_supply' => '27', 'igst' => 0, 'cgst' => 120_750, 'sgst' => 120_750, 'buyer' => array_merge($this->doc()['buyer'], ['gstin' => self::gstin('27AAAPL1234C1Z'), 'state_code' => '27'])]);
        $this->assertSame([], EinvoiceValidator::check($p, '2026-10-08'));
        $bad = $this->payload(['place_of_supply' => '27', 'buyer' => array_merge($this->doc()['buyer'], ['gstin' => self::gstin('27AAAPL1234C1Z'), 'state_code' => '27'])]);   // IGST on an intra-state supply
        $this->assertStringContainsString('CGST + SGST', implode(' ', EinvoiceValidator::check($bad, '2026-10-08')));
    }

    public function testACreditNoteIsCrnAndReferencesTheOriginalInvoice(): void
    {
        $p = $this->payload(['doc_type' => 'credit_note', 'number' => 'CN/26-27/00001'], [], ['number' => 'INV/26-27/00001', 'issue_date' => '2026-10-01']);
        $this->assertSame('CRN', $p['DocDtls']['Typ']);
        $this->assertSame([['InvNo' => 'INV/26-27/00001', 'InvDt' => '01/10/2026']], $p['RefDtls']['PrecDocDtls']);
        $this->assertSame([], EinvoiceValidator::check($p, '2026-10-08'));
        $noRef = $this->payload(['doc_type' => 'credit_note', 'number' => 'CN/26-27/00001']);
        $this->assertStringContainsString('reference the original', implode(' ', EinvoiceValidator::check($noRef, '2026-10-08')));
    }

    public function testEveryProblemIsReportedAtOnceInPlainWords(): void
    {
        $p = $this->payload(['buyer' => ['name' => '', 'gstin' => 'BADGSTIN', 'state_code' => '', 'address_line1' => '', 'city' => 'XY', 'pincode' => '12']], ['pincode' => '', 'address_line1' => '', 'city' => '']);
        $e = implode(' | ', EinvoiceValidator::check($p, '2026-10-08'));
        foreach (['customer\'s GSTIN', 'customer\'s legal name', 'customer\'s address', 'customer\'s city', 'customer\'s 6-digit PIN', 'customer\'s state', 'Add your address', 'Add your city', 'valid 6-digit PIN code on your Business profile'] as $needle) {
            $this->assertStringContainsString($needle, $e, $needle);
        }
        $this->assertGreaterThanOrEqual(8, count(EinvoiceValidator::check($p, '2026-10-08')));
    }

    public function testDocumentNumberDateAndArithmeticRules(): void
    {
        $this->assertStringContainsString('1-16 letters', implode(' ', EinvoiceValidator::check($this->payload(['number' => 'INV/2026-27/000000001']), '2026-10-08')));   // 21 chars
        $this->assertStringContainsString('1-16 letters', implode(' ', EinvoiceValidator::check($this->payload(['number' => 'INV 26 1']), '2026-10-08')));
        $this->assertStringContainsString('future', implode(' ', EinvoiceValidator::check($this->payload(), '2026-10-01')));
        $p = $this->payload(); $p['ValDtls']['TotInvVal'] = 51729.0;
        $this->assertStringContainsString('invoice total does not equal', implode(' ', EinvoiceValidator::check($p, '2026-10-08')));
        $q = $this->payload(); $q['ItemList'][0]['GstRt'] = 7.0;
        $this->assertStringContainsString('allowed slab', implode(' ', EinvoiceValidator::check($q, '2026-10-08')));
        $h = $this->payload(['sac' => 'ABC']);
        $this->assertStringContainsString('SAC/HSN', implode(' ', EinvoiceValidator::check($h, '2026-10-08')));
        $m = $this->payload(['buyer' => array_merge($this->doc()['buyer'], ['state_code' => '27'])]);            // GSTIN says Karnataka (29), state says Maharashtra
        $this->assertStringContainsString('does not match their GSTIN', implode(' ', EinvoiceValidator::check($m, '2026-10-08')));
    }

    public function testPhoneAndB2bHelpers(): void
    {
        $this->assertSame('9876543210', EinvoiceBuilder::phone('+91 98765-43210'));
        $this->assertSame('9876543210', EinvoiceBuilder::phone('919876543210'));
        $this->assertNull(EinvoiceBuilder::phone('123'));
        $this->assertTrue(EinvoiceBuilder::isB2b(['gstin' => self::gstin('29AAAPL1234C1Z')]));
        $this->assertFalse(EinvoiceBuilder::isB2b(['gstin' => null]));
        $this->assertFalse(EinvoiceBuilder::isB2b(['gstin' => '29AAAPL1234C1ZX']));       // checksum fails
        $p = $this->payload(['buyer' => array_merge($this->doc()['buyer'], ['email' => 'not-an-email', 'phone' => '12'])]);
        $this->assertArrayNotHasKey('Em', $p['BuyerDtls']);
        $this->assertArrayNotHasKey('Ph', $p['BuyerDtls']);                                // optional fields that are malformed are omitted, not sent
    }

    // ---- GSP client -------------------------------------------------------------------------------------------------------------

    private function gsp(callable $http, array $cfg = []): GspIrpClient
    {
        return new GspIrpClient($cfg + ['base_url' => 'https://gsp.example.com/api', 'auth' => ['type' => 'headers', 'headers' => ['X-Api-Key' => 'k', 'X-Gstin' => '27ABCDE1234F1Z0']]], $http);
    }

    public function testSuccessIsReadFromTheTopLevelOrADataWrapperAndHeadersAreSent(): void
    {
        $seen = [];
        $c = $this->gsp(function ($m, $u, $o) use (&$seen) { $seen = [$m, $u, $o]; return ['status' => 200, 'body' => json_encode(['status' => 1, 'data' => ['Irn' => str_repeat('a', 64), 'AckNo' => 112010000001, 'AckDt' => '2026-10-08 11:30:00', 'SignedQRCode' => 'eyJ.signed.qr']])]; });
        $r = $c->generate($this->payload());
        $this->assertSame([str_repeat('a', 64), '112010000001', '2026-10-08 11:30:00', 'eyJ.signed.qr'], [$r['irn'], $r['ack_no'], $r['ack_dt'], $r['signed_qr']]);
        $this->assertSame('https://gsp.example.com/api/einvoice/generate', $seen[1]);
        $this->assertSame('k', $seen[2]['headers']['X-Api-Key']);
        $this->assertSame('INV', $seen[2]['json']['DocDtls']['Typ']);
        $top = $this->gsp(fn () => ['status' => 200, 'body' => json_encode(['Irn' => str_repeat('b', 64), 'AckNo' => '5', 'AckDt' => '2026-10-08 12:00:00', 'SignedQRCode' => 'x.y.z'])]);
        $this->assertSame(str_repeat('b', 64), $top->generate($this->payload())['irn']);
    }

    public function testFailuresAreClassifiedAndNeverLookLikeSuccess(): void
    {
        $cases = [
            [fn () => ['status' => 500, 'body' => ''], EinvoiceException::TRANSIENT],
            [fn () => ['status' => 429, 'body' => ''], EinvoiceException::TRANSIENT],
            [fn () => ['status' => 401, 'body' => '{}'], EinvoiceException::AUTH],
            [fn () => ['status' => 200, 'body' => json_encode(['ErrorCode' => '2172', 'ErrorMessage' => 'Invalid pincode'])], EinvoiceException::REJECTED],
            [fn () => ['status' => 400, 'body' => json_encode(['error' => ['code' => 'X', 'message' => 'bad request']])], EinvoiceException::REJECTED],
            [fn () => ['status' => 200, 'body' => json_encode(['status' => 1, 'data' => ['Irn' => str_repeat('c', 64)]])], EinvoiceException::REJECTED],        // an IRN with no signed QR is not a usable answer
            [fn () => ['status' => 200, 'body' => 'not json'], EinvoiceException::REJECTED],
        ];
        foreach ($cases as $i => [$http, $kind]) {
            try { $this->gsp($http)->generate($this->payload()); $this->fail("case $i accepted"); } catch (EinvoiceException $e) { $this->assertSame($kind, $e->kind, "case $i: " . $e->getMessage()); }
        }
        try { $this->gsp(function () { throw new \RuntimeException('timeout'); })->generate($this->payload()); $this->fail(); } catch (EinvoiceException $e) { $this->assertSame(EinvoiceException::TRANSIENT, $e->kind); }
        $this->expectException(EinvoiceException::class);
        (new GspIrpClient(['base_url' => 'http://insecure.example.com'], fn () => ['status' => 200, 'body' => '{}']))->generate($this->payload());      // https only
    }

    public function testADuplicateCarriesTheExistingIrnSoTheCallerCanAdoptIt(): void
    {
        $existing = ['Irn' => str_repeat('d', 64), 'AckNo' => '77', 'AckDt' => '2026-10-08 09:00:00', 'SignedQRCode' => 'old.signed.qr'];
        $c = $this->gsp(fn () => ['status' => 200, 'body' => json_encode(['ErrorCode' => '2150', 'ErrorMessage' => 'Duplicate IRN', 'InfoDtls' => [$existing]])]);
        try { $c->generate($this->payload()); $this->fail('duplicate not signalled'); }
        catch (EinvoiceException $e) { $this->assertSame([EinvoiceException::DUPLICATE, str_repeat('d', 64)], [$e->kind, $e->extra['existing']['irn']]); }
    }
}
