<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\WhatsApp\TemplateParamCheck;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for the shared template parameter-count guard that prevents
 * WhatsApp error #132000 (parameter count mismatch). No DB required.
 */
class TemplateParamCheckTest extends CIUnitTestCase
{
    // ── requiredCount ────────────────────────────────────────────────

    public function testRequiredCountFromVariablesJson(): void
    {
        $tpl = ['variables' => json_encode([['example' => 'a'], ['example' => 'b']]), 'body' => 'irrelevant'];
        $this->assertSame(2, TemplateParamCheck::requiredCount($tpl));
    }

    public function testRequiredCountFallsBackToBodyPlaceholders(): void
    {
        // variables column empty → count distinct {{n}} in body
        $tpl = ['variables' => '[]', 'body' => 'Hi {{1}}, welcome to {{2}} — see {{1}} again'];
        $this->assertSame(2, TemplateParamCheck::requiredCount($tpl));
    }

    public function testRequiredCountZeroForStaticTemplate(): void
    {
        $tpl = ['variables' => '[]', 'body' => 'Welcome and congratulations!'];
        $this->assertSame(0, TemplateParamCheck::requiredCount($tpl));
    }

    // ── error() ──────────────────────────────────────────────────────

    public function testNoErrorWhenMappingComplete(): void
    {
        $tpl = ['name' => 't', 'variables' => json_encode([['example' => 'a'], ['example' => 'b']])];
        $this->assertNull(TemplateParamCheck::error($tpl, ['1' => 'name', '2' => 'company']));
    }

    public function testNoErrorWhenTemplateHasNoVariables(): void
    {
        $tpl = ['name' => 'welcome', 'variables' => '[]', 'body' => 'Hi there!'];
        $this->assertNull(TemplateParamCheck::error($tpl, []));
    }

    public function testErrorWhenMappingEmptyButVariablesRequired(): void
    {
        // This is the exact flow failure: 2-var template, nothing mapped → #132000.
        $tpl = ['name' => 'free_demo_invite', 'variables' => json_encode([['example' => 'a'], ['example' => 'b']])];
        $err = TemplateParamCheck::error($tpl, []);
        $this->assertNotNull($err);
        $this->assertStringContainsString('needs 2 variable(s)', $err);
        $this->assertStringContainsString('132000', $err);
    }

    public function testErrorWhenMappingPartial(): void
    {
        $tpl = ['name' => 'demo_offer', 'variables' => json_encode([['e' => 1], ['e' => 2], ['e' => 3]])];
        // maps {{1}} and {{3}} but not {{2}} → still a mismatch
        $err = TemplateParamCheck::error($tpl, ['1' => 'name', '3' => 'url']);
        $this->assertNotNull($err);
        $this->assertStringContainsString('needs 3 variable(s)', $err);
    }

    public function testAcceptsJsonStringMapping(): void
    {
        $tpl = ['name' => 't', 'variables' => json_encode([['e' => 1]])];
        $this->assertNull(TemplateParamCheck::error($tpl, '{"1":"name"}'));
        $this->assertNotNull(TemplateParamCheck::error($tpl, '{}'));
    }

    public function testNormaliseMapHandlesNullStringAndArray(): void
    {
        $this->assertSame([], TemplateParamCheck::normaliseMap(null));
        $this->assertSame([], TemplateParamCheck::normaliseMap(''));
        $this->assertSame(['1' => 'name'], TemplateParamCheck::normaliseMap('{"1":"name"}'));
        $this->assertSame(['1' => 'name'], TemplateParamCheck::normaliseMap(['1' => 'name']));
    }
}
