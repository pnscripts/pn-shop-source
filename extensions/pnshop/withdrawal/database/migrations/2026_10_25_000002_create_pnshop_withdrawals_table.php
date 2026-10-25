<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pnshop_withdrawals', function (Blueprint $table) {
            $table->id();
            // Quoted to the customer and staff, e.g. W-7KQ2M9XD.
            $table->string('reference', 20)->unique();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            // As the customer typed them: the statement is accepted even if they match no order.
            $table->string('order_number', 64);
            $table->string('name');
            $table->string('email');
            $table->text('items')->nullable();
            $table->string('locale', 12)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('handled_at')->nullable();
            $table->text('staff_note')->nullable();
            $table->timestamps();
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pnshop_withdrawals');
    }
};
