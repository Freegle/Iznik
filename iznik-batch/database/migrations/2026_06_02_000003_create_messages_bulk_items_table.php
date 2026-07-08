<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `messages_bulk_items` — the structured catalogue for a "bulk offer" / clearance
 * listing. One Freegle message (e.g. "Office Clearance") can carry many items,
 * each with its own name, quantity, condition, dimensions and photos.
 *
 * A message is a bulk offer simply by having rows here; the message itself stays
 * a normal Offer (no new message type). See plans/active/bulk-offer-clearance-design.md
 * and the Freegle Helper concierge spec which consumes this data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages_bulk_items')) {
            return;
        }

        Schema::create('messages_bulk_items', function (Blueprint $table) {
            // Pin the collation to the schema-wide utf8mb4_unicode_ci rather than the
            // MySQL 8 server default (utf8mb4_0900_ai_ci). items.name (and the rest of
            // the legacy iznik schema) is utf8mb4_unicode_ci, and stats generation joins
            // items i ON i.name = bi.name — a mismatch throws "Illegal mix of collations".
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->comment('Structured catalogue items for a bulk offer (clearance) message.');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid');
            $table->unsignedInteger('position')->default(0)->comment('Display order within the post');
            $table->string('name', 255);
            $table->unsignedInteger('quantity')->default(1)->comment('How many of this item are offered');
            $table->enum('condition', ['New', 'LikeNew', 'Good', 'Used', 'Poor', 'Unknown'])->default('Unknown');
            $table->string('dimensions', 255)->nullable()->comment('Free-text measurements, e.g. 120x80x74cm');
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('msgid');
            $table->index(['msgid', 'position']);

            $table->foreign('msgid')->references('id')->on('messages')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_bulk_items');
    }
};
