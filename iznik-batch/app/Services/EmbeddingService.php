<?php

namespace App\Services;

use App\Services\Embedding\EmbedderContract;
use App\Services\Embedding\NodeEmbedder;
use App\Support\SubjectParser;
use Illuminate\Support\Facades\DB;

class EmbeddingService
{
    public const MODEL_VERSION = 'nomic-embed-text-v1.5-dim256';
    public const EMBEDDING_DIM = 256;

    public function __construct(
        private ?EmbedderContract $embedder = null,
    ) {
        $this->embedder ??= new NodeEmbedder;
    }

    /**
     * Preprocess a raw subject for embedding — return just the item part.
     *
     * Freegle subjects are canonically "TYPE: item (location)". The item is
     * what we want to embed; the type is noise (it's encoded in the msgtype
     * column, filtered separately) and the location pollutes similarity
     * (two sofas in the same town would cluster tighter than a sofa and a
     * sofa-bed across the country — the opposite of useful).
     *
     * Uses the same bracket-counting parser as IncomingMailService so the
     * embedding pipeline agrees with how moderation / mail handling carve
     * subjects up. Falls back to the raw trimmed subject when the input
     * isn't in canonical shape.
     */
    public function preprocessSubject(string $raw): string
    {
        [, $item, ] = SubjectParser::parse($raw);

        if ($item !== null && $item !== '') {
            return $item;
        }

        // Non-canonical subject: strip a leading type keyword if obvious,
        // otherwise embed whatever's there. No regex-based postcode or
        // location heuristics — we only trust the structured parse.
        $s = preg_replace('/^\s*(OFFER|WANTED|Offered|Requested|TAKEN|RECEIVED):\s*/i', '', $raw);

        return trim($s);
    }

    /**
     * Embed subjects and bodies for a chunk of messages and upsert into
     * messages_embeddings. Returns count written, or false on embedder failure.
     *
     * @param  iterable<object{msgid:int,subject:string,body:string}>  $messages
     */
    public function processMessages(iterable $messages): int|false
    {
        $texts = [];
        $hasBody = [];
        $msgids = [];

        foreach ($messages as $msg) {
            $id = (int) $msg->msgid;
            $msgids[] = $id;
            $texts["s:$id"] = $this->preprocessSubject((string) ($msg->subject ?? ''));
            $body = trim((string) ($msg->body ?? ''));
            if ($body !== '') {
                $texts["b:$id"] = $body;
                $hasBody[$id] = true;
            }
        }

        if (empty($msgids)) {
            return 0;
        }

        $vectors = $this->embedder->embed($texts);
        if ($vectors === false) {
            return false;
        }

        $count = 0;
        foreach ($msgids as $id) {
            if (! isset($vectors["s:$id"])) {
                continue;
            }
            $subjectBlob = $this->packVector($vectors["s:$id"]);
            $bodyBlob = isset($hasBody[$id], $vectors["b:$id"])
                ? $this->packVector($vectors["b:$id"])
                : null;

            // upsert() with the update columns given as a plain LIST renders
            // exactly "col = values(col)" for each - which is what this
            // statement wrote by hand. Passing them as a key => value map
            // instead would emit "col = ?" and bind, quietly changing an
            // update-from-the-incoming-row into an update-to-a-fixed-value.
            DB::table('messages_embeddings')->upsert(
                [[
                    'msgid' => $id,
                    'subject_embedding' => $subjectBlob,
                    'body_embedding' => $bodyBlob,
                    'model_version' => self::MODEL_VERSION,
                ]],
                ['msgid'],
                ['subject_embedding', 'body_embedding', 'model_version']
            );
            $count++;
        }

        return $count;
    }

    /**
     * Embed saved search terms and upsert into users_searches_embeddings.
     *
     * Terms are embedded exactly as post subjects are - as DOCUMENTS - so the
     * resulting cosines sit on the same scale as
     * messages_embeddings.subject_embedding and the matched-posts threshold
     * (0.85) means the same thing against both. A term is already the same kind
     * of text as a preprocessed subject: "pine bookcase", not "OFFER: Pine
     * bookcase (Tuvalu High Street)".
     *
     * Returns count written, or false on embedder failure.
     *
     * @param  iterable<object{id:int,term:string}>  $searches
     */
    public function processSearches(iterable $searches): int|false
    {
        $texts = [];

        foreach ($searches as $search) {
            $term = trim((string) ($search->term ?? ''));
            if ($term === '') {
                continue;
            }

            // preprocessSubject anyway: some saved searches are pasted straight
            // from a post subject, complete with "WANTED:" and a location.
            $texts[(string) (int) $search->id] = $this->preprocessSubject($term);
        }

        if (empty($texts)) {
            return 0;
        }

        $vectors = $this->embedder->embed($texts);
        if ($vectors === false) {
            return false;
        }

        $count = 0;
        foreach ($texts as $id => $_) {
            if (! isset($vectors[$id])) {
                continue;
            }

            // Same upsert() shape as processMessages() above: the update columns are
            // given as a plain LIST so each renders "col = values(col)" rather than
            // "col = ?" bound to a fixed value.
            DB::table('users_searches_embeddings')->upsert(
                [[
                    'searchid' => (int) $id,
                    'term_embedding' => $this->packVector($vectors[$id]),
                    'model_version' => self::MODEL_VERSION,
                ]],
                ['searchid'],
                ['term_embedding', 'model_version']
            );
            $count++;
        }

        return $count;
    }

    private function packVector(array $floats): string
    {
        if (count($floats) !== self::EMBEDDING_DIM) {
            throw new \RuntimeException(sprintf(
                'Expected %d-dim vector, got %d',
                self::EMBEDDING_DIM,
                count($floats)
            ));
        }

        return pack('g*', ...$floats);
    }
}
