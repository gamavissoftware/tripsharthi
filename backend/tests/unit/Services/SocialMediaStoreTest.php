<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Social\SocialMediaStore;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Creative uploads for the Social Planner.
 *
 * The part that matters is pathFor(): the served route is PUBLIC (Graph has to
 * fetch the photo with no session), so the only thing standing between a
 * request and the filesystem is this validation. It must refuse traversal, a
 * non-numeric tenant, and any name that is not one of our own random tokens.
 *
 * Type and size limits are asserted too — rejecting at upload gives the user a
 * clear message instead of a Graph error hours later when the post is due.
 */
final class SocialMediaStoreTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = SocialMediaStore::baseDir() . '/1';
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
    }

    private function makeFile(string $name): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, 'not-really-an-image');

        return $path;
    }

    public function testPathForAcceptsAStoredRandomName(): void
    {
        $name = str_repeat('a', 32) . '.jpg';
        $this->makeFile($name);

        $this->assertSame($this->dir . '/' . $name, SocialMediaStore::pathFor('1', $name));

        unlink($this->dir . '/' . $name);
    }

    public function testPathForRejectsTraversal(): void
    {
        $this->assertNull(SocialMediaStore::pathFor('1', '../../../.env'));
        $this->assertNull(SocialMediaStore::pathFor('..', str_repeat('a', 32) . '.jpg'));
        $this->assertNull(SocialMediaStore::pathFor('1', str_repeat('a', 32) . '.jpg/../../.env'));
    }

    public function testPathForRejectsANonNumericTenant(): void
    {
        $this->assertNull(SocialMediaStore::pathFor('1x', str_repeat('a', 32) . '.jpg'));
        $this->assertNull(SocialMediaStore::pathFor('', str_repeat('a', 32) . '.jpg'));
    }

    public function testPathForRejectsNamesWeDidNotGenerate(): void
    {
        // Right shape, wrong alphabet / length / extension.
        $this->makeFile('photo.jpg');

        $this->assertNull(SocialMediaStore::pathFor('1', 'photo.jpg'));
        $this->assertNull(SocialMediaStore::pathFor('1', str_repeat('A', 32) . '.jpg'));
        $this->assertNull(SocialMediaStore::pathFor('1', str_repeat('a', 31) . '.jpg'));
        $this->assertNull(SocialMediaStore::pathFor('1', str_repeat('a', 32) . '.php'));

        unlink($this->dir . '/photo.jpg');
    }

    public function testPathForReturnsNullWhenTheFileIsMissing(): void
    {
        $this->assertNull(SocialMediaStore::pathFor('1', str_repeat('b', 32) . '.png'));
    }

    public function testMimeForMapsBackFromTheExtension(): void
    {
        $this->assertSame('image/jpeg', SocialMediaStore::mimeFor('x.jpg'));
        $this->assertSame('image/png', SocialMediaStore::mimeFor('x.png'));
        $this->assertSame('image/webp', SocialMediaStore::mimeFor('x.webp'));
        $this->assertSame('application/octet-stream', SocialMediaStore::mimeFor('x.txt'));
    }

    public function testOnlyImageTypesGraphAcceptsAreAllowed(): void
    {
        $this->assertArrayHasKey('image/jpeg', SocialMediaStore::ALLOWED);
        $this->assertArrayHasKey('image/png', SocialMediaStore::ALLOWED);
        $this->assertArrayNotHasKey('application/pdf', SocialMediaStore::ALLOWED);
        $this->assertArrayNotHasKey('video/mp4', SocialMediaStore::ALLOWED);
    }

    public function testSizeCeilingMatchesInstagrams(): void
    {
        $this->assertSame(8 * 1024 * 1024, SocialMediaStore::MAX_BYTES);
    }

    public function testPublicUrlIsAbsoluteAndUnderTheApiPrefix(): void
    {
        $name = str_repeat('c', 32) . '.png';
        $url  = SocialMediaStore::publicUrl(1, $name);

        // /api/ is the only prefix production nginx hands to PHP — a URL
        // outside it would silently return the SPA and Graph would get HTML.
        $this->assertStringContainsString('/api/v1/social/media/1/' . $name, $url);
        $this->assertMatchesRegularExpression('#^https?://#', $url);
    }
}
