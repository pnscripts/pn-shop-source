<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gift cards, customers' store credit, and the ledger of every change to their balances.
 * Gift card codes are stored only as a hash (and their last four characters).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->char('last4', 4);
            $table->char('currency', 3);
            $table->bigInteger('initial_amount');
            $table->bigInteger('balance');
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('recipient_email')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('balance')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'currency']);
        });

        Schema::create('balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->morphs('account');
            $table->char('currency', 3);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('reason', 32);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_transactions');
        Schema::dropIfExists('credit_accounts');
        Schema::dropIfExists('gift_cards');
    }
};
