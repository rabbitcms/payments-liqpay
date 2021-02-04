<?php

declare(strict_types=1);

namespace RabbitCMS\Payments\LiqPay;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use RabbitCMS\Payments\Factory;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend('payments', fn(Factory $payments) => $payments
            ->extend('liqpay', fn(Factory $payments, array $config) => new LiqPayPaymentProvider($payments, $config)));
    }
}
