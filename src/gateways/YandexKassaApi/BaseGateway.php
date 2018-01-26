<?php
/**
 * Created by PhpStorm.
 * User: cronfy
 * Date: 01.11.17
 * Time: 13:36
 */

namespace cronfy\velopay\gateways\YandexKassaApi;

use cronfy\velopay\gateways\AbstractGateway;
use cronfy\velopay\InvoiceInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;
use yii\db\Exception;
use GuzzleHttp\Psr7;

/**
 * TODO:
 *
 * 1. Запросы к API должны возвращать не Response, а массив с ответом.
 */
abstract class BaseGateway extends AbstractGateway
{
    public $shopId;
    public $shopPassword;

    public $debug = false;

    public $receipt = false;

    abstract public function getPaymentMethod();

    public function start()
    {
        /** @var InvoiceInterface $invoice */
        $invoice = $this->getInvoice();

        $gatewayData = $invoice->getGatewayData();

        if (isset($gatewayData['payment_id'])) {
            throw new \Exception("Invoice processing already started");
        };

        try {
            $requestData = [
                'amount' => [
                    'value' => $invoice->getAmountValue(),
                    'currency' => $invoice->getAmountCurrency(),
                ],
                'payment_method_data' => [
                    'type' => $this->getPaymentMethod(),
                ],
                "confirmation" => [
                    'type' => 'redirect',
                    "return_url" => $this->getReturnUrl()
                ],
            ];
            if ($this->receipt) {
                $requestData['receipt'] = $invoice->getReceiptData();
            }
//            D($requestData);
            $response = $this->request('POST', 'payments', [], $requestData);
            // save payment_id
            $data = json_decode($response->getBody(), true);
            $gatewayData['payment_id'] = $data['id'];
            // по этому идентификатору мы сможем найти транзакцию в момент получения нотифмкации от YK
            $invoice->setGatewayTransactionSid($data['id']);
            // remember confirmation_url
            $gatewayData['confirmation_url'] = $data['confirmation']['confirmation_url'];
        } catch (BadResponseException $e) {
//            echo "\n*\n* REQUEST\n*\n";
//            echo Psr7\str($e->getRequest());
//            if ($e->hasResponse()) {
//                echo "\n*\n* RESPONSE\n*\n";
//                echo Psr7\str($e->getResponse());
//            }
//
//            echo "\n*\n* MESSAGE\n*\n";
//            echo $e->getMessage();
//            echo "***\n";

            throw $e;
        }

        return $this->processResponse($data);
    }

    public function process() {
        /** @var InvoiceInterface $invoice */
        $invoice = $this->getInvoice();
        $gatewayData = $invoice->getGatewayData();
        if (!isset($gatewayData['payment_id'])) {
            throw new \Exception("Can not process without payment id");
        }

        $payment = $this->payment($gatewayData['payment_id']);
        return $this->processResponse($payment);
    }

    public function payment($id) {
        $data = $this->get('payments/' . $id);
        return $data;
    }

    public function processResponse($data) {
        if (is_a($data, ResponseInterface::class)) {
            /**
             * @deprecated : раньше ответ приходил в виде Response,
             * а теперь (см. TODO наверху) двигаемся к тому, чтобы
             * он приходил в виде array.
             */
            $data = json_decode($data->getBody(), true);
        }

        $result = null;

        if (@$data['type'] === 'processing') {
            $this->status = static::STATUS_PENDING; // платежная система думает, нужно подождать
            return;
        }

        if (@$data['type'] === 'error') {
            $this->status = static::STATUS_ERROR; // неустранимая ошибка
            return;
        }

        switch ($data['status']) {
            case 'pending':
                // 1. есть pending, когда paid = 0 и есть  confirmation->confirmation_url - тогда надо перенаправить
                // посетителя на ЯК для оплаты.
                // 2. А есть pending, paid = 1 и нет confirmation->confirmation_url, то есть заказ оплачен,
                // но гейт еще что-то думает. Например, ждет фискализации от кассы. Платеж еще может отмениться
                // (тогда деньги вернутся покупателю)

                // 1. Нужен редирект
                if (isset($data['confirmation']['confirmation_url'])) {
                    $this->status = static::STATUS_SUGGEST_USER_REDIRECT;
                    $this->statusDetails = [
                        'url' => $data['confirmation']['confirmation_url'],
                    ];
                    break;
                }

                // 2. Нужно подождать.
                if (isset($data['paid']) && $data['paid']) {
                    $this->status = static::STATUS_PENDING;
                    break;
                }

                $gatewayData = $this->getInvoice()->getGatewayData();

                if (isset($gatewayData['confirmation_url'])) {
                    // 3. В storage есть confirmation_url. Значит, платеж был инициирован ранее.
                    // Значит, сейчас мы обращаемся к нему повторно по /payments/{id}, а в таком
                    // случае Яндекс не возвращает confirmation_url.
                    // Но запрос все еще pending. Если бы клиент заплатил, здесь было бы
                    // waiting_for_capture. Значит, он еще не заплатил, и то, что здесь нужно
                    // сделать - либо инициировать платеж повторно и пройти по пути start, либо (что
                    // проще и предсказуемее) - перейти по сохраненному в start() url.
                    $this->status = static::STATUS_SUGGEST_USER_REDIRECT;
                    $this->statusDetails = [
                        'url' => $gatewayData['confirmation_url'],
                    ];
                    break;
                }

                throw new \Exception("Unknown pending response");
            case 'waiting_for_capture':
                if (!$this->getInvoice()->isAmountEqualsTo($data['amount']['value'], $data['amount']['currency'])) {
                    throw new \Exception("Wrong amount for capture");
                }
                // Сейчас здесь мы безусловно делаем capture - забираем деньги.
                // Однако это также точка, в которой можно отказаться от приема платежа.
                // Может потребоваться ответ от контроллера: требуется забрать деньги или отклонить
                // платеж.

                // TODO: нужно реализовать взаимодействие с контроллером в этом месте.
                // Но не хочется перегружать интерфейс взаимодействия через статусы. Возможность сделать/отклонить
                // capture - это уже особенности конкретного гейта, и контроллер может о них не знать
                // (у других гейтов могут быть и другие особенности). Лучше так - контроллер по умолчанию
                // будет предполагать, что платеж принимается без вопросов, а гейт по умолчанию вопросов
                // задавать не будет. Но если хочется организовать взаимодействие, касающееся нюансов конкретного
                // гейта, это можно сделать через callback'и, например `$this->callbacks['waiting_for_capture'].
                // Если callback'а нет - переходим к capture, если есть и вернул true - тоже, если false - выходим
                // со статусом PENDING (тогда можно в `$this->statusDetails` добавить информацию о том,
                // что capture был возможен, но отклонен).
                $response = $this->request('POST', 'payments/' . $data['id'] . '/capture' , [], [
                    'amount' => [
                        'value' => $this->getInvoice()->getAmountValue(),
                        'currency' => $this->getInvoice()->getAmountCurrency(),
                    ],
                ]);
                $data = json_decode($response->getBody(), true);
                if (@$data['status'] === 'waiting_for_capture') {
                    // такого быть не должно, но на всякий случай не будем уходить здесь в рекурсию
                    $this->status = static::STATUS_PENDING; // платежная система думает, нужно подождать
                    break;
                }

                $result = $this->processResponse($response);
                break;
            case 'succeeded':
                if (!$this->getInvoice()->isAmountEqualsTo($data['amount']['value'], $data['amount']['currency'])) {
                    throw new \Exception("Wrong amount for successful payment");
                }

                if ($data['paid'] !== true) {
                    throw new \Exception("Unexpected paid status");
                }

                $this->status = static::STATUS_PAID;
                $this->statusDetails = [
                    'paymentFqid' => $this->getSid() . '.' . $data['id']
                ];
                break;
            case 'canceled':
                $this->status = static::STATUS_CANCELED;
                break;
            default:
                D(['NEW STATUS', $data]);
                throw new \Exception("Unknown status");
        }

//        D($result);
    }

