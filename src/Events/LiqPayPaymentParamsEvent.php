<?php

declare(strict_types=1);

namespace RabbitCMS\Payments\LiqPay\Events;

use RabbitCMS\Payments\Contracts\OrderInterface;

class LiqPayPaymentParamsEvent
{
    public function __construct(private array $params, private OrderInterface $order)
    {
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function getOrder(): OrderInterface
    {
        return $this->order;
    }
}
