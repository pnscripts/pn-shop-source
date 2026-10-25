<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pnshop_n18_documents', function (Blueprint $table) {
            $table->id();
            // "sale": чл. 52о document; "cod": accompanying document for a postal money order.
            $table->string('type', 10);
            // The 10-digit sale document number (sale documents only); unique in the business.
            $table->unsignedBigInteger('number')->nullable()->unique();
            // Issued documents are a fiscal record: an order with one cannot be deleted.
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->unsignedTinyInteger('payment_code');
            $table->string('transaction_ref')->nullable();
            $table->bigInteger('total');
            $table->string('currency', 3);
            // Everything printed, frozen when issued: seller, lines, totals, QR text.
            $table->json('data');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'type']);
            $table->index('issued_at');
        });

        Schema::create('pnshop_n18_counters', function (Blueprint $table) {
            $table->string('name', 40)->primary();
            $table->unsignedBigInteger('last');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pnshop_n18_counters');
        Schema::dropIfExists('pnshop_n18_documents');
    }
};
