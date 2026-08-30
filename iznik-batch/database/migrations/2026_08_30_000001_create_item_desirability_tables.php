<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item desirability artifact + per-message scores.
 *
 * item_desirability is a model artifact table: rebuilt wholesale by
 * `desirability:import-artifact` from the offline analysis (historical reply
 * lifts pooled with the modern cohort, keyed by canonical title). It is not
 * written on any request path.
 *
 * messages_desirability records the score assigned to each new approved OFFER
 * by `desirability:score-new`, keyed (msgid, model_version) like messages_eee
 * so a model refresh can rescore without colliding with old rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('item_desirability')) {
            Schema::create('item_desirability', function (Blueprint $table) {
                $table->id();
                // Canonical item key as produced by TitleCanonicalService. Artifact
                // builder guarantees <= 191 chars so the unique index fits utf8mb4.
                $table->string('canonical', 191);
                // Representative title of the embedding cluster this key belongs to,
                // null when the key was not merged into any cluster.
                $table->string('cluster_rep', 191)->nullable();
                // Demand lift for replies: observed / expected under the confounder
                // model, gamma-Poisson shrunk. 1.0 = average item.
                $table->decimal('lift_replies', 8, 4);
                // Evidence behind the lift (pooled expected replies). Higher = better
                // measured; used for posterior-based bucketing and kNN weighting.
                $table->decimal('evidence', 10, 2);
                // View-side lift and taken rate are descriptive companions - views
                // measure attention-while-open, NOT desirability (fast-taken items
                // accumulate FEWER views), so never rank by lift_views alone.
                $table->decimal('lift_views', 8, 4)->nullable();
                $table->decimal('taken_rate', 5, 4)->nullable();
                $table->integer('n_posts')->default(0);
                // Bucket comes from the gamma POSTERIOR, not the point estimate:
                // high/low only when the posterior clears the boundary with the
                // configured confidence, else medium. A near-boundary or thin-evidence
                // item is medium by construction - no knife-edge flips.
                $table->enum('bucket', ['low', 'medium', 'high'])->default('medium');
                // 256 x little-endian float32 title embedding (same recipe as the
                // embedding sidecar, query-space), present only for reference rows
                // used by the cold-start kNN. Null for the long tail.
                $table->binary('embedding')->nullable();
                $table->string('model_version', 50);
                $table->timestamp('built_at')->useCurrent();

                $table->unique(['canonical', 'model_version']);
                $table->index(['model_version', 'bucket']);
            });
        }

        if (! Schema::hasTable('messages_desirability')) {
            Schema::create('messages_desirability', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('msgid');
                // Predicted demand lift for this post's item (1.0 = average).
                $table->decimal('score', 8, 4);
                $table->enum('bucket', ['low', 'medium', 'high']);
                // How the score was obtained: exact canonical match, cluster member,
                // embedding kNN over reference titles, or default (no information -
                // score 1.0, medium). kNN and default rows are lower-trust.
                $table->enum('source', ['exact', 'knn', 'default']);
                // The canonical key the score came from (the matched reference title
                // for knn), for auditability.
                $table->string('matched_canonical', 191)->nullable();
                $table->string('model_version', 50);
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['msgid', 'model_version']);
                $table->index('created_at');
                $table->foreign('msgid')->references('id')->on('messages')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_desirability');
        Schema::dropIfExists('item_desirability');
    }
};
