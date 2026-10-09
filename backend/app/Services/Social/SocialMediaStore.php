<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Services\Email\EmailService;
use CodeIgniter\HTTP\Files\UploadedFile;
use InvalidArgumentException;
use RuntimeException;

/**
 * Stores creatives for social posts and hands back a public URL.
 *
 * Meta fetches a photo by URL rather than accepting bytes, so an uploaded
 * creative has to live somewhere its servers can GET without a session. Files
 * land in writable/uploads/social/{tenant}/ under a random name, and
 * Public\SocialMediaController streams them back.
 *
 * The random name IS the access control. That is deliberate and sufficient
 * here: the file is about to be published on a public Facebook Page. Do not
 * reuse this store for anything private.
 */
class SocialMediaStore
{
    /** Graph publishes these; anything else is rejected at upload. */
    public const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /** Instagram's own ceiling is 8 MB, so refuse above that rather than fail at publish. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    public static function baseDir(): string
    {
        return rtrim(WRITEPATH, '/\\') . '/uploads/social';
    }

    /**
     * @return array{url:string,filename:string,mime:string,bytes:int}
     */
    public function store(UploadedFile $file, int $tenantId): array
    {
        $mime = $file->getMimeType();

        if (! isset(self::ALLOWED[$mime])) {
            throw new InvalidArgumentException(
                'That file is a ' . $mime . '. Facebook and Instagram only accept JPEG, PNG, GIF or WebP images.'
            );
        }

        $bytes = (int) $file->getSize();
        if ($bytes > self::MAX_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'That image is %.1f MB. The limit is %d MB — Instagram rejects anything larger.',
                $bytes / 1048576,
                self::MAX_BYTES / 1048576
            ));
        }

        $dir = self::baseDir() . '/' . $tenantId;
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }

        // Random name, not the user's: it is the only thing guarding the file,
        // and it also sidesteps collisions and traversal in the served path.
        $name = bin2hex(random_bytes(16)) . '.' . self::ALLOWED[$mime];

        if (! $file->move($dir, $name)) {
            throw new RuntimeException('move() failed: ' . $file->getErrorString());
        }

        return [
            'url'      => self::publicUrl($tenantId, $name),
            'filename' => $tenantId . '/' . $name,
            'mime'     => $mime,
            'bytes'    => $bytes,
        ];
    }

    public static function publicUrl(int $tenantId, string $name): string
    {
        return EmailService::appUrl() . '/api/v1/social/media/' . $tenantId . '/' . $name;
    }

    /**
     * Absolute path for a "{tenant}/{name}" reference, or null if it does not
     * resolve to a real file inside the store. Both halves are pattern-checked
     * so no traversal can reach outside baseDir().
     */
    public static function pathFor(string $tenant, string $name): ?string
    {
        if (! preg_match('/^\d+$/', $tenant)) {
            return null;
        }

        $exts = implode('|', array_unique(array_values(self::ALLOWED)));
        if (! preg_match('/^[a-f0-9]{32}\.(' . $exts . ')$/', $name)) {
            return null;
        }

        $path = self::baseDir() . '/' . $tenant . '/' . $name;

        return is_file($path) ? $path : null;
    }

    public static function mimeFor(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return array_search($ext, self::ALLOWED, true) ?: 'application/octet-stream';
    }
}
