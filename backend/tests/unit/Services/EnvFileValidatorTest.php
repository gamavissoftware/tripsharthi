<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Env\EnvFileValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * A malformed .env took production down for 45 hours on 8 Sep 2026: the line
 * `META_APP_SECRET = <new secret>` (an unquoted placeholder containing a space)
 * made DotEnv throw inside Boot::loadDotEnv(), before the logger existed. Every
 * request returned a bare 500 with nothing in any log, while the static frontend
 * kept serving — so the site looked up but showed no data.
 *
 * These cases pin the parser rules this validator mirrors. No DB required.
 */
class EnvFileValidatorTest extends CIUnitTestCase
{
    private EnvFileValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new EnvFileValidator();
    }

    /**
     * The exact line that caused the outage.
     */
    public function testUnquotedValueWithASpaceIsFatal(): void
    {
        $problems = $this->validator->validate($this->withRequired("META_APP_SECRET = <new secret>\n"));
        $secret   = $this->only($problems, 'META_APP_SECRET');

        $this->assertCount(1, $secret, 'the offending line should raise exactly one problem');
        $this->assertSame(EnvFileValidator::FATAL, $secret[0]['severity']);
        $this->assertFalse($this->validator->bootable($problems), 'app must be reported as unbootable');
    }

    public function testQuotingTheSameValueMakesItBootable(): void
    {
        $problems = $this->validator->validate($this->withRequired("META_APP_SECRET = \"two words\"\n"));

        $this->assertSame([], $this->only($problems, 'META_APP_SECRET'));
        $this->assertTrue($this->validator->bootable($problems));
    }

    public function testARealSecretPassesCleanly(): void
    {
        $problems = $this->validator->validate($this->withRequired("META_APP_SECRET = aff71205c65dd3ce1296706506bcae06\n"));

        $this->assertSame([], $problems);
    }

    /**
     * DotEnv truncates an unquoted value at the first ' #', so a trailing
     * comment is legal and must not be mistaken for a space in the value.
     */
    public function testTrailingCommentAfterAnUnquotedValueIsAllowed(): void
    {
        $problems = $this->validator->validate($this->withRequired("QUEUE_DRIVER = database # the only supported driver\n"));

        $this->assertSame([], $this->only($problems, 'QUEUE_DRIVER'));
    }

    public function testFullLineCommentsAndBlankLinesAreIgnored(): void
    {
        $contents = $this->withRequired("# META_APP_SECRET = <not set yet>\n\n   # indented comment with spaces\n");

        $this->assertSame([], $this->validator->validate($contents));
    }

    /**
     * Boots fine, but is certainly not what the operator meant.
     */
    public function testPlaceholderValueWarnsWithoutBlockingBoot(): void
    {
        $problems = $this->validator->validate($this->withRequired("META_APP_SECRET = PASTE_NEW_SECRET_HERE\n"));
        $secret   = $this->only($problems, 'META_APP_SECRET');

        $this->assertCount(1, $secret);
        $this->assertSame(EnvFileValidator::WARN, $secret[0]['severity']);
        $this->assertTrue($this->validator->bootable($problems));
    }

    public function testUnterminatedQuoteWarns(): void
    {
        $problems = $this->validator->validate($this->withRequired("APP_NAME = \"TravelPilot\n"));
        $name     = $this->only($problems, 'APP_NAME');

        $this->assertCount(1, $name);
        $this->assertSame(EnvFileValidator::WARN, $name[0]['severity']);
    }

    public function testMissingRequiredKeyIsFatal(): void
    {
        $problems = $this->validator->validate("app.baseURL = https://travelpilot.gamavis.com\n");
        $key      = $this->only($problems, 'encryption.key');

        $this->assertCount(1, $key);
        $this->assertSame(EnvFileValidator::FATAL, $key[0]['severity']);
        $this->assertSame(0, $key[0]['line'], 'a missing key has no line number');
    }

    public function testEmptyRequiredKeyIsFatal(): void
    {
        $problems = $this->validator->validate($this->withRequired("encryption.key =\n", false));

        $this->assertCount(1, $this->only($problems, 'encryption.key'));
    }

    /**
     * A value that legitimately contains '=' (base64 padding on the encryption
     * key, for instance) must survive: DotEnv splits on the FIRST '=' only.
     */
    public function testValueContainingAnEqualsSignIsFine(): void
    {
        $problems = $this->validator->validate($this->withRequired("SOME_TOKEN = abc123==\n"));

        $this->assertSame([], $this->only($problems, 'SOME_TOKEN'));
    }

    /** @param list<array{line:int,key:string,severity:string,message:string}> $problems */
    private function only(array $problems, string $key): array
    {
        return array_values(array_filter($problems, static fn ($p) => $p['key'] === $key));
    }

    private function withRequired(string $extra, bool $includeKey = true): string
    {
        $base = "app.baseURL = https://travelpilot.gamavis.com\n"
            . ($includeKey ? "encryption.key = hex2bin:0123456789abcdef\n" : '')
            . "database.default.hostname = localhost\n"
            . "database.default.database = travelpilot_db\n"
            . "database.default.username = travelpilot\n";

        return $base . $extra;
    }
}
