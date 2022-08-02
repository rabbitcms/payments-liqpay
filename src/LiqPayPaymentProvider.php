<?php

declare(strict_types=1);

namespace RabbitCMS\Payments\LiqPay;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\{Boolean, BooleanGroup, Password, Select, Text};
use Laravel\Nova\Http\Requests\NovaRequest;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Log\LoggerAwareTrait;
use RabbitCMS\Payments\Concerns\PaymentProvider;
use RabbitCMS\Payments\Contracts\{ContinuableInterface,
    InvoiceInterface,
    OrderInterface,
    PaymentProviderInterface,
    SubscribePaymentInterface,
    TransactionInterface};
use RabbitCMS\Payments\Entities\Transaction;
use RabbitCMS\Payments\LiqPay\Events\LiqPayPaymentParamsEvent;
use RabbitCMS\Payments\Support\{Action, Invoice};
use RuntimeException;
use function GuzzleHttp\json_decode;

class LiqPayPaymentProvider implements PaymentProviderInterface
{
    use LoggerAwareTrait;
    use PaymentProvider;

    const VERSION = 3;
    const URL = 'https://www.liqpay.ua/api/';

    protected static $statuses = [
        'failure' => InvoiceInterface::STATUS_FAILURE,
        'success' => InvoiceInterface::STATUS_SUCCESSFUL,
        'sandbox' => InvoiceInterface::STATUS_SUCCESSFUL,
        'reversed' => InvoiceInterface::STATUS_REFUND,
        'refund' => InvoiceInterface::STATUS_REFUND,
        'subscribed' => InvoiceInterface::STATUS_SUCCESSFUL,
        'unsubscribed' => InvoiceInterface::STATUS_CANCELED,
    ];

    public function getProviderName(): string
    {
        return 'liqpay';
    }

    public function createPayment(
        OrderInterface $order,
        callable $callback = null,
        array $options = []
    ): ContinuableInterface {
        $periods = [
            SubscribePaymentInterface::PERIODICITY_MONTH => 'month',
            SubscribePaymentInterface::PERIODICITY_YEAR => 'year',
        ];
        $payment = $order->getPayment();
        if ($callback) {
            call_user_func($callback, $payment, $this);
        }
        $params = [
            'version' => self::VERSION,
            'public_key' => $this->config('public_key'),
            'server_url' => $this->getCallbackUrl(),
            'action' => 'pay',
            'currency' => $payment->getCurrency(),
            'amount' => $payment->getAmount(),
            'description' => $payment->getDescription(),
        ];
        if ($payment instanceof SubscribePaymentInterface) {
            if (! array_key_exists($payment->getSubscribePeriodic(), $periods)) {
                throw new RuntimeException('LiqPay supports only PERIODICITY_MONTH and PERIODICITY_YEAR periodicity');
            }

            $date = $payment->getSubscribeStart();
            $params['action'] = 'subscribe';
            $params['subscribe'] = '1';
            $params['subscribe_date_start'] = \DateTime::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d H:i:s'),
                $date->getTimezone())
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
            $params['subscribe_periodicity'] = $periods[$payment->getSubscribePeriodic()];
        }

        $payTypes = $this->config('paytypes');

        if (! empty($payTypes)) {
            $params['paytypes'] = $payTypes;
        }

        if ($this->config('sandbox')) {
            $params['sandbox'] = 1;
        }

        $client = $payment->getClient();

        if ($client !== null) {
            $identifier = $client->getId();
            if (! empty($identifier)) {
                $params['customer'] = $identifier;
            }
            foreach ([
                         'first_name',
                         'last_name',
                         // 'Country' => 'country_code',
                         'city',
                         'address',
                         'postal_code',
                     ] as $property => $field) {
                $property = is_int($property) ? Str::studly($field) : $property;
                $value = $client->{"get{$property}"}();
                if ($value !== '') {
                    $params["sender_{$field}"] = $value;
                }
            }
        }

        $product = $payment->getProduct();

        if ($product !== null) {
            foreach (['url', 'category', 'name', 'description'] as $field) {
                $property = Str::studly($field);
                $value = $product->{"get{$property}"}();
                if ($value !== '') {
                    $params["product_{$field}"] = $value;
                }
            }
        }

        //        $params['info'] = $payment->getInfo();
        $params['result_url'] = $payment->getReturnUrl();

        $transaction = $this->makeTransaction($order, $payment, $options,
            $payment instanceof SubscribePaymentInterface);

        $params['order_id'] = $transaction->getTransactionId();

        $modifiedParamsArr = event(new LiqPayPaymentParamsEvent($params, $order));
        if (!empty($modifiedParamsArr)) {
            $params = $modifiedParamsArr[0];
        }

