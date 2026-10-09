<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Crm\CustomObjectValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Per-type validation for custom-object record data (Phase L gap-close).
 * Pure — exercises the field schema vs. data map without the database.
 */
class CustomObjectValidatorTest extends CIUnitTestCase
{
    private function field(string $key, string $type, array $extra = []): array
    {
        return array_merge(['field_key' => $key, 'label' => ucfirst($key), 'type' => $type, 'required' => 0, 'options' => null], $extra);
    }

    private function validate(array $fields, array $data): array
    {
        return (new CustomObjectValidator())->validate($fields, $data);
    }

    public function testRequiredFieldMissingIsRejected(): void
    {
        $errors = $this->validate([$this->field('vin', 'text', ['required' => 1])], []);
        $this->assertArrayHasKey('vin', $errors);
        $this->assertStringContainsString('required', $errors['vin']);
    }

    public function testOptionalEmptyFieldSkipsTypeCheck(): void
    {
        // Empty + optional email → no error (nothing to validate).
        $this->assertSame([], $this->validate([$this->field('email', 'email')], ['email' => '']));
    }

    public function testEmailTypeValidatesFormat(): void
    {
        $fields = [$this->field('email', 'email')];
        $this->assertArrayHasKey('email', $this->validate($fields, ['email' => 'not-an-email']));
        $this->assertSame([], $this->validate($fields, ['email' => 'a@b.com']));
    }

    public function testNumberTypeRejectsNonNumeric(): void
    {
        $fields = [$this->field('qty', 'number')];
        $this->assertArrayHasKey('qty', $this->validate($fields, ['qty' => 'ten']));
        $this->assertSame([], $this->validate($fields, ['qty' => '42']));
        $this->assertSame([], $this->validate($fields, ['qty' => 3.5]));
    }

    public function testPhoneTypeValidates(): void
    {
        $fields = [$this->field('phone', 'phone')];
        $this->assertArrayHasKey('phone', $this->validate($fields, ['phone' => 'abc']));
        $this->assertSame([], $this->validate($fields, ['phone' => '+91 98765-43210']));
    }

    public function testDateTypeRejectsImpossibleDate(): void
    {
        $fields = [$this->field('dob', 'date')];
        $this->assertArrayHasKey('dob', $this->validate($fields, ['dob' => '2026-13-40']));
        $this->assertArrayHasKey('dob', $this->validate($fields, ['dob' => '15/06/2026']));
        $this->assertSame([], $this->validate($fields, ['dob' => '2026-06-15']));
    }

    public function testBooleanTypeAcceptsBoolish(): void
    {
        $fields = [$this->field('active', 'boolean')];
        $this->assertSame([], $this->validate($fields, ['active' => 'true']));
        $this->assertSame([], $this->validate($fields, ['active' => true]));
        $this->assertSame([], $this->validate($fields, ['active' => '1']));
        $this->assertArrayHasKey('active', $this->validate($fields, ['active' => 'maybe']));
    }

    public function testSelectTypeEnforcesOptions(): void
    {
        $fieldsStr = [$this->field('stage', 'select', ['options' => json_encode(['New', 'Used'])])];
        $this->assertArrayHasKey('stage', $this->validate($fieldsStr, ['stage' => 'Refurb']));
        $this->assertSame([], $this->validate($fieldsStr, ['stage' => 'Used']));

        // Object-shaped options {value,label} are also supported.
        $fieldsObj = [$this->field('stage', 'select', ['options' => json_encode([['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']])])];
        $this->assertSame([], $this->validate($fieldsObj, ['stage' => 'a']));
        $this->assertArrayHasKey('stage', $this->validate($fieldsObj, ['stage' => 'z']));
    }

    public function testMultipleErrorsReturnedTogether(): void
    {
        $fields = [
            $this->field('email', 'email', ['required' => 1]),
            $this->field('qty', 'number'),
        ];
        $errors = $this->validate($fields, ['email' => 'bad', 'qty' => 'x']);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('qty', $errors);
    }

    public function testTextFieldIsUnconstrained(): void
    {
        $this->assertSame([], $this->validate([$this->field('notes', 'textarea')], ['notes' => 'anything at all 123 !@#']));
    }
}
