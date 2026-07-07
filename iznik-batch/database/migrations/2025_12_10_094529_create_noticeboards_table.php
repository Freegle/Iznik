<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('noticeboards')) {
            return;
        }

        Schema::create('noticeboards', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->decimal('lat', 10, 4);
            $table->decimal('lng', 10, 4);
            $table->geography('position', null, 3857);
            $table->timestamp('added')->useCurrent()->index('added');
            $table->unsignedBigInteger('addedby')->nullable()->index('addedby');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('lastcheckedat')->nullable()->index('lastcheckedat');
            $table->timestamp('thanked')->nullable()->index('thanked');

            $table->spatialIndex(['position'], 'position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noticeboards');
    }
};
