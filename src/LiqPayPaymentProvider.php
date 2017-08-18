<?php
declare(strict_types=1);

namespace RabbitCMS\Payments\LiqPay;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareTrait;
use RabbitCMS\Payments\Concerns\PaymentProvider;
use RabbitCMS\Payments\Contracts\ContinuableInterface;
use RabbitCMS\Payments\Contracts\InvoiceInterface;
use RabbitCMS\Payments\Contracts\OrderInterface;
use RabbitCMS\Payments\Contracts\PaymentProviderInterface;
use RabbitCMS\Payments\Entities\Transaction;
use RabbitCMS\Payments\Support\Action;
use function GuzzleHttp\json_decode;
use RabbitCMS\Payments\Support\Invoice;
use RuntimeException;

/**
 * Class LiqPayPaymentProvider
 *
 * @package RabbitCMS\Payments\LiqPay
 */
class LiqPayPaymentProvider implements PaymentProviderInterface
{
    use LoggerAwareTrait;
    use PaymentProvider;

    const VERSION = 3;
    const URL = 'https://www.liqpay.com/api/';

    protected static $statuses = [
        'failure' => InvoiceInterface::STATUS_FAILURE,
        'success' => InvoiceInterface::STATUS_SUCCESSFUL,
        'reversed' => InvoiceInterface::STATUS_REFUND,
        'refund' => InvoiceInterface::STATUS_REFUND
    ];

    /**
     * @return string
     */
    public function getProviderName(): string
    {
        return 'liqpay';
    }

    /**
     * @param OrderInterface $order
     *
     * @param callable|null  $callback
     *
     * @return ContinuableInterface
     */
    public function createPayment(OrderInterface $order, callable $callback = null): ContinuableInterface
    {
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

        $payTypes = $this->config('paytypes');

        if (!empty($payTypes)) {
            $params['paytypes'] = $payTypes;
        }

        if ($this->config('sandbox')) {
            $params['sandbox'] = 1;
        }

        $client = $payment->getClient();

        if ($client !== null) {
            $identifier = $client->getId();
            if (!empty($identifier)) {
                $params['customer'] = $identifier;
            }
            foreach ([
                         'first_name',
                         'last_name',
                        // 'Country' => 'country_code',
                         'city',
                         'address',
                         'postal_code'
                     ] as $property => $field) {
                $property = is_int($property) ? Str::camel($field) : $property;
                $value = $client->{"get{$property}"}();
                if ($value !== '') {
                    $params["sender_{$field}"] = $value;
                }
            }
        }

        $product = $payment->getProduct();

        if ($product !== null) {
            foreach (['url', 'category', 'name', 'description'] as $field) {
                $property = Str::camel($field);
                $value = $product->{"get{$property}"}();
                if ($value !== '') {
                    $params["product_{$field}"] = $value;
                }
            }
        }

        //        $params['info'] = $payment->getInfo();
        $params['result_url'] = $payment->getReturnUrl();

        $transaction = $this->makeTransaction($order, $payment);

        $params['order_id'] = $transaction->getTransactionId();

        return (new Action($this, Action::ACTION_OPEN, $this->buildData($params)))
            ->setUrl(self::URL . '3/checkout')
            ->setMethod(Action::METHOD_POST);
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
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

        if (array_key_exists($params['status'], self::$statuses)) {
            $this->manager->process(new Invoice(
                $this,
                (string)$params['payment_id'],
                (string)$params['order_id'],
                Transaction::TYPE_PAYMENT,
                (int)self::$statuses[$params['status']],
                (float)$params['amount']
            ));
        }
        return new Response();
    }

    /**
     * @param array $params
     *
     * @return array
     */
    protected function buildData(array $params = []): array
    {
        $data = base64_encode(json_encode($params));
        return [
            'data' => $data,
            'signature' => $this->sign($data),
        ];
    }

    /**
     * Call API
     *
     * @param string $path
     * @param array  $params
     *
     * @return array
     */
    public function api(string $path, array $params = []): array
    {
        $response = (new Client())->request('POST', static::URL . $path, [
            RequestOptions::FORM_PARAMS => $this->buildData($params)
        ]);

        /** @noinspection ReturnNullInspection */
        return json_decode($response->getBody()->getContents());
    }

    /**
     * @param string $request
     *
     * @return string
     */
    protected function sign(string $request): string
    {
        $key = $this->config('private_key');
        return base64_encode(sha1("{$key}{$request}{$key}", true));
    }
}
