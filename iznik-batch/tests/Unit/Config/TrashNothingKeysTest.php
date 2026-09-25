<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * The two TrashNothing APIs take two different keys (config/freegle.php,
 * 'trashnothing'). These pin the wiring of public_api_key: its own variable when
 * set, the partner key otherwise, so a single-key development environment keeps
 * working while production can hold both.
 */
class TrashNothingKeysTest extends TestCase
{
    private const VARS = ['FREEGLE_TN_API_KEY', 'FREEGLE_TN_PUBLIC_API_KEY'];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::VARS as $var) {
            $this->saved[$var] = getenv($var);
            $this->clearVar($var);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            $this->clearVar($var);
            if ($this->saved[$var] !== false) {
                $this->setVar($var, $this->saved[$var]);
            }
        }
        parent::tearDown();
    }

    private function setVar(string $var, string $value): void
    {
        putenv("{$var}={$value}");
        $_ENV[$var] = $value;
        $_SERVER[$var] = $value;
    }

    private function clearVar(string $var): void
    {
        putenv($var);
        unset($_ENV[$var], $_SERVER[$var]);
    }

    /** @return array<string, mixed> */
    private function trashnothingConfig(): array
    {
        $config = require base_path('config/freegle.php');

        return $config['trashnothing'];
    }

    public function test_public_api_key_falls_back_to_the_partner_key_when_unset(): void
    {
        $this->setVar('FREEGLE_TN_API_KEY', 'partner-key');

        $tn = $this->trashnothingConfig();

        $this->assertSame('partner-key', $tn['api_key']);
        $this->assertSame('partner-key', $tn['public_api_key']);
    }

    public function test_public_api_key_is_its_own_variable_when_set(): void
    {
        $this->setVar('FREEGLE_TN_API_KEY', 'partner-key');
        $this->setVar('FREEGLE_TN_PUBLIC_API_KEY', 'developer-key');

        $tn = $this->trashnothingConfig();

        $this->assertSame('partner-key', $tn['api_key'], 'the partner key must not be replaced');
        $this->assertSame('developer-key', $tn['public_api_key']);
    }

    public function test_both_keys_default_to_empty(): void
    {
        $tn = $this->trashnothingConfig();

        $this->assertSame('', $tn['api_key']);
        $this->assertSame('', $tn['public_api_key']);
    }
}
