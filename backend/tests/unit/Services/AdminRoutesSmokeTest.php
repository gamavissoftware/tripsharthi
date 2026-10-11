<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every admin / support endpoint must LOAD and answer 401 without a login. A controller that fails to load (e.g. a method that clashes with
 * ResourceController) answers 500 instead - exactly what slipped through once and only showed on the live server.
 */
#[Group('mysql')]
final class AdminRoutesSmokeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    public function testEveryAdminAndSupportRouteLoadsAndRequiresALogin(): void
    {
        $get = ['admin/overview', 'admin/tenants', 'admin/subscriptions', 'admin/coupons', 'admin/promotions', 'admin/grants', 'admin/partners', 'admin/whatsapp/overview', 'admin/whatsapp/marketing',
                'admin/payments/razorpay', 'admin/mail', 'admin/support', 'admin/email', 'admin/impersonations', 'support/tickets', 'support/unread'];
        foreach ($get as $path) { $this->get('api/v1/' . $path)->assertStatus(401, "GET $path should answer 401, not crash"); }
        foreach (['admin/support/1/reply', 'admin/email/campaigns/1/send', 'admin/mail/test', 'admin/payments/razorpay/test', 'support/tickets', 'admin/tenants/1/impersonate', 'admin/impersonations/1/end'] as $path) {
            $this->post('api/v1/' . $path)->assertStatus(401, "POST $path should answer 401, not crash");
        }
        foreach (['admin/mail', 'admin/payments/razorpay'] as $path) { $this->put('api/v1/' . $path)->assertStatus(401, "PUT $path"); $this->delete('api/v1/' . $path)->assertStatus(401, "DELETE $path"); }
    }
}
