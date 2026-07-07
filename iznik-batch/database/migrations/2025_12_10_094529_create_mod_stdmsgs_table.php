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
        if (Schema::hasTable('mod_stdmsgs')) {
            return;
        }

        Schema::create('mod_stdmsgs', function (Blueprint $table) {
            $table->bigIncrements('id')->unique('id')->comment('Unique ID of standard message');
            $table->unsignedBigInteger('configid')->nullable()->index('configid');
            $table->string('title')->comment('Title of standard message');
            $table->enum('action', ['Approve', 'Reject', 'Leave', 'Approve Member', 'Reject Member', 'Leave Member', 'Leave Approved Message', 'Delete Approved Message', 'Leave Approved Member', 'Delete Approved Member', 'Edit', 'Hold Message'])->default('Reject')->comment('What action to take');
            $table->string('subjpref')->comment('Subject prefix');
            $table->string('subjsuff')->comment('Subject suffix');
            $table->mediumText('body');
            $table->boolean('rarelyused')->default(false)->comment('Rarely used messages may be hidden in the UI');
            $table->boolean('autosend')->default(false)->comment('Send the message immediately rather than wait for user');
            $table->enum('newmodstatus', ['UNCHANGED', 'MODERATED', 'DEFAULT', 'PROHIBITED', 'UNMODERATED'])->default('UNCHANGED')->comment('Yahoo mod status afterwards');
            $table->enum('newdelstatus', ['UNCHANGED', 'DIGEST', 'NONE', 'SINGLE', 'ANNOUNCEMENT'])->default('UNCHANGED')->comment('Yahoo delivery status afterwards');
            $table->enum('edittext', ['Unchanged', 'Correct Case'])->default('Unchanged');
            $table->enum('insert', ['Top', 'Bottom'])->nullable()->default('Top');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mod_stdmsgs');
    }
};
