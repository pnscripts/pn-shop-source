<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Channels: several storefronts on one installation, each on its own domain or path.
 *
 * The existing store becomes the default channel, which answers on every host, and its
 * orders, carts and customers are recorded as belonging to it: a single-store shop works
 * exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('hostname')->nullable();
            $table->string('path', 64)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('default_locale', 12)->nullable();
            $table->json('locales')->nullable();
            $table->char('currency', 3)->nullable();
            $table->json('settings')->nullable();
            $table->json('stock_location_ids')->nullable();
            $table->json('payment_method_ids')->nullable();
            $table->json('shipping_method_ids')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'hostname']);
        });

        $now = now();
        $id = DB::table('channels')->insertGetId([
            'code' => 'default',
            'name' => 'Main store',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (['orders', 'carts', 'users'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            });

            DB::table($table)->update(['channel_id' => $id]);
        }

        // A customer has one cart per channel. The new index comes first: MySQL needs an
        // index on carts.user_id for its foreign key.
        Schema::table('carts', function (Blueprint $table) {
            $table->unique(['user_id', 'channel_id'], 'carts_user_channel_unique');
        });
        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique('carts_user_id_unique');
        });
    }

    public function down(): void
    {
        // One cart per customer again: keep each customer's newest.
        $keep = DB::table('carts')->whereNotNull('user_id')->selectRaw('max(id) as id')->groupBy('user_id')->pluck('id');
        DB::table('carts')->whereNotNull('user_id')->whereNotIn('id', $keep)->delete();

        Schema::table('carts', function (Blueprint $table) {
            $table->unique('user_id', 'carts_user_id_unique');
        });
        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique('carts_user_channel_unique');
        });

        foreach (['orders', 'carts', 'users'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('channel_id');
            });
        }

        Schema::dropIfExists('channels');
    }
};
