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
        if (Schema::hasTable('users_phones')) {
            return;
        }

        Schema::create('users_phones', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->unique('userid');
            $table->string('number', 20)->index('number');
            $table->boolean('valid')->default(true);
            $table->timestamp('added')->useCurrent();
            $table->timestamp('lastsent')->nullable();
            $table->text('lastresponse')->nullable();
            $table->enum('laststatus', ['queued', 'failed', 'sent', 'delivered', 'undelivered'])->nullable();
            $table->timestamp('laststatusreceived')->nullable();
            $table->integer('count')->default(0);
            $table->timestamp('lastclicked')->nullable();

            $table->index(['laststatus', 'valid'], 'laststatus');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_phones');
    }
};
