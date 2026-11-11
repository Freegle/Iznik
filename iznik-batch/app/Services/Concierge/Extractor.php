<?php

namespace App\Services\Concierge;


/**
 * Turns a raw inbound reply into the structured signal the ConciergeEngine
 * consumes: which catalogue items the sender wants, whether they're declining,
 * and any collection dates. This is the second "AI slot" — the LlmExtractor is
 * the real one; the RuleBasedExtractor is a deterministic fallback and the thing
 * the replay test pins, so the pipeline is testable without a network call.
 *
 * @phpstan-type Extraction array{wants:int[],declined:bool,collectDays:string[]}
 */
interface Extractor
{
    /**
     * @param array<int,array{num:int,name:string}> $items catalogue keyed by number
     * @param array{defaultMonth?:string} $opts  e.g. defaultMonth '2026-07' for bare ordinals
     * @return array{wants:int[],declined:bool,collectDays:string[]}
     */
    public function extract(string $body, string $subject, array $items, array $opts = []): array;
}
