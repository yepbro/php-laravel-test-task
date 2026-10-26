<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', static function (Blueprint $table) {
            $table->comment('Заказы MarketHub');
            $table->string('id', 64)->primary()->comment('Идентификатор заказа MarketHub (ord_…)');
            $table->string('number', 64)->comment('Номер заказа, который видит покупатель');
            $table->string('status', 16)->comment('Статус заказа (created|paid|shipped|delivered|cancelled)');
            $table->char('currency', 3)->comment('Код валюты ISO 4217');
            $table->string('customer_email', 255)->comment('Email покупателя как пришёл в снимке');
            $table->string('customer_email_normalized', 255)->comment('Нормализованный email (trim + lower) для фильтрации');
            $table->string('customer_name', 255)->comment('Имя покупателя');
            $table->string('customer_phone', 64)->nullable()->comment('Телефон покупателя');
            $table->decimal('delivery_price', 12, 2)->comment('Стоимость доставки');
            $table->decimal('discount', 12, 2)->comment('Скидка на заказ');
            $table->decimal('total', 12, 2)->comment('Итог к оплате');
            $table->string('shipment_carrier', 64)->nullable()->comment('Служба доставки');
            $table->string('shipment_tracking_number', 64)->nullable()->comment('Трек-номер отправления');
            $table->string('shipment_tracking_url', 512)->nullable()->comment('Ссылка на отслеживание');
            $table->dateTime('placed_at')->comment('Время оформления заказа (created_at MarketHub)');
            $table->dateTime('source_updated_at')->comment('Время последнего изменения (updated_at MarketHub)');
            $table->dateTime('paid_at')->nullable()->comment('Время оплаты');
            $table->dateTime('cancelled_at')->nullable()->comment('Время отмены');
            $table->text('cancel_reason')->nullable()->comment('Причина отмены');
            $table->dateTime('version_event_created_at')->comment('created_at события последней применённой версии');
            $table->string('version_event_id', 64)->comment('id события последней применённой версии');
            $table->json('snapshot')->comment('Последний применённый снимок заказа');
            $table->timestamps();

            $table->index('number');
            $table->index(['status', 'placed_at']);
            $table->index(['customer_email_normalized', 'placed_at']);
            $table->index(['placed_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
