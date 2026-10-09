<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Leads\VariableResolver;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for VariableResolver. No DB required.
 */
class VariableResolverTest extends CIUnitTestCase
{
    private VariableResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new VariableResolver();
    }

    private function contact(array $overrides = []): array
    {
        return array_merge([
            'id'        => 1,
            'wa_number' => '+919999900001',
            'name'      => 'Alice',
            'email'     => 'alice@example.com',
            'status'    => 'new',
            'source'    => 'manual',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Standard field resolution
    // ------------------------------------------------------------------

    public function testResolvesNameField(): void
    {
        $result = $this->resolver->resolve(['1' => 'name'], $this->contact(), []);
        $this->assertSame([['type' => 'text', 'text' => 'Alice']], $result['body_params']);
        $this->assertSame(0, $result['missing_count']);
    }

    public function testResolvesEmailField(): void
    {
        $result = $this->resolver->resolve(['1' => 'email'], $this->contact(), []);
        $this->assertSame('alice@example.com', $result['body_params'][0]['text']);
    }

    public function testResolvesWaNumberField(): void
    {
        $result = $this->resolver->resolve(['1' => 'wa_number'], $this->contact(), []);
        $this->assertSame('+919999900001', $result['body_params'][0]['text']);
    }

    public function testResolvesMultipleVariablesInOrder(): void
    {
        $mapping = ['1' => 'name', '2' => 'email'];
        $result  = $this->resolver->resolve($mapping, $this->contact(), []);
        $this->assertCount(2, $result['body_params']);
        $this->assertSame('Alice',             $result['body_params'][0]['text']);
        $this->assertSame('alice@example.com', $result['body_params'][1]['text']);
    }

    // ------------------------------------------------------------------
    // Custom field resolution
    // ------------------------------------------------------------------

    public function testResolvesCustomField(): void
    {
        $cfv    = ['company_name' => 'Acme Corp'];
        $result = $this->resolver->resolve(['1' => 'company_name'], $this->contact(), $cfv);
        $this->assertSame('Acme Corp', $result['body_params'][0]['text']);
        $this->assertSame(0, $result['missing_count']);
    }

    // ------------------------------------------------------------------
    // Missing / blank value fallback
    // ------------------------------------------------------------------

    public function testBlankNameUsesCampaignDefaultIfSet(): void
    {
        $result = $this->resolver->resolve(
            ['1' => 'name'],
            $this->contact(['name' => '']),
            [],
            ['1' => 'friend']
        );
        $this->assertSame('friend', $result['body_params'][0]['text']);
        $this->assertSame(1, $result['missing_count']);
    }

    public function testBlankNameUsesGenericFallbackWhenNoDefault(): void
    {
        $result = $this->resolver->resolve(
            ['1' => 'name'],
            $this->contact(['name' => '']),
            []
        );
        $this->assertSame('there', $result['body_params'][0]['text']);
        $this->assertSame(1, $result['missing_count']);
    }

    public function testMissingCustomFieldUsesGenericFallback(): void
    {
        $result = $this->resolver->resolve(['1' => 'non_existent_field'], $this->contact(), []);
        $this->assertSame('there', $result['body_params'][0]['text']);
        $this->assertSame(1, $result['missing_count']);
    }

    public function testAllMissingVariablesCountedCorrectly(): void
    {
        $result = $this->resolver->resolve(
            ['1' => 'name', '2' => 'non_existent_1', '3' => 'non_existent_2'],
            $this->contact(['name' => '']),
            []
        );
        $this->assertSame(3, $result['missing_count']); // all 3 blank/missing
        // All fallback to 'there'
        foreach ($result['body_params'] as $p) {
            $this->assertSame('there', $p['text']);
        }
    }

    public function testFallbackIsNeverEmpty(): void
    {
        // Meta rejects empty-string parameters — fallback must be non-blank
        $result = $this->resolver->resolve(
            ['1' => 'name'],
            $this->contact(['name' => '']),
            []
        );
        $this->assertNotSame('', $result['body_params'][0]['text']);
        $this->assertNotEmpty($result['body_params'][0]['text']);
    }

    // ------------------------------------------------------------------
    // Empty mapping
    // ------------------------------------------------------------------

    public function testEmptyMappingReturnsEmptyParams(): void
    {
        $result = $this->resolver->resolve([], $this->contact(), []);
        $this->assertEmpty($result['body_params']);
        $this->assertSame(0, $result['missing_count']);
    }

    // ------------------------------------------------------------------
    // Output shape
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // state: — values that belong to the message, not the person
    // ------------------------------------------------------------------

    public function testAStatePrefixReadsFromRunState(): void
    {
        $result = $this->resolver->resolve(
            ['1' => 'name', '2' => 'state:followup_hook'],
            $this->contact(),
            [],
            [],
            ['followup_hook' => 'batch and expiry tracking']
        );

        $this->assertSame('batch and expiry tracking', $result['body_params'][1]['text']);
    }

    /** Meta rejects an empty body parameter, so a missing state key must fall back. */
    public function testAMissingStateKeyFallsBackToTheDefault(): void
    {
        $result = $this->resolver->resolve(
            ['1' => 'state:nothing_here'],
            $this->contact(),
            [],
            ['1' => 'your day-to-day operations'],
            []
        );

        $this->assertSame('your day-to-day operations', $result['body_params'][0]['text']);
        $this->assertSame(1, $result['missing_count']);
    }

    /** A contact field named like a state key must still read from the contact. */
    public function testStateNeverShadowsAnOrdinaryFieldMapping(): void
    {
        $result = $this->resolver->resolve(
            ['1' => 'name'],
            $this->contact(),
            [],
            [],
            ['name' => 'WRONG']
        );

        $this->assertNotSame('WRONG', $result['body_params'][0]['text']);
    }

    public function testEveryParamHasTypeAndText(): void
    {
        $result = $this->resolver->resolve(
            ['1' => 'name', '2' => 'email'],
            $this->contact(),
            []
        );
        foreach ($result['body_params'] as $param) {
            $this->assertArrayHasKey('type', $param);
            $this->assertArrayHasKey('text', $param);
            $this->assertSame('text', $param['type']);
        }
    }
}
