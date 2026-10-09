<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\Docs\BusinessProfileService;
use App\Services\Billing\Docs\DocFormat;
use App\Services\Billing\Docs\Gst;
use App\Services\Billing\Docs\PdfRenderer;
use App\Services\Billing\Docs\Qr;
use App\Services\Billing\Docs\SafeImage;
use PHPUnit\Framework\TestCase;

final class BillingDocsPureTest extends TestCase
{
    public function testIndianDigitGroupingAndFormatting(): void
    {
        $this->assertSame('0.00', DocFormat::num(0));
        $this->assertSame('999.99', DocFormat::num(99_999));
        $this->assertSame('1,000.00', DocFormat::num(100_000));
        $this->assertSame('51,729.30', DocFormat::num(5_172_930));
        $this->assertSame('1,23,456.00', DocFormat::num(12_345_600));
        $this->assertSame('12,34,56,789.05', DocFormat::num(1_234_567_890_5));
        $this->assertSame('₹18,105', DocFormat::inr(1_810_525, 0));
        $this->assertSame('-1,500.50', DocFormat::num(-150_050));
    }

    public function testPhoneAndAddressFormatting(): void
    {
        $this->assertSame('+91 98765 43210', DocFormat::phone('+919876543210'));
        $this->assertSame('+91 98765 43210', DocFormat::phone('9876543210'));
        $this->assertSame('+44 20 7946 0958', DocFormat::phone('+44 20 7946 0958'));   // not Indian: untouched
        $this->assertSame(['Shop 12', 'Andheri', 'Mumbai, Maharashtra - 400093'], DocFormat::addressLines(['address_line1' => 'Shop 12', 'address_line2' => 'Andheri', 'city' => 'Mumbai', 'state_code' => '27', 'pincode' => '400093']));
        $this->assertSame('&lt;b&gt;x&lt;/b&gt;', DocFormat::e('<b>x</b>'));
    }

    public function testProfileValidationCatchesComplianceMistakes(): void
    {
        $this->assertSame([], BusinessProfileService::validate(['gstin' => '27ABCDE1234F1Z0', 'state_code' => '27', 'pan' => 'ABCDE1234F', 'bank_ifsc' => 'HDFC0001234', 'upi_id' => 'ab@okhdfcbank', 'pincode' => '400093', 'brand_color' => '#0f766e']));
        $bad = implode(' | ', BusinessProfileService::validate(['gstin' => '27ABCDE1234F1Z1', 'pan' => '12345', 'bank_ifsc' => 'BAD', 'upi_id' => 'nope', 'pincode' => '12', 'brand_color' => 'red', 'invoice_prefix' => 'TOOLONG', 'logo_url' => 'http://insecure/x.png']));
        foreach (['GSTIN is not valid', 'PAN is not valid', 'IFSC', 'UPI', 'PIN code', 'Brand colour', 'prefixes', 'Logo'] as $needle) { $this->assertStringContainsString($needle, $bad); }
        $this->assertStringContainsString('State does not match', implode(' ', BusinessProfileService::validate(['gstin' => '27ABCDE1234F1Z0', 'state_code' => '29'])));
        $this->assertStringContainsString('PAN does not match', implode(' ', BusinessProfileService::validate(['gstin' => '27ABCDE1234F1Z0', 'pan' => 'ZZZZZ9999Z'])));
    }

    public function testMissingFieldsDependOnDocumentType(): void
    {
        $svc = new BusinessProfileService();
        $full = ['legal_name' => 'X', 'address_line1' => 'a', 'city' => 'c', 'state_code' => '27', 'pincode' => '400001'];
        $this->assertSame([], $svc->missingFor($full + ['gstin' => '27ABCDE1234F1Z0'], 'tax_invoice'));
        $this->assertContains('GSTIN (without one, a Bill of Supply is issued instead)', $svc->missingFor($full, 'tax_invoice'));
        $this->assertSame([], $svc->missingFor($full, 'bill_of_supply'));                     // unregistered agencies may still issue a bill of supply
        $this->assertCount(5, $svc->missingFor([], 'bill_of_supply'));
    }

    // ---- image safety ---------------------------------------------------------------------------------------

    public function testOnlyPublicHttpsUrlsAreFetchable(): void
    {
        foreach (['https://cdn.example.com/a.jpg', 'https://8.8.8.8/a.jpg'] as $ok) { $this->assertTrue(SafeImage::isPublicHttpUrl($ok), $ok); }
        foreach (['http://cdn.example.com/a.jpg', 'file:///etc/passwd', 'https://localhost/a.jpg', 'https://127.0.0.1/a.jpg', 'https://10.0.0.5/a.jpg', 'https://192.168.1.1/a.jpg',
                  'https://169.254.169.254/latest/meta-data', 'https://[::1]/a.jpg', 'https://[fe80::1]/a.jpg', 'https://[fd00::1]/a.jpg', 'https://user:pw@cdn.example.com/a.jpg', 'https://db.internal/a.jpg', 'ftp://x/a.jpg', 'javascript:alert(1)', ''] as $bad) {
            $this->assertFalse(SafeImage::isPublicHttpUrl($bad), $bad);
        }
        $this->assertNull(SafeImage::dataUri('https://169.254.169.254/x.jpg'));              // refused before any connection is made
    }

    public function testReencodeAcceptsRealImagesAndFlattensAlpha(): void
    {
        $im = imagecreatetruecolor(40, 20); imagesavealpha($im, true); imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 0, 0, 127));                  // fully transparent
        ob_start(); imagepng($im); $png = (string) ob_get_clean();
        $out = SafeImage::reencode($png, 20);
        $this->assertNotNull($out);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($out)[2]);                  // always JPEG out: metadata/payload stripped
        $this->assertSame(20, getimagesizefromstring($out)[0]);                              // downscaled to max width
        $rgb = imagecolorat(imagecreatefromstring($out), 2, 2);
        $this->assertGreaterThan(0xE0, ($rgb >> 8) & 0xFF);                                  // transparent area became white-ish (green channel high), not black
        $this->assertGreaterThan(0xE0, $rgb & 0xFF);
    }

    public function testReencodeRejectsSvgGarbageAndDecompressionBombs(): void
    {
        $this->assertNull(SafeImage::reencode('<svg xmlns="http://www.w3.org/2000/svg"><image href="file:///etc/passwd"/></svg>'));
        $this->assertNull(SafeImage::reencode('not an image at all'));
        $this->assertNull(SafeImage::reencode("GIF89a<?php echo 'x'; ?>"));
        // A tiny PNG whose header DECLARES 8000x8000 (64M pixels): must be refused without being decoded.
        $ihdr = pack('N', 8000) . pack('N', 8000) . "\x08\x02\x00\x00\x00";
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        $this->assertNull(SafeImage::reencode($png));
    }

    // ---- rendering --------------------------------------------------------------------------------------------

    public function testQrAndRendererProduceRealArtifacts(): void
    {
        $qr = Qr::dataUri('upi://pay?pa=a@b&am=10.00');
        $this->assertStringStartsWith('data:image/png;base64,', (string) $qr);
        $pdf = PdfRenderer::render('<html><body><h1>Hello ₹1,23,456.00</h1><img src="https://evil.example/x.png"><a href="file:///etc/passwd">x</a></body></html>', 'footer', 'T');
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        $this->assertLessThan(60_000, strlen($pdf));                                          // no remote image was fetched/embedded
    }
}
