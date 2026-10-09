<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

/**
 * Fetches an image for embedding in a PDF — SAFELY. PDF engines that fetch URLs themselves are a classic SSRF and
 * file-read vector, so the engine is never allowed to: we download here, and embed re-encoded pixels only.
 *   - https only; the host must resolve to a PUBLIC address (checked on the IP we then PIN the connection to,
 *     defeating DNS rebinding); no redirects; 6 s timeout; 4 MB cap
 *   - only JPEG/PNG/GIF/WebP; SVG is never accepted; declared pixel count capped (decompression bombs)
 *   - re-encoded through GD to JPEG (strips metadata and any payload) and downscaled
 * Any failure returns null and the document simply renders without the image.
 */
final class SafeImage
{
    private const MAX_BYTES = 4_000_000;
    private const MAX_PIXELS = 25_000_000;

    public static function isPublicHttpUrl(string $url): bool
    {
        $p = parse_url($url);
        if (! $p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user'])) { return false; }
        $host = strtolower(trim($p['host'], '[]'));                   // parse_url keeps the brackets of an IPv6 literal
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) { return false; }
        if (filter_var($host, FILTER_VALIDATE_IP)) { return self::publicIp($host); }
        return true;
    }

    public static function publicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** @return string|null data: URI of a JPEG, or null */
    public static function dataUri(?string $url, int $maxWidth = 1100): ?string
    {
        if (! $url || ! self::isPublicHttpUrl($url)) { return null; }
        $cache = WRITEPATH . 'cache/pdf-img/' . sha1($url . $maxWidth) . '.jpg';
        if (is_file($cache) && filemtime($cache) > time() - 86400) { return 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($cache)); }

        $bytes = self::download($url);
        $jpeg  = $bytes === null ? null : self::reencode($bytes, $maxWidth);
        if ($jpeg === null) { return null; }
        if (! is_dir(dirname($cache))) { @mkdir(dirname($cache), 0755, true); }
        @file_put_contents($cache, $jpeg);
        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    private static function download(string $url): ?string
    {
        $p = parse_url($url);
        $host = trim($p['host'], '[]');
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if ($ip === $host && ! filter_var($host, FILTER_VALIDATE_IP)) { return null; }           // did not resolve
        if (! self::publicIp($ip)) { return null; }
        $port = $p['port'] ?? 443;
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"], CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'TravelPilot-PDF/1.0',
            CURLOPT_WRITEFUNCTION => static function ($c, $chunk) use (&$buf) { $buf .= $chunk; return strlen($buf) > self::MAX_BYTES ? 0 : strlen($chunk); },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($ok !== false && $status === 200 && $buf !== '') ? $buf : null;
    }

    /** Validate + re-encode. Public so it can be tested without a network. */
    public static function reencode(string $bytes, int $maxWidth = 1100): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) { return null; }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) { return null; }
        $src = @imagecreatefromstring($bytes);
        if (! $src) { return null; }
        $w = imagesx($src); $h = imagesy($src);
        $nw = min($w, $maxWidth); $nh = (int) round($h * $nw / $w);
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));        // flatten transparency onto white
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($dst, null, 82);
        return (string) ob_get_clean();
    }
}
