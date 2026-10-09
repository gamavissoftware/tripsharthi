<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** QR codes as PNG data URIs (GD) — embedded in PDFs for "accept online" and UPI pay-by-scan. */
final class Qr
{
    public static function dataUri(string $text, int $scale = 5): ?string
    {
        try {
            return (new QRCode(new QROptions(['outputType' => 'png', 'outputBase64' => true, 'scale' => $scale, 'quietzoneSize' => 1, 'eccLevel' => 0b01])))->render($text) ?: null;   // ECC "M"
        } catch (\Throwable $e) {
            log_message('error', 'QR generation failed: ' . $e->getMessage());
            return null;
        }
    }
}
