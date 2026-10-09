<?php

declare(strict_types=1);

namespace App\Services\Env;

/**
 * Validates a .env file the way CodeIgniter's DotEnv parser will read it,
 * WITHOUT booting the framework.
 *
 * This exists because a malformed .env is uniquely nasty in production: DotEnv
 * throws inside Boot::loadDotEnv(), which runs before the logger is available,
 * so every request dies as a bare HTTP 500 and nothing is written to any log.
 * A static frontend keeps serving, so the site looks alive but shows no data.
 *
 * The rules mirror CodeIgniter\Config\DotEnv::parse()/sanitizeValue():
 *   - lines whose first non-space character is '#' are comments
 *   - a line is an assignment if it contains '=' (split on the FIRST '=')
 *   - an unquoted value is truncated at the first ' #', then may not contain
 *     whitespace — DotEnv throws InvalidArgumentException if it does
 *
 * @see \CodeIgniter\Config\DotEnv
 */
final class EnvFileValidator
{
    public const FATAL = 'fatal';
    public const WARN  = 'warn';

    /**
     * Values that are obviously an unfilled placeholder rather than a secret.
     * Matched case-insensitively against the whole value.
     */
    private const PLACEHOLDER_PATTERNS = [
        '/^<.*>$/',
        '/^paste[_ -]/i',
        '/^your[_ -]/i',
        '/^change[_ -]?me/i',
        '/^(xxx+|yyy+|todo|tbd)$/i',
    ];

    /**
     * Keys that must be present and non-empty for the app to work in production.
     */
    private const REQUIRED = [
        'app.baseURL',
        'encryption.key',
        'database.default.hostname',
        'database.default.database',
        'database.default.username',
    ];

    /**
     * @return list<array{line:int,key:string,severity:string,message:string}>
     *         Empty when the file parses cleanly and nothing looks unfilled.
     */
    public function validate(string $contents): array
    {
        $problems = [];
        $seen     = [];

        foreach (explode("\n", $contents) as $i => $rawLine) {
            $lineNo = $i + 1;
            $line   = rtrim($rawLine, "\r");

            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $key            = str_replace(['\'', '"'], '', trim($name));
            $value          = trim($value);

            $seen[$key] = $value;

            if ($value === '') {
                continue;
            }

            if (strpbrk($value[0], '"\'') !== false) {
                $quote = $value[0];

                if (substr($value, -1) !== $quote || strlen($value) < 2) {
                    $problems[] = [
                        'line'     => $lineNo,
                        'key'      => $key,
                        'severity' => self::WARN,
                        'message'  => "opens with {$quote} but never closes it — the quote becomes part of the value",
                    ];
                }

                continue;
            }

            // Unquoted: DotEnv truncates at ' #', then rejects any whitespace.
            $bare = trim(explode(' #', $value, 2)[0]);

            if (preg_match('/\s/', $bare) === 1) {
                $problems[] = [
                    'line'     => $lineNo,
                    'key'      => $key,
                    'severity' => self::FATAL,
                    'message'  => 'unquoted value contains a space — DotEnv throws and the app will not boot at all',
                ];

                continue;
            }

            foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
                if (preg_match($pattern, $bare) === 1) {
                    $problems[] = [
                        'line'     => $lineNo,
                        'key'      => $key,
                        'severity' => self::WARN,
                        'message'  => 'looks like an unfilled placeholder, not a real value',
                    ];

                    break;
                }
            }
        }

        foreach (self::REQUIRED as $key) {
            if (! isset($seen[$key]) || trim($seen[$key], " \t\"'") === '') {
                $problems[] = [
                    'line'     => 0,
                    'key'      => $key,
                    'severity' => self::FATAL,
                    'message'  => 'required key is missing or empty',
                ];
            }
        }

        return $problems;
    }

    /**
     * True when nothing in $problems would stop the app from booting.
     *
     * @param list<array{line:int,key:string,severity:string,message:string}> $problems
     */
    public function bootable(array $problems): bool
    {
        foreach ($problems as $problem) {
            if ($problem['severity'] === self::FATAL) {
                return false;
            }
        }

        return true;
    }
}
