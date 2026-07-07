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
        if (Schema::hasTable('microactions')) {
            return;
        }

        Schema::create('microactions', function (Blueprint $table) {
            $table->comment('Micro-volunteering tasks');
            $table->bigIncrements('id');
            $table->enum('actiontype', ['CheckMessage', 'SearchTerm', 'Items', 'FacebookShare', 'PhotoRotate', 'ItemSize', 'ItemWeight', 'Survey', 'Survey2', 'Invite'])->nullable();
            $table->unsignedBigInteger('userid')->index('userid');
            $table->unsignedBigInteger('msgid')->nullable()->index('msgid');
            $table->enum('msgcategory', ['CouldBeBetter', 'ShouldntBeHere', 'NotSure'])->nullable();
            $table->enum('result', ['Approve', 'Reject']);
            $table->timestamp('timestamp')->useCurrent()->index('timestamp');
            $table->text('comments')->nullable();
            $table->unsignedBigInteger('searchterm1')->nullable()->index('searchterm1');
            $table->unsignedBigInteger('searchterm2')->nullable()->index('searchterm2');
            $table->integer('version')->default(1)->comment('For when we make changes which affect the validity of the data');
            $table->unsignedBigInteger('item1')->nullable()->index('item1');
            $table->unsignedBigInteger('item2')->nullable()->index('item2');
            $table->unsignedBigInteger('facebook_post')->nullable()->index('facebook_post');
            $table->unsignedBigInteger('rotatedimage')->nullable()->index('rotatedimage');
            $table->decimal('score_positive', 10, 4)->default(0);
            $table->decimal('score_negative', 10, 4);
            $table->text('modfeedback')->nullable();

            $table->unique(['userid', 'msgid'], 'userid_2');
            $table->unique(['userid', 'searchterm1', 'searchterm2'], 'userid_3');
            $table->unique(['userid', 'item1', 'item2'], 'userid_4');
            $table->unique(['userid', 'facebook_post'], 'userid_5');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('microactions');
    }
};
