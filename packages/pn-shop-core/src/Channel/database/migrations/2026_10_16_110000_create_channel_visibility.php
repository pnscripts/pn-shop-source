<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products, categories and pages limited to channels. No rows: visible in every channel,
 * so nothing changes for existing shops.
 */
return new class extends Migration
{
    /** @var array<string, string> pivot table => the other model's table */
    private const PIVOTS = [
        'channel_product' => 'products',
        'category_channel' => 'product_categories',
        'channel_page' => 'pages',
    ];

    public function up(): void
    {
        foreach (self::PIVOTS as $pivot => $table) {
            $column = $table === 'product_categories' ? 'category_id' : rtrim($table, 's').'_id';

            Schema::create($pivot, function (Blueprint $blueprint) use ($table, $column) {
                $blueprint->foreignId('channel_id')->constrained()->cascadeOnDelete();
                $blueprint->foreignId($column)->constrained($table)->cascadeOnDelete();
                $blueprint->primary(['channel_id', $column]);
                $blueprint->index($column);
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PIVOTS) as $pivot) {
            Schema::dropIfExists($pivot);
        }
    }
};
