<?php

namespace Tests\Unit\CodeHealth;

use PhpToken;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * No parameter may be made nullable only by its default: `string $x = null`
 * must be written `?string $x = null`. PHP 8.4 deprecates the implicit form
 * when it compiles the file.
 *
 * That deprecation used to reach PHPUnit, which named the test that triggered
 * it. With opcache on for the CLI it no longer does: opcache detaches the user
 * error handler while it compiles, so a cold cache prints the deprecation and
 * a warm cache, where the file is not compiled at all, says nothing. This
 * check reads the source instead, so the answer is the same whatever the
 * cache holds. The opcache ini (docker/php-cli-opcache.ini) points here.
 */
class ImplicitNullableParametersTest extends TestCase
{
    private const DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'routes', 'tests'];

    public function test_no_parameter_is_implicitly_nullable(): void
    {
        $offenders = [];

        $files = Finder::create()
            ->in(array_map(fn ($d) => base_path($d), self::DIRECTORIES))
            ->name('*.php')
            ->files();

        foreach ($files as $file) {
            $source = $file->getContents();

            // Cheap pre-filter: a null default is the only way to offend.
            if (! preg_match('/=\s*null\b/i', $source)) {
                continue;
            }

            foreach ($this->implicitlyNullableParameters($source) as $line => $parameter) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.$line.'  '.$parameter;
            }
        }

        $this->assertSame([], $offenders, "Parameters that are nullable only by their default (write ?type, or type|null):\n  ".implode("\n  ", $offenders));
    }

    /**
     * Every parameter of every function, method, closure and arrow function in
     * the source whose type does not admit null but whose default is null.
     *
     * @return array<int, string> line => parameter text
     */
    private function implicitlyNullableParameters(string $source): array
    {
        $tokens = PhpToken::tokenize($source);
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            if (! $tokens[$i]->is([T_FUNCTION, T_FN])) {
                continue;
            }

            // The parameter list is the first parenthesised group after the keyword.
            while ($i < $count && ! $tokens[$i]->is('(')) {
                $i++;
            }

            $depth = 0;
            $parameter = '';
            $line = $tokens[$i]->line ?? 0;
            $parameters = [];

            for ($i++; $i < $count; $i++) {
                $token = $tokens[$i];

                if ($token->is(['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE])) {
                    $depth++;
                } elseif ($token->is([')', ']', '}'])) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                }

                if ($depth === 0 && $token->is(',')) {
                    $parameters[] = [$line, $parameter];
                    $parameter = '';
                    continue;
                }

                if ($parameter === '' && ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    $line = $token->line;
                }

                // Attributes decorate a parameter; they are not part of its type.
                if ($depth === 0 && ! $token->is([T_COMMENT, T_DOC_COMMENT])) {
                    $parameter .= $token->text;
                } elseif ($depth > 0 && ! $token->is(T_ATTRIBUTE) && ! $this->insideAttribute($tokens, $i)) {
                    $parameter .= $token->text;
                }
            }
            $parameters[] = [$line, $parameter];

            foreach ($parameters as [$at, $text]) {
                // Promoted constructor properties carry modifiers before the type.
                $text = preg_replace('/^(?:(?:public|protected|private|readonly)\s+)+/i', '', trim(preg_replace('/\s+/', ' ', $text)));

                if ($text === '' || ! preg_match('/^(.+?)\s*(?:&|\.\.\.)?\s*\$\w+\s*=\s*null$/i', $text, $m)) {
                    continue;
                }

                $type = trim($m[1]);

                if ($type === '' || str_starts_with($type, '?') || strtolower($type) === 'mixed' || preg_match('/\bnull\b/i', $type)) {
                    continue;
                }

                $found[$at] = $text;
            }
        }

        return $found;
    }

    /**
     * Whether token $i sits inside an attribute group (#[...]) that opened at
     * parameter-list depth, which is the only nesting a parameter's attribute
     * introduces.
     */
    private function insideAttribute(array $tokens, int $i): bool
    {
        $depth = 0;

        for ($j = $i - 1; $j >= 0; $j--) {
            if ($tokens[$j]->is(']')) {
                $depth++;
            } elseif ($tokens[$j]->is(T_ATTRIBUTE)) {
                if ($depth === 0) {
                    return true;
                }
                $depth--;
            } elseif ($tokens[$j]->is(['(', ','])) {
                return false;
            }
        }

        return false;
    }
}
