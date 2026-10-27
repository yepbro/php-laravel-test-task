<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response(): void
    {
        // `/` больше не страница, а перенаправление на список заказов
        // админки (T-013, FR-ADM-01); успешный ответ — сама страница
        // списка по адресу назначения.
        $this->followingRedirects()->get(route('home'))->assertOk();
    }
}
