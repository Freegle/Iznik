<?php

namespace App\Services\Embedding;

interface EmbedderContract
{
    /**
     * Embed a set of texts keyed by caller-defined ids.
     *
     * @param  array<string,string>  $texts  id => text to embed
     * @return array<string,array<float>>|false  id => 256-dim normalised vector,
     *                                           or false on failure
     */
    public function embed(array $texts): array|false;
}
