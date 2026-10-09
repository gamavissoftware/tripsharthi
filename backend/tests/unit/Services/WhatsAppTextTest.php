<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\WhatsApp\WhatsAppText;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * A real customer received this from the AI assistant:
 *
 *   "1. **What do you manufacture?** (e.g., pumps, motors, gears)"
 *
 * WhatsApp is not Markdown — it renders *bold* with SINGLE asterisks — so the
 * double asterisks showed literally. Small, but it reads as broken software at
 * the exact moment you are trying to look credible to a prospect.
 *
 * A prompt instruction alone is a request, not a guarantee; models drift back
 * to Markdown on longer replies. So the output is repaired too. No DB required.
 */
class WhatsAppTextTest extends CIUnitTestCase
{
    /** The exact text that went to the customer. */
    public function testRepairsTheReplyThatWentOut(): void
    {
        $out = WhatsAppText::normalise('1. **What do you manufacture?** (e.g., pumps, motors, gears)');

        $this->assertSame('1. *What do you manufacture?* (e.g., pumps, motors, gears)', $out);
        $this->assertStringNotContainsString('**', $out);
    }

    public function testConvertsDoubleUnderscoreToItalic(): void
    {
        $this->assertSame('_urgent_', WhatsAppText::normalise('__urgent__'));
    }

    /**
     * WhatsApp's own bold must survive untouched — over-correcting would break
     * text that was already right.
     */
    public function testLeavesWhatsAppFormattingAlone(): void
    {
        $this->assertSame('*already bold* and _already italic_', WhatsAppText::normalise('*already bold* and _already italic_'));
    }

    public function testHeadingsBecomeBoldRatherThanHashes(): void
    {
        $this->assertSame("*Next steps*\nCall them", WhatsAppText::normalise("## Next steps\nCall them"));
    }

    public function testMarkdownLinksBecomeReadableUrls(): void
    {
        $this->assertSame(
            'our site: https://gamavis.com',
            WhatsAppText::normalise('[our site](https://gamavis.com)')
        );
    }

    public function testBareLinkDropsTheDuplicateLabel(): void
    {
        $this->assertSame('https://gamavis.com', WhatsAppText::normalise('[https://gamavis.com](https://gamavis.com)'));
    }

    public function testBulletsBecomeRealBullets(): void
    {
        $this->assertSame("• production\n• inventory", WhatsAppText::normalise("- production\n- inventory"));
    }

    /**
     * A line starting with bold is not a bullet. The bullet rule requires a
     * space after the marker precisely so this survives.
     */
    public function testBoldAtLineStartIsNotMistakenForABullet(): void
    {
        $this->assertSame('*Production* is the issue', WhatsAppText::normalise('*Production* is the issue'));
    }

    public function testMultiplicationAndPlainAsterisksSurvive(): void
    {
        $this->assertSame('5 * 4 = 20', WhatsAppText::normalise('5 * 4 = 20'));
    }

    public function testPromptRuleNamesTheTrapExplicitly(): void
    {
        $rule = WhatsAppText::promptRule();

        $this->assertStringContainsString('**', $rule, 'must warn against the exact thing that broke');
        $this->assertStringContainsString('WhatsApp', $rule);
    }
}
