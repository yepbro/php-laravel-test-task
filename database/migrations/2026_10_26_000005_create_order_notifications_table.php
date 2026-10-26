<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_notifications', static function (Blueprint $table) {
            $table->comment('Transactional outbox писем покупателю');
            $table->id()->comment('Идентификатор уведомления');
            $table->string('order_id', 64)->comment('Идентификатор заказа MarketHub');
            $table->string('type', 16)->comment('Тип письма (paid|shipped|cancelled)');
            $table->string('dedupe_key', 191)->nullable()->comment('Ключ дедупликации отправки');
            $table->string('status', 16)->comment('Статус (pending|sending|sent|skipped|failed)');
            $table->string('skip_reason', 32)->nullable()->comment('Причина пропуска отправки');
            $table->string('recipient', 255)->nullable()->comment('Получатель письма');
            $table->string('subject', 255)->nullable()->comment('Тема письма');
            $table->unsignedInteger('attempts')->default(0)->comment('Число попыток отправки');
            $table->string('source_event_id', 64)->comment('id события-триггера');
            $table->text('last_error')->nullable()->comment('Последняя ошибка отправки');
            $table->dateTime('sent_at')->nullable()->comment('Время успешной отправки');
            $table->timestamps();

            $table->unique('dedupe_key');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notifications');
    }
};
