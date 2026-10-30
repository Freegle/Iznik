<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * domain_hosting: what hosting:scan-domains found out about a web domain, one row per domain. It is
 * keyed by the domain and holds nothing about any member: the Go API joins a member's preferred email
 * domain to it when it decides whether to show the Krystal hosting ad
 * (docs/developers/reference/ux-experiments.md, "A hosting ad for members with a WordPress site").
 *
 * wordpress: the site's home page carried a WordPress fingerprint. krystal: a nameserver or a response
 * header named Krystal, so the site is already there. nameserver is the first NS record, server the
 * Server header, status the HTTP status of the home page or null when nothing answered. checked_at is
 * when it was last looked at; a row is looked at again after RECHECK_DAYS.
 *
 * A new, empty table. Nothing alters an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('domain_hosting')) {
            Schema::create('domain_hosting', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('domain', 191)->unique('domain_hosting_domain');
                $t->boolean('wordpress')->default(false);
                $t->boolean('krystal')->default(false);
                $t->string('nameserver', 191)->nullable();
                $t->string('server', 64)->nullable();
                $t->unsignedSmallInteger('status')->nullable();
                $t->timestamp('checked_at')->useCurrent();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['checked_at'], 'domain_hosting_checked');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_hosting');
    }
};
