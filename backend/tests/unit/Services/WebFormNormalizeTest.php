<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Controllers\Api\WebFormsController;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Phase L (web-form builder): the admin payload is normalized to the canonical
 * [{field_key,label,required}] shape the public form view + FormHandler consume,
 * accepting legacy string arrays and always forcing wa_number.
 */
class WebFormNormalizeTest extends CIUnitTestCase
{
    public function testLegacyStringArrayCoercedToObjects(): void
    {
        $out = WebFormsController::normalizeFields(['name', 'email']);
        // wa_number is force-prepended; each entry is a full def.
        $keys = array_column($out, 'field_key');
        $this->assertContains('wa_number', $keys);
        $this->assertContains('name', $keys);
        $this->assertContains('email', $keys);
        foreach ($out as $f) {
            $this->assertArrayHasKey('field_key', $f);
            $this->assertArrayHasKey('label', $f);
            $this->assertArrayHasKey('required', $f);
        }
        $wa = array_values(array_filter($out, static fn ($f) => $f['field_key'] === 'wa_number'))[0];
        $this->assertTrue($wa['required'], 'wa_number always required');
    }

    public function testObjectShapePreservedAndDeduped(): void
    {
        $out = WebFormsController::normalizeFields([
            ['field_key' => 'wa_number', 'label' => 'Mobile', 'required' => true],
            ['field_key' => 'budget', 'label' => 'Your Budget', 'required' => true],
            ['field_key' => 'budget', 'label' => 'dup'],   // duplicate dropped
        ]);
        $this->assertCount(2, $out);
        $budget = array_values(array_filter($out, static fn ($f) => $f['field_key'] === 'budget'))[0];
        $this->assertSame('Your Budget', $budget['label']);
        $this->assertTrue($budget['required']);
    }

    public function testEmptyFallsBackToDefaults(): void
    {
        $out  = WebFormsController::normalizeFields([]);
        $keys = array_column($out, 'field_key');
        $this->assertContains('wa_number', $keys);
        $this->assertNotEmpty($out);
    }
}
