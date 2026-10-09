<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Libraries\OptionalId;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Saving a contact with "Unassigned" selected returned a bare HTTP 500.
 *
 * The <select>'s empty option posts '' — not null. Guards written as
 * `$v === null`, and array_filter(fn($v) => $v !== null), let '' through;
 * MySQL then coerced it to 0 on an INT column, and contacts.owner_id has a
 * foreign key to users, where there is no row 0. The insert was rejected and
 * the request died before any handler could report it usefully.
 *
 * The same shape existed in Tickets (owner_id) and Deals, which is why the
 * normalisation lives in one place. No DB required.
 */
class OptionalIdTest extends CIUnitTestCase
{
    /**
     * @dataProvider absentValues
     */
    public function testTreatsAbsentValuesAsNull(mixed $input, string $why): void
    {
        $this->assertNull(OptionalId::from($input), $why);
    }

    public static function absentValues(): array
    {
        return [
            'empty select'   => ['',      "an \"Unassigned\" option posts '' — the bug that caused the 500"],
            'whitespace'     => ['   ',   'a padded empty value is still empty'],
            'null'           => [null,    'field omitted entirely'],
            'zero int'       => [0,       'there is no user #0'],
            'zero string'    => ['0',     'there is no user #0'],
            'negative'       => [-5,      'junk id — absent beats a rejected write'],
            'non-numeric'    => ['abc',   'never coerce text to an id'],
        ];
    }

    public function testKeepsARealId(): void
    {
        $this->assertSame(4, OptionalId::from(4));
        $this->assertSame(4, OptionalId::from('4'), 'JSON numbers often arrive as strings');
    }

    /**
     * The distinction that matters: a real id must survive, and only the
     * genuinely-empty cases become null.
     */
    public function testEmptyBecomesNullWhileOneSurvives(): void
    {
        $this->assertNull(OptionalId::from(''));
        $this->assertSame(1, OptionalId::from('1'));
    }

    public function testAmountTreatsBlankAsNotProvidedRatherThanZero(): void
    {
        $this->assertNull(OptionalId::amount(''), 'blank budget is unknown, not zero');
        $this->assertNull(OptionalId::amount(null));
        $this->assertNull(OptionalId::amount('abc'));
        $this->assertSame('50000', OptionalId::amount('50000'));
        $this->assertSame('0', OptionalId::amount('0'), 'an explicit zero budget is a real answer');
    }
}
