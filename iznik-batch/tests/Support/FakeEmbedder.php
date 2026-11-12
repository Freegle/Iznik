<?php

namespace Tests\Support;

use App\Services\Embedding\EmbedderContract;

/**
 * Deterministic id-keyed fake embedder.
 */
class FakeEmbedder implements EmbedderContract
{
    public array $textsSeen = []; // id => text

    public function __construct(
        private float $seed = 0.0,
        private bool $fail = false,
    ) {}

    public function embed(array $texts): array|false
    {
        if ($this->fail) {
            return false;
        }

        $out = [];
        foreach ($texts as $id => $text) {
            $this->textsSeen[$id] = $text;
            $out[$id] = $this->vectorFor($text);
        }

        return $out;
    }

    private function vectorFor(string $text): array
    {
        $h = hash('sha256', $text.'|'.$this->seed, true);
        $v = [];
        for ($i = 0; $i < 256; $i++) {
            $byte = ord($h[$i % 32]) + (($i >> 5) * 13);
            $v[] = (($byte % 256) / 255.0) - 0.5;
        }
        $norm = 0.0;
        foreach ($v as $x) {
            $norm += $x * $x;
        }
        $norm = sqrt($norm) ?: 1.0;
        foreach ($v as $i => $x) {
            $v[$i] = $x / $norm;
        }

        return $v;
    }
}
