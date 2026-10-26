<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('webhook_events', static function (Blueprint $table) {
            $table->comment('Журнал обработки событий вебхуков MarketHub');
            $table->string('id', 64)->primary()->comment('Идентификатор события (= event.id)');
            $table->string('type', 32)->comment('Тип события (order.created, …)');
            $table->string('order_id', 64)->nullable()->comment('Идентификатор заказа из снимка (без внешнего ключа: событие вставляется до создания заказа)');
            $table->integer('signature_timestamp')->comment('Unix time из заголовка X-Webhook-Timestamp');
            $table->mediumText('payload')->comment('Сырое тело события (UTF-8, байт в байт)');
            $table->char('payload_sha256', 64)->comment('SHA-256 хеш тела для диагностики повторов');
            $table->string('outcome', 16)->comment('Исход обработки (applied|stale|ignored|rejected)');
            $table->string('reject_reason', 32)->nullable()->comment('Причина отказа (например, terminal_status)');
            $table->integer('deliveries')->default(1)->comment('Количество доставок события');
            $table->dateTime('received_at')->comment('Время первой приёмки');
            $table->dateTime('last_received_at')->nullable()->comment('Время последней повторной доставки');
            $table->dateTime('processed_at')->nullable()->comment('Время завершения обработки');

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
