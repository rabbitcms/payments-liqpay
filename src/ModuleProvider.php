<?php
declare(strict_types=1);

namespace RabbitCMS\Payments\LiqPay;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use RabbitCMS\Payments\Factory;

/**
 * Class ModuleProvider
 */
class ModuleProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->extend('payments', function (Factory $payments) {
            return $payments->extend('liqpay', function (Factory $payments, array $config) {
                return new LiqPayPaymentProvider($payments, $config);
            });
        });
    }
}
