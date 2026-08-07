<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users_searches_embeddings - a saved search term as a vector.
 *
 * Saved searches were matched against posts with SQL LIKE on keywords, which has
 * no notion of how well anything matched: "bed lever" matched "bed", and there
 * was no score with which to say it was a poor match. That is tolerable on a
 * browsing surface somebody chose to look at. It is not tolerable for deciding
 * whether to put an email in somebody's inbox unasked.
 *
 * Storing the term as an embedding lets the search signal be held to exactly the
 * same bar as the matched-posts email (MinMatchedPostScore, 0.85), rather than
 * needing a threshold of its own.
 *
 * **The term is embedded as a DOCUMENT, not as a query, and that is the point.**
 * A saved search term ("pine bookcase") is the same kind of text as a post
 * subject once EmbeddingService::preprocessSubject has stripped "OFFER:" and the
 * trailing location. Embedding it the same way puts it on the same
 * document-vs-document cosine scale as messages_embeddings.subject_embedding, so
 * 0.85 means here what it means there. Embedding it as a query instead would put
 * it on the search scale, where the equivalent bar is a different number
 * entirely - and picking one by eye is exactly the mistake that left the
 * matched-posts email at 0.60 with a precision of 0.49.
 *
 * Mirrors messages_embeddings: BLOB rather than a VECTOR column because Percona
 * 8.0 has no vector type, and model_version so a model change can be found and
 * re-embedded rather than silently mixing scales.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users_searches_embeddings')) {
            return;
        }

        Schema::create('users_searches_embeddings', function (Blueprint $t) {
            $t->unsignedBigInteger('searchid')->primary();
            $t->binary('term_embedding');
            $t->string('model_version', 50);
            $t->timestamp('created_at')->useCurrent();

            // Finding what still needs embedding, and what needs re-embedding
            // after a model change, are the only two queries this table serves
            // other than the by-id read.
            $t->index('model_version', 'users_searches_embeddings_model');

            $t->foreign('searchid')->references('id')->on('users_searches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users_searches_embeddings');
    }
};
