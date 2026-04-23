<?php

namespace App\Services\Embedding;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Production embedder — shells out to resources/js/embed.mjs which uses
 * nomic-embed-text-v1.5 (ONNX) and truncates to 256 dims (Matryoshka).
 */
class NodeEmbedder implements EmbedderContract
{
    private const PROCESS_TIMEOUT = 600;

    public function embed(array $texts): array|false
    {
        if (empty($texts)) {
            return [];
        }

        $input = '';
        foreach ($texts as $id => $text) {
            $input .= json_encode(['msgid' => (string) $id, 'text' => $text])."\n";
        }

        $scriptPath = base_path('resources/js/embed.mjs');
        $process = new Process(['node', $scriptPath]);
        $process->setInput($input);
        $process->setTimeout(self::PROCESS_TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::error('Embedding script failed', ['stderr' => $process->getErrorOutput()]);

            return false;
        }

        $out = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if ($line === '') {
                continue;
            }
            $parsed = json_decode($line, true);
            if (! is_array($parsed) || ! isset($parsed['msgid'], $parsed['embedding'])) {
                continue;
            }
            $out[(string) $parsed['msgid']] = $parsed['embedding'];
        }

        return $out;
    }
}
