<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\WhatsApp\TemplateComponentBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests send-time component building — especially the header rule that caused
 * Meta error #132000: a STATIC text header must carry zero parameters.
 */
class TemplateComponentBuilderTest extends CIUnitTestCase
{
    private function tpl(array $o = []): array
    {
        return array_merge([
            'header_type'    => 'none',
            'header_content' => null,
            'body'           => 'Hi {{1}}',
        ], $o);
    }

    public function testStaticTextHeaderEmitsNoHeaderComponent(): void
    {
        $components = TemplateComponentBuilder::forSend(
            $this->tpl(['header_type' => 'text', 'header_content' => 'Big News - Something New']),
            [['type' => 'text', 'text' => 'Asha']]
        );

        $types = array_column($components, 'type');
        $this->assertNotContains('header', $types, 'static text header must NOT send a parameter (#132000)');
        $this->assertContains('body', $types);
    }

    public function testVariableTextHeaderEmitsHeaderParameter(): void
    {
        $components = TemplateComponentBuilder::forSend(
            $this->tpl(['header_type' => 'text', 'header_content' => 'Hello {{1}}']),
            [['type' => 'text', 'text' => 'Asha']]
        );
        $this->assertContains('header', array_column($components, 'type'));
    }

    public function testMediaHeaderAlwaysEmitsComponent(): void
    {
        $components = TemplateComponentBuilder::forSend(
            $this->tpl(['header_type' => 'image', 'header_content' => 'https://x/i.jpg']),
            [['type' => 'text', 'text' => 'Asha']]
        );
        $header = array_values(array_filter($components, fn ($c) => $c['type'] === 'header'))[0] ?? null;
        $this->assertNotNull($header);
        $this->assertSame('image', $header['parameters'][0]['type']);
    }

    public function testBodyParamsCountMatchesInput(): void
    {
        $components = TemplateComponentBuilder::forSend(
            $this->tpl(['header_type' => 'text', 'header_content' => 'Static']),
            [['type' => 'text', 'text' => 'a'], ['type' => 'text', 'text' => 'b'], ['type' => 'text', 'text' => 'c']]
        );
        $body = array_values(array_filter($components, fn ($c) => $c['type'] === 'body'))[0];
        $this->assertCount(3, $body['parameters']);
    }

    public function testNoBodyParamsEmitsNoBodyComponent(): void
    {
        $components = TemplateComponentBuilder::forSend($this->tpl(), []);
        $this->assertSame([], $components);
    }

    // ── Carousel ──────────────────────────────────────────────────────

    private function carouselTpl(): array
    {
        return $this->tpl([
            'body'  => 'Welcome {{1}}',
            'cards' => json_encode([
                ['image_url' => 'https://x/a.jpg', 'body' => 'Card A',
                 'buttons' => [['type' => 'URL', 'text' => 'Open', 'url' => 'https://x/a']]],
                ['image_url' => 'https://x/b.jpg', 'body' => 'Card B',
                 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'More']]],
            ]),
        ]);
    }

    public function testCarouselSubmissionBuildsCarouselComponent(): void
    {
        $c = TemplateComponentBuilder::forSubmission($this->carouselTpl());
        $carousel = array_values(array_filter($c, static fn ($x) => $x['type'] === 'CAROUSEL'))[0] ?? null;

        $this->assertNotNull($carousel, 'a CAROUSEL component must be present');
        $this->assertCount(2, $carousel['cards']);
        $comps = $carousel['cards'][0]['components'];
        $this->assertSame('HEADER', $comps[0]['type']);
        $this->assertSame('IMAGE', $comps[0]['format']);
        $this->assertSame(['https://x/a.jpg'], $comps[0]['example']['header_handle']);
        $this->assertSame('URL', $comps[2]['buttons'][0]['type']);
    }

    public function testCarouselSendBuildsPerCardImageAndPayloads(): void
    {
        $c = TemplateComponentBuilder::forSend($this->carouselTpl(), [['type' => 'text', 'text' => 'Asha']]);
        $carousel = array_values(array_filter($c, static fn ($x) => ($x['type'] ?? '') === 'carousel'))[0] ?? null;

        $this->assertNotNull($carousel);
        $this->assertSame(0, $carousel['cards'][0]['card_index']);
        $this->assertSame('https://x/a.jpg', $carousel['cards'][0]['components'][0]['parameters'][0]['image']['link']);
        $qrCard = $carousel['cards'][1]['components'];
        $btn = array_values(array_filter($qrCard, static fn ($x) => ($x['type'] ?? '') === 'button'))[0] ?? null;
        $this->assertNotNull($btn, 'quick-reply button needs a send-time payload');
        $this->assertSame('quick_reply', $btn['sub_type']);
    }

    // ------------------------------------------------------------------
    // Submission examples — what the Meta reviewer actually reads
    // ------------------------------------------------------------------

    private function submissionBody(array $overrides = []): array
    {
        $tpl = array_merge([
            'name'        => 'x',
            'language'    => 'en',
            'category'    => 'marketing',
            'header_type' => 'none',
            'body'        => 'Hi {{1}}, about {{2}}.',
            'footer'      => null,
            'buttons'     => null,
            'variables'   => null,
        ], $overrides);

        foreach (TemplateComponentBuilder::forSubmission($tpl) as $c) {
            if ($c['type'] === 'BODY') {
                return $c;
            }
        }

        return [];
    }

    /** Every seeder stores bare samples; only the descriptor shape used to work. */
    public function testBareStringSamplesAreSentToMeta(): void
    {
        $body = $this->submissionBody([
            'variables' => json_encode(['1' => 'Rajesh', '2' => 'batch and expiry tracking']),
        ]);

        $this->assertSame(
            [['Rajesh', 'batch and expiry tracking']],
            $body['example']['body_text']
        );
    }

    public function testDescriptorSamplesStillWork(): void
    {
        $body = $this->submissionBody([
            'variables' => json_encode(['1' => ['example' => 'Rajesh']]),
        ]);

        $this->assertSame([['Rajesh']], $body['example']['body_text']);
    }

    /** {{1}} must be the first example regardless of the stored key order. */
    public function testSamplesAreOrderedByVariableNumber(): void
    {
        $body = $this->submissionBody([
            'variables' => json_encode(['2' => 'second', '1' => 'first']),
        ]);

        $this->assertSame([['first', 'second']], $body['example']['body_text']);
    }

    /** Meta rejects an empty example, so a blank sample still needs filling. */
    public function testABlankSampleFallsBackToAPlaceholder(): void
    {
        $body = $this->submissionBody([
            'variables' => json_encode(['1' => '   ']),
        ]);

        $this->assertSame([['example_value']], $body['example']['body_text']);
    }

    public function testNoVariablesMeansNoExampleBlock(): void
    {
        $this->assertArrayNotHasKey('example', $this->submissionBody(['body' => 'No vars here.']));
    }
}