        return (new Action($this, Action::ACTION_OPEN, $this->buildData($params)))
            ->setUrl(self::URL.'3/checkout')
            ->setMethod(Action::METHOD_POST);
    }

    public function callback(ServerRequestInterface $request): ResponseInterface
    {
        $data = $request->getParsedBody();
        /** @noinspection ReturnFalseInspection */
        /** @noinspection ReturnNullInspection */
        $params = json_decode(base64_decode($data['data']), true);
        if ($params['version'] !== self::VERSION) {
            throw new RuntimeException('Invalid version.');
        }
        if ($params['public_key'] !== $this->config('public_key')) {
            throw new RuntimeException('Invalid public key.');
        }

        if ($this->sign($data['data']) !== $data['signature']) {
            throw new RuntimeException('Invalid signature');
        }

        if ($params['action'] === 'subscribe') {
            if (array_key_exists($params['status'], self::$statuses)) {
                $this->manager->process(new Invoice(
                    $this,
                    (string) $params['payment_id'],
                    (string) $params['order_id'],
                    TransactionInterface::TYPE_SUBSCRIPTION,
                    (int) self::$statuses[$params['status']],
                    0
                ));
            }
        } elseif (array_key_exists($params['status'], self::$statuses)) {
            $type = $params['status'] === 'reversed'
                ? TransactionInterface::TYPE_REFUND
                : TransactionInterface::TYPE_PAYMENT;

            $this->manager->process(new Invoice(
                $this,
                (string) $params['payment_id'],
                (string) $params['order_id'],
                $type,
                (int) self::$statuses[$params['status']],
                (float) $params['status'] === 'reversed' ? $params['refund_amount'] : $params['amount'],
                $params['status'] === 'reversed' ? 0 : (float) ($params['receiver_commission'] ?? 0)
            ));
        }

        return new Response();
    }

    protected function buildData(array $params = []): array
    {
        $data = base64_encode(json_encode($params));

        return [
            'data' => $data,
            'signature' => $this->sign($data),
        ];
    }

    /**
     * @param  OrderInterface|Model  $order
     */
    public function unsubscribe(OrderInterface $order): bool
    {
        /* @var Transaction $transaction */
        $transaction = Transaction::query()->where([
            'order_type' => $order->getMorphClass(),
            'order_id' => $order->getKey(),
            'driver' => $this->getShop(),
        ])
            ->whereNull('parent_id')
            ->firstOrFail();
        if ($transaction->getStatus() === Transaction::STATUS_CANCELED) {
            return true;
        }

        $result = $this->api('request', [
            'version' => self::VERSION,
            'public_key' => $this->config('public_key'),
            'action' => 'unsubscribe',
            'order_id' => $transaction->getTransactionId(),
        ]);

        return $result['status'] === 'unsubscribed';
    }

    public function api(string $path, array $params = []): array
    {
        $response = (new Client([
            RequestOptions::VERIFY => true,
        ]))->request('POST', static::URL.$path, [
            RequestOptions::FORM_PARAMS => $this->buildData($params),
        ]);

        /** @noinspection ReturnNullInspection */
        return json_decode($response->getBody()->getContents(), true);
    }

    protected function sign(string $request): string
    {
        $key = $this->config('private_key');

        return base64_encode(sha1("{$key}{$request}{$key}", true));
    }

    public function isValid(): bool
    {
        return ! empty($this->config('private_key')) && ! empty($this->config('public_key'));
    }

    public function getNovaFields(NovaRequest $request): array
    {
        return [
            Text::make('Публічний ключ', 'public_key')->rules(['required']),
            Password::make('Приватний ключ', 'private_key')
                ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
                    if (! empty($request[$requestAttribute])) {
                        $model->{$attribute} = $request[$requestAttribute];
                    }
                })
                ->creationRules(['required']),
            Select::make('Валюта', 'currency')
                ->options([
                    'UAH' => 'Гривня',
                    'EUR' => 'Євро',
                    'USD' => 'Долар',
                ])
                ->displayUsingLabels()
                ->default('UAH'),
            BooleanGroup::make('Методи', 'paytypes')
                ->resolveUsing(fn($value) => empty($value) ? null : array_fill_keys(explode(',',$value), true))
                ->fillUsing(function (NovaRequest $request, $model, $attribute, $requestAttribute) {
                    if ($request->exists($requestAttribute)) {
                        $values = array_filter(json_decode($request[$requestAttribute], true));
                        $model->{$attribute} = empty($values) ? null : implode(',',array_keys($values));
                    }
                })
                ->options([
                    'card' => 'Картка',
                    'privat24' => 'Приват24',
                    'liqpay' => 'LiqPay',
                    'invoice' => 'Рахунок',
                    'cash' => 'Готівка',
                ]),
        ];
    }
}
