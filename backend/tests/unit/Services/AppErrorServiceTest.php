<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\AppErrorService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Crash reports from the phone: capped, cleaned, grouped (group "mysql"). */
#[Group('mysql')]
final class AppErrorServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void { parent::setUp(); db_connect()->table('app_errors')->truncate(); }

    public function testAReportIsStoredCappedAndCleaned(): void
    {
        $this->assertTrue(AppErrorService::record(['message' => "Cannot read property 'x'\x00 of undefined" . str_repeat('!', 900), 'version' => '1.1.0', 'platform' => 'android', 'screen' => 'Booking', 'stack' => str_repeat('at f()\n', 2000)], 5, 2, '1.2.3.4'), 'stored');
        $row = db_connect()->table('app_errors')->get()->getRowArray();
        $this->assertSame(300, mb_strlen($row['message']));
        $this->assertStringNotContainsString("\x00", $row['message']);
        $this->assertLessThanOrEqual(4000, mb_strlen($row['stack']));
        $this->assertSame('1.1.0', $row['app_version']);
        $this->assertSame(32, strlen($row['ip_hash']));
        $this->assertStringNotContainsString('1.2.3.4', (string) json_encode($row), 'the address is only kept as a hash');
    }

    public function testJunkIsRefusedAndOddValuesAreNeutralised(): void
    {
        $this->assertFalse(AppErrorService::record(['message' => '   '], null, null, ''));
        AppErrorService::record(['message' => 'boom', 'version' => '<script>', 'platform' => 'windows-phone'], null, null, '');
        $row = db_connect()->table('app_errors')->get()->getRowArray();
        $this->assertNull($row['app_version']);
        $this->assertSame('android', $row['platform']);
    }

    public function testTheSameCrashSeenManyTimesIsOneLineWithACount(): void
    {
        foreach ([1, 1, 2, 3] as $uid) { AppErrorService::record(['message' => 'Network exploded', 'version' => '1.1.0', 'stack' => 'at a'], $uid, 1, ''); }
        AppErrorService::record(['message' => 'Something else', 'version' => '1.1.0'], 1, 1, '');
        $list = AppErrorService::recent();
        $this->assertCount(2, $list);
        $top = array_values(array_filter($list, static fn ($r) => $r['message'] === 'Network exploded'))[0];
        $this->assertSame(4, $top['times']);
        $this->assertSame(3, $top['people']);
    }
}
