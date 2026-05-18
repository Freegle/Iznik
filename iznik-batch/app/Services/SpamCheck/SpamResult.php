<?php

namespace App\Services\SpamCheck;

/**
 * Value object representing the result of a spam check.
 */
class SpamResult
{
    public function __construct(
        public string $engine,
        public float $score,
        public array $symbols,
        public bool $isSpam,
        public ?string $error = null
    ) {}

    /**
     * Create a result from a SpamAssassin response.
     */
    public static function fromSpamAssassin(string $response): self
    {
        $score = 0.0;
        $symbols = [];
        $isSpam = false;
        $error = null;

        // Parse SpamAssassin response
        // Format: "Spam: True ; 15.5 / 5.0" or "Spam: False ; 2.1 / 5.0"
        if (preg_match('/Spam:\s*(True|False|Yes|No)\s*;\s*([\d.]+)\s*\/\s*([\d.]+)/i', $response, $matches)) {
            $isSpam = in_array(strtolower($matches[1]), ['true', 'yes']);
            $score = (float) $matches[2];
        }

        // Parse symbols from the response
        if (preg_match_all('/([A-Z][A-Z0-9_]+)/m', $response, $symbolMatches)) {
            $symbols = array_filter($symbolMatches[1], function($s) {
                return strlen($s) > 2 && !in_array($s, ['Spam', 'True', 'False', 'Yes', 'No']);
            });
        }

        return new self('SpamAssassin', $score, array_values($symbols), $isSpam, $error);
    }

    /**
     * Create a result from an Rspamd JSON response.
     */
    public static function fromRspamd(array $response): self
    {
        $score = (float) ($response['score'] ?? 0.0);
        $isSpam = ($response['action'] ?? 'no action') === 'reject';
        $symbols = [];
        $error = null;

        // Extract symbol names
        if (isset($response['symbols']) && is_array($response['symbols'])) {
            foreach ($response['symbols'] as $name => $data) {
                $symbolScore = (float) ($data['score'] ?? 0.0);
                $symbols[] = sprintf('%s(%.1f)', $name, $symbolScore);
            }
        }

        return new self('Rspamd', $score, $symbols, $isSpam, $error);
    }

    /**
     * Create an error result.
     */
    public static function error(string $engine, string $message): self
    {
        return new self($engine, 0.0, [], false, $message);
    }

    /**
     * Format the result as a header value.
     */
    public function toHeaderValue(): string
    {
        if ($this->error) {
            return "Error: {$this->error}";
        }

        return sprintf('%.1f', $this->score);
    }

    /**
     * Format symbols as a header value.
     */
    public function symbolsToHeaderValue(): string
    {
        if ($this->error) {
            return 'Error';
        }

        return implode(',', array_slice($this->symbols, 0, 10));
    }
}
