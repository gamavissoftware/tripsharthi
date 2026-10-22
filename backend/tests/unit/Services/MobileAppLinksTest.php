<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\MobileAppLinks;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** The phone-app download links: nothing is shown until a valid link is saved (group "mysql": real platform_settings table). */
#[Group('mysql')]
final class MobileAppLinksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        db_connect()->table('platform_settings')->whereIn('setting_key', [MobileAppLinks::ANDROID, MobileAppLinks::IOS])->delete();
    }

    public function testNothingIsAvailableUntilALinkIsSaved(): void
    {
        $v = MobileAppLinks::publicView();
        $this->assertFalse($v['available']);
        $this->assertNull($v['android']);
        $this->assertNull($v['ios']);
    }

    public function testSavingALinkMakesItAvailableAndBlankClearsIt(): void
    {
        $r = MobileAppLinks::save(['android' => 'https://play.google.com/store/apps/details?id=com.example.app'], 1);
        $this->assertTrue($r['available']);
        $this->assertSame('https://play.google.com/store/apps/details?id=com.example.app', $r['android']);
        $this->assertNull($r['ios'], 'a field that was not sent stays empty');

        $r = MobileAppLinks::save(['ios' => 'https://apps.apple.com/in/app/tripsarthi/id123456789'], 1);
        $this->assertNotNull($r['android'], 'saving one platform never clears the other');
        $this->assertNotNull($r['ios']);

        $r = MobileAppLinks::save(['android' => '', 'ios' => ''], 1);
        $this->assertFalse($r['available']);
    }

    public function testBadLinksAreRefusedWithAReasonAndNothingIsSaved(): void
    {
        foreach ([
            ['android' => 'http://play.google.com/store/apps/details?id=x'],      // not https
            ['android' => 'javascript:alert(1)'],
            ['android' => 'https://user:pass@play.google.com/x'],                 // credentials in the link
            ['android' => 'not a link'],
            ['android' => 'https://localhost/x'],                                 // no dot: not a public address
            ['ios' => 'https://play.google.com/store/apps/details?id=x'],         // the iPhone link must be Apple's
            ['ios' => 'https://evil.example.com/apps.apple.com'],
            ['android' => 'https://example.com/' . str_repeat('a', 400)],
        ] as $bad) {
            try { MobileAppLinks::save($bad, 1); $this->fail('accepted ' . json_encode($bad)); }
            catch (\InvalidArgumentException $e) { $this->assertNotSame('', $e->getMessage()); }
        }
        $this->assertFalse(MobileAppLinks::publicView()['available']);
    }

    public function testAnApkOrTestFlightLinkIsAccepted(): void
    {
        $r = MobileAppLinks::save(['android' => 'https://tripsarthi.com/download/tripsarthi.apk', 'ios' => 'https://testflight.apple.com/join/ABC123'], 1);
        $this->assertTrue($r['available']);
        $this->assertSame('https://testflight.apple.com/join/ABC123', $r['ios']);
    }

    public function testAStoredBadValueIsNeverShown(): void
    {
        \App\Services\Admin\PlatformSettings::set(MobileAppLinks::ANDROID, 'javascript:alert(1)', 1);   // e.g. edited straight in the database
        $this->assertNull(MobileAppLinks::publicView()['android']);
        $this->assertFalse(MobileAppLinks::publicView()['available']);
    }
}
