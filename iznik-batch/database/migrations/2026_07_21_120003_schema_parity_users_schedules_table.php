<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create users_schedules, which exists on production (77,968 rows) but had no
     * migration at all.
     *
     * This is the most consequential piece of the drift: chat_messages.scheduleid
     * carries a real foreign key to this table on live (chat_messages_ibfk_2,
     * ON DELETE CASCADE), so a migrated database was missing both the table and
     * the constraint. Anything reasoning about chat_messages indexes from the
     * migrations alone would wrongly conclude scheduleid has no FK and its index
     * is therefore droppable - it is not.
     *
     * Definition taken verbatim from production SHOW CREATE TABLE.
     */
    public function up(): void
    {
        if (Schema::hasTable('users_schedules')) {
            return;
        }

        Schema::create('users_schedules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->unique('userid');
            $table->timestamp('created')->useCurrent();
            $table->text('schedule')->nullable();
            // Named to match production exactly, so a hand-written
            // DROP FOREIGN KEY works the same in both places.
            $table->foreign(['userid'], 'users_schedules_ibfk_1')->references(['id'])->on('users')
                ->onUpdate('restrict')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users_schedules');
    }
};