    /**
     * @return string
     * @throws Exception
     */
    protected function getKassaUrl() {
        if ($this->testMode) {
            throw new Exception("Not implemented");
        }
        return 'https://payment.yandex.net/api/v3/';
    }

    /**
     * @return Client
     * @throws Exception
     */
    protected function getClient() {
        $client = new Client([
            // Base URI is used with relative requests
            'base_uri' => $this->getKassaUrl(),
            // You can set any number of default request options.
            'timeout'  => 10.0,
            'debug' => $this->debug,
        ]);

        return $client;
    }

    protected function getIdempotenceKey($uri) {
       $gatewayData = $this->getInvoice()->getGatewayData();
       $storageKey = $this->getSid() . '.IdempotenceKey.' . $uri;
       if (!isset($gatewayData[$storageKey])) {
           $gatewayData[$storageKey] = Uuid::uuid4()->toString();
       }

        return $gatewayData[$storageKey];
    }

    protected function request($method, $uri, $queryArgs = [], $payload = null) {
        $options = ['query' => []];
        if ($queryArgs) $options['query'] = array_merge($options['query'], $queryArgs);
        if ($payload) $options['json'] = $payload;
        $options['auth'] = [$this->shopId, $this->shopPassword];
        $options['headers'] = [
            'Idempotence-Key' => $this->getIdempotenceKey($uri),
        ];

        $logger = $this->getLog();

        if ($logger) {
            $logger->request($method, $uri, $options);
        }

        $response = $this->getClient()->request($method, $uri, $options);

        if ($logger) {
            $logger->response($response);
        }

        return $response;
    }

    protected function post($uri, $idempotenceKey, $queryArgs = [], $payload = null) {
        $options = ['query' => []];
        if ($queryArgs) $options['query'] = array_merge($options['query'], $queryArgs);
        if ($payload) $options['json'] = $payload;
        $options['headers'] = [
            'Idempotence-Key' => $idempotenceKey,
        ];
        $options['auth'] = [$this->shopId, $this->shopPassword];

        $logger = $this->getLog();

        if ($logger) {
            $logger->request('POST', $uri, $options);
        }

        $response = $this->getClient()->request('POST', $uri, $options);

        if ($logger) {
            $logger->response($response);
        }

        return $response;
    }

    protected function get($uri, $queryArgs = [], $payload = null) {
        $options = ['query' => []];
        if ($queryArgs) $options['query'] = array_merge($options['query'], $queryArgs);
        if ($payload) $options['json'] = $payload;
        $options['auth'] = [$this->shopId, $this->shopPassword];

        $logger = $this->getLog();

        if ($logger) {
            $logger->request('GET', $uri, $options);
        }

        $response = $this->getClient()->request('GET', $uri, $options);

        if ($logger) {
            $logger->response($response);
        }

        $data = json_decode($response->getBody(), true);

        return $data;
    }

    public function capture($paymentId, $value, $currency, $idempotenceKey) {
        $response = $this->post('payments/' . $paymentId . '/capture' , $idempotenceKey, [], [
            'amount' => [
                'value' => $value,
                'currency' => $currency,
            ],
        ]);

        $data = json_decode($response->getBody(), true);
        return $data;
    }

}