<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_status_history', static function (Blueprint $table) {
            $table->comment('История статусов заказов');
            $table->id()->comment('Идентификатор записи истории');
            $table->string('order_id', 64)->comment('Идентификатор заказа MarketHub');
            $table->string('status', 16)->comment('Статус, в котором находился заказ');
            $table->dateTime('at')->comment('Момент перехода по данным MarketHub');
            $table->string('source_event_id', 64)->nullable()->comment('id события, из которого следует факт');

            $table->unique(['order_id', 'status', 'at']);
            $table->index(['order_id', 'at', 'id']);
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
