<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_items', static function (Blueprint $table) {
            $table->comment('Позиции заказов');
            $table->id()->comment('Идентификатор позиции');
            $table->string('order_id', 64)->comment('Идентификатор заказа MarketHub');
            $table->smallInteger('position')->comment('Позиция в заказе (с 1)');
            $table->string('sku', 64)->comment('Артикул товара');
            $table->string('name', 255)->comment('Название товара');
            $table->unsignedInteger('quantity')->comment('Количество');
            $table->decimal('price', 12, 2)->comment('Цена за единицу');

            $table->unique(['order_id', 'position']);
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
