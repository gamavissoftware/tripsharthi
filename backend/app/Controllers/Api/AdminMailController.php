<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Email\PlatformMail;

/** /api/v1/admin/mail - the mail server TripSarthi's own e-mails go through (filter: adminauth). The password is write-only. 422 with plain words, never 401. */
class AdminMailController extends TravelBaseController
{
    private function run(callable $fn)
    {
        try { return $this->ok($fn()); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
    }

    private function adminEmail(): string
    {
        $r = db_connect()->table('users')->select('email')->where('id', CurrentUser::id())->get()->getRowArray();
        $e = (string) ($r['email'] ?? '');
        if (! filter_var($e, FILTER_VALIDATE_EMAIL)) { throw new \InvalidArgumentException('Your admin account has no usable e-mail address to send the test to.'); }
        return $e;
    }

    public function show()  { return $this->run(fn () => PlatformMail::status()); }
    public function save()
    {
        $b = $this->body();
        return $this->run(fn () => PlatformMail::save($b, CurrentUser::id(), $this->adminEmail()));
    }
    public function test()  { return $this->run(fn () => PlatformMail::test($this->adminEmail())); }
    public function clear() { return $this->run(fn () => PlatformMail::clear(CurrentUser::id())); }
}
