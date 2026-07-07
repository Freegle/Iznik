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
        if (Schema::hasTable('groups_facebook_toshare')) {
            return;
        }

        Schema::create('groups_facebook_toshare', function (Blueprint $table) {
            $table->comment('Stores central posts for sharing out to group pages');
            $table->bigIncrements('id');
            $table->string('sharefrom', 40)->comment('Page to share from');
            $table->string('postid', 80)->unique('postid')->comment('Facebook postid');
            $table->timestamp('date')->useCurrent()->index('date');
            $table->text('data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('groups_facebook_toshare');
    }
};
