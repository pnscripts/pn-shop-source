<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer accounts per channel (an option): a channel with separate accounts has its own
 * customers, so the same email address can have one account there and one shared by the
 * other channels. account_scope is 0 for shared accounts, or the channel's id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('separate_accounts')->default(false)->after('is_active');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('account_scope')->default(0)->after('email');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->unique(['email', 'account_scope'], 'users_email_account_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_account_scope_unique');
            $table->unique('email', 'users_email_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('account_scope');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('separate_accounts');
        });
    }
};
