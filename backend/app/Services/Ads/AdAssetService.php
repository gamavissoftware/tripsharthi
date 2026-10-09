<?php

declare(strict_types=1);

namespace App\Services\Ads;

/**
 * Image library for ads. Uploads are validated by CONTENT (never by the filename or declared type), size/pixel-capped, and RE-ENCODED
 * (strips EXIF/GPS and anything hidden in the file, and guarantees a clean JPEG/PNG). The same stored file is reused for Meta
 * (uploaded once per ad account -> image hash) and Google (sent inline as an image asset).
 */
final class AdAssetService
{
    public const MAX_BYTES = 5_242_880;
    public const MAX_SIDE = 8000;
    public const MIN_SIDE = 400;

    public function __construct(private readonly ?int $now = null) {}
    private static function dir(): string { return trim((string) (getenv('AD_ASSET_STORAGE_DIR') ?: 'uploads/ad-assets'), '/'); }

    /** @return array{mime:string,ext:string,width:int,height:int,shape:string} @throws \InvalidArgumentException */
    public static function inspect(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) { throw new \InvalidArgumentException('The image must be under 5 MB.'); }
        $info = @getimagesizefromstring($bytes);
        $types = [IMAGETYPE_JPEG => ['image/jpeg', 'jpg'], IMAGETYPE_PNG => ['image/png', 'png']];
        if ($info === false || ! isset($types[$info[2]])) { throw new \InvalidArgumentException('Please upload a JPG or PNG image.'); }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w > self::MAX_SIDE || $h > self::MAX_SIDE) { throw new \InvalidArgumentException('The image is too large (max ' . self::MAX_SIDE . ' px on a side).'); }
        if ($w < self::MIN_SIDE || $h < self::MIN_SIDE) { throw new \InvalidArgumentException('The image is too small: at least ' . self::MIN_SIDE . ' px on each side (1080 px or more looks best).'); }
        return ['mime' => $types[$info[2]][0], 'ext' => $types[$info[2]][1], 'width' => $w, 'height' => $h, 'shape' => self::shape($w, $h)];
    }

    /** landscape 1.91:1, square 1:1, portrait 4:5, tall 9:16 (each within 3 %) — what Google asset groups and Meta placements ask for. */
    public static function shape(int $w, int $h): string
    {
        $r = $w / $h;
        foreach (['landscape' => 1.91, 'square' => 1.0, 'portrait' => 0.8, 'tall' => 0.5625] as $name => $target) { if (abs($r - $target) / $target <= 0.03) { return $name; } }
        return 'other';
    }

    /** Demand Gen is stricter than Performance Max: exact ratio (+-1 %) AND a minimum size per slot. slot: landscape|square|portrait|tall|logo */
    public const STRICT = ['landscape' => [1.91, 600, 314], 'square' => [1.0, 300, 300], 'portrait' => [0.8, 480, 600], 'tall' => [0.5625, 600, 1067], 'logo' => [1.0, 128, 128]];

    /** @return string|null what is wrong with using this image in this slot, or null when it fits */
    public static function strictProblem(int $w, int $h, string $slot, string $name = 'This image'): ?string
    {
        [$ratio, $minW, $minH] = self::STRICT[$slot] ?? [0, 0, 0];
        if ($ratio <= 0) { return "Unknown image slot {$slot}."; }
        $actual = $w / max(1, $h);
        if (abs($actual - $ratio) / $ratio > 0.01) { return sprintf('“%s” is %d×%d (%.2f:1), but the %s slot needs %s (±1%%).', $name, $w, $h, $actual, $slot, ['landscape' => '1.91:1', 'square' => '1:1', 'portrait' => '4:5', 'tall' => '9:16', 'logo' => '1:1'][$slot]); }
        if ($w < $minW || $h < $minH) { return sprintf('“%s” is %d×%d, but the %s slot needs at least %d×%d.', $name, $w, $h, $slot, $minW, $minH); }
        return null;
    }

    /** Decode and re-encode: drops metadata and any trailing/embedded payload. PNG stays PNG (logos need transparency), everything else JPEG. */
    private static function clean(string $bytes, string $mime): string
    {
        $im = @imagecreatefromstring($bytes);
        if ($im === false) { throw new \InvalidArgumentException('That image could not be read. Try exporting it again as a JPG or PNG.'); }
        ob_start();
        if ($mime === 'image/png') { imagesavealpha($im, true); imagepng($im, null, 6); }
        else { imagejpeg($im, null, 90); }
        $out = (string) ob_get_clean();
        imagedestroy($im);
        return $out;
    }

    /** @param array{tmp_name:string,name:string,size:int} $file */
    public function store(int $tenantId, array $file, ?int $userId = null): array
    {
        $raw = (string) @file_get_contents($file['tmp_name']);
        $m = self::inspect($raw);
        $clean = self::clean($raw, $m['mime']);
        $info = self::inspect($clean);                              // the stored bytes must pass too (and give the true dimensions)
        $hash = hash('sha256', $clean);
        $db = db_connect();
        if ($dupe = $db->table('ad_assets')->where('tenant_id', $tenantId)->where('sha256', $hash)->where('deleted_at', null)->get()->getRowArray()) { return $this->view($dupe); }   // same image twice = one asset
        $rel = self::dir() . '/' . $tenantId . '/' . bin2hex(random_bytes(12)) . '.' . $info['ext'];
        $path = WRITEPATH . $rel;
        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0750, true)) { throw new \RuntimeException('Could not store the image.'); }
        if (file_put_contents($path, $clean, LOCK_EX) === false) { throw new \RuntimeException('Could not store the image.'); }
        $name = mb_substr(preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename((string) $file['name'])) ?: 'image.' . $info['ext'], 0, 200);
        $db->table('ad_assets')->insert(['tenant_id' => $tenantId, 'original_name' => $name, 'mime' => $info['mime'], 'width' => $info['width'], 'height' => $info['height'], 'shape' => $info['shape'], 'size' => strlen($clean),
            'path' => $rel, 'sha256' => $hash, 'created_by' => $userId, 'created_at' => date('Y-m-d H:i:s', $this->now ?? time())]);
        return $this->view($this->row($tenantId, (int) $db->insertID()));
    }

    private function row(int $tenantId, int $id): array
    {
        $r = db_connect()->table('ad_assets')->where('tenant_id', $tenantId)->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        if (! $r) { throw new \InvalidArgumentException('Image not found.'); }
        return $r;
    }

    private function view(array $r): array
    {
        return ['id' => (int) $r['id'], 'name' => $r['original_name'], 'width' => (int) $r['width'], 'height' => (int) $r['height'], 'shape' => $r['shape'], 'size' => (int) $r['size'], 'mime' => $r['mime']];
    }

    public function list(int $tenantId): array
    {
        return array_map(fn ($r) => $this->view($r), db_connect()->table('ad_assets')->where('tenant_id', $tenantId)->where('deleted_at', null)->orderBy('id', 'DESC')->limit(200)->get()->getResultArray());
    }

    public function delete(int $tenantId, int $id): void
    {
        $r = $this->row($tenantId, $id);
        db_connect()->table('ad_assets')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s', $this->now ?? time())]);
        @unlink(WRITEPATH . $r['path']);
    }

    /** @return array{bytes:string,mime:string,name:string,width:int,height:int,shape:string} integrity-checked */
    public function read(int $tenantId, int $id): array
    {
        $r = $this->row($tenantId, $id);
        $path = WRITEPATH . $r['path'];
        $bytes = is_file($path) ? (string) file_get_contents($path) : '';
        if ($bytes === '' || hash('sha256', $bytes) !== $r['sha256']) { throw new \RuntimeException('This image file is missing or damaged. Upload it again.'); }
        return ['bytes' => $bytes, 'mime' => $r['mime'], 'name' => $r['original_name'], 'width' => (int) $r['width'], 'height' => (int) $r['height'], 'shape' => $r['shape']];
    }

    // ---- Meta ------------------------------------------------------------------------------------------------------

    /** Upload one stored image to a Meta ad account (once; the hash is remembered). */
    public function metaHash(int $tenantId, MetaMarketingClient $client, string $account, int $id): string
    {
        $r = $this->row($tenantId, $id);
        $known = json_decode((string) ($r['meta_hashes'] ?? '{}'), true) ?: [];
        if (! empty($known[$account])) { return (string) $known[$account]; }
        $img = $this->read($tenantId, $id);
        $res = $client->request('POST', "{$account}/adimages", ['bytes' => base64_encode($img['bytes'])]);
        $first = is_array($res['images'] ?? null) ? reset($res['images']) : null;
        $hash = is_array($first) ? (string) ($first['hash'] ?? '') : '';
        if ($hash === '') { throw new AdsApiException('Meta did not accept the image. Try a different file (JPG/PNG, at least 600 px wide).', AdsApiException::INVALID); }
        $known[$account] = $hash;
        db_connect()->table('ad_assets')->where('id', $id)->update(['meta_hashes' => json_encode($known)]);
        return $hash;
    }

    /** Replace creative.image_asset_id (campaign-wide and per ad set) with the hash Meta issued, uploading as needed. */
    public function resolveMetaImages(int $tenantId, MetaMarketingClient $client, array $spec): array
    {
        $acct = (string) $spec['account_id'];
        $fix = function (array $c) use ($tenantId, $client, $acct): array {
            if ((int) ($c['image_asset_id'] ?? 0) > 0) { $c['image_hash'] = $this->metaHash($tenantId, $client, $acct, (int) $c['image_asset_id']); }
            return $c;
        };
        $spec['creative'] = $fix((array) ($spec['creative'] ?? []));
        foreach ((array) ($spec['adsets'] ?? []) as $i => $set) { if (isset($set['creative'])) { $spec['adsets'][$i]['creative'] = $fix((array) $set['creative']); } }
        return $spec;
    }
}
