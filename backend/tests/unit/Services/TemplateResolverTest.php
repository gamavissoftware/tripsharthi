<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\TemplateModel;
use App\Services\Leads\TemplateResolver;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CampaignTestSchema;

/**
 * Tests language localization of templates by shared name.
 */
class TemplateResolverTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CampaignTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCampaignSchema();
    }

    private function resolver(): TemplateResolver
    {
        return new TemplateResolver(new TemplateModel());
    }

    public function testPicksMatchingLanguageVersion(): void
    {
        $this->seedApprovedTemplate(['name' => 'welcome', 'language' => 'en', 'body' => 'Hi']);
        $this->seedApprovedTemplate(['name' => 'welcome', 'language' => 'hi', 'body' => 'Namaste']);

        $base = (new TemplateModel())->setTenant(1)->where('language', 'en')->where('name', 'welcome')->first();
        $localized = $this->resolver()->localize(1, $base, 'hi');

        $this->assertSame('hi', $localized['language']);
        $this->assertSame('Namaste', $localized['body']);
    }

    public function testFallsBackWhenNoLanguageMatch(): void
    {
        $this->seedApprovedTemplate(['name' => 'welcome', 'language' => 'en', 'body' => 'Hi']);
        $base = (new TemplateModel())->setTenant(1)->where('name', 'welcome')->first();

        $localized = $this->resolver()->localize(1, $base, 'fr'); // no French version
        $this->assertSame('en', $localized['language']);
    }

    public function testFallsBackWhenVariantNotApproved(): void
    {
        $this->seedApprovedTemplate(['name' => 'promo', 'language' => 'en', 'body' => 'EN']);
        $this->seedApprovedTemplate(['name' => 'promo', 'language' => 'hi', 'body' => 'HI', 'meta_status' => 'pending']);

        $base = (new TemplateModel())->setTenant(1)->where('language', 'en')->where('name', 'promo')->first();
        $localized = $this->resolver()->localize(1, $base, 'hi');

        $this->assertSame('en', $localized['language'], 'unapproved variant must be ignored');
    }

    public function testNoLanguageReturnsBase(): void
    {
        $this->seedApprovedTemplate(['name' => 'x', 'language' => 'en']);
        $base = (new TemplateModel())->setTenant(1)->where('name', 'x')->first();
        $this->assertSame('en', $this->resolver()->localize(1, $base, null)['language']);
    }
}
