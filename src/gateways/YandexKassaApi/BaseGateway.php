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
use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;
use yii\db\Exception;
use GuzzleHttp\Psr7;

abstract class BaseGateway extends AbstractGateway
{
    const EVENT_START_REQUEST = 'start-request';

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

            $eventResult = $this->getMiddleware()->processEvent(
                $this,
                static::EVENT_START_REQUEST,
                $requestData
            );
            if ($eventResult) {
                $requestData = $eventResult;
            }

            if ($this->receipt) {
                // для поддержки чеков модель Invoice должна поддерживать специфичный для
                // YandexKassa метод getReceiptData()
                // Возвращаемое значение - массив в соответствии с документацией YK по полю receipt
                // https://kassa.yandex.ru/docs/checkout-api/#sozdanie-platezha
                $requestData['receipt'] = $invoice->getReceiptData();
            }
//            D($requestData);
            $data = $this->post('payments', [], $requestData);
            $gatewayData['payment_id'] = $data['id'];
            // по этому идентификатору мы сможем найти транзакцию в момент получения нотифмкации от YK
            $invoice->setGatewayInvoiceSid($data['id']);
            // remember confirmation_url
            $gatewayData['confirmation_url'] = $data['confirmation']['confirmation_url'];

            $invoice->setGatewayData($gatewayData);
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

    /**
     * @return mixed
     */
    public function getPaymentInfo()
    {
        $payment = $this->payment($this->getInvoice()->getGatewayData()['payment_id']);
        return $payment;
    }

    public function processResponse($data) {
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
                $data = $this->post('payments/' . $data['id'] . '/capture' , [], [
                    'amount' => [
                        'value' => $this->getInvoice()->getAmountValue(),
                        'currency' => $this->getInvoice()->getAmountCurrency(),
                    ],
                ]);
                if (@$data['status'] === 'waiting_for_capture') {
                    // такого быть не должно, но на всякий случай не будем уходить здесь в рекурсию
                    $this->status = static::STATUS_PENDING; // платежная система думает, нужно подождать
                    break;
                }

                // мы сделали еще один запрос (capture), получили новый ответ и пойдем его опять обрабатывать
                $this->processResponse($data);
                break;
            case 'succeeded':
                if (!$this->getInvoice()->isAmountEqualsTo($data['amount']['value'], $data['amount']['currency'])) {
                    throw new \Exception("Wrong amount for successful payment");
                }

                if ($data['paid'] !== true) {
                    throw new \Exception("Unexpected paid status");
                }

                $this->status = static::STATUS_PAID;
                break;
            case 'canceled':
                $this->status = static::STATUS_CANCELED;
                break;
            default:
                throw new \Exception("Unknown status");
        }
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
        $invoice = $this->getInvoice();
        $gatewayData = $invoice->getGatewayData();

        $storageKey = 'IdempotenceKey.' . $uri;
        if (!isset($gatewayData[$storageKey])) {
            $gatewayData[$storageKey] = Uuid::uuid4()->toString();
        }

        $invoice->setGatewayData($gatewayData);

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

        try {
            $response = $this->getClient()->request($method, $uri, $options);
        } catch (ClientException $e) {
            throw $e;
//            echo "\n*\n* REQUEST\n*\n";
//            $request = $e->getRequest();
//            echo Psr7\str($request);
//            if ($e->hasResponse()) {
//                echo "\n*\n* RESPONSE\n*\n";
//                echo Psr7\str($e->getResponse());
//            }
//
//            print_r (json_decode($request->getBody()));
//
//            echo "\n*\n* MESSAGE\n*\n";
//            echo $e->getMessage();
//            echo "***\n";
//            D();
        }

        if ($logger) {
            $logger->response($response);
        }

        return $response;
    }

    protected function post($uri, $queryArgs = [], $payload = null) {
        $response = $this->request('POST', $uri, $queryArgs, $payload);
        $data = json_decode($response->getBody(), true);
        return $data;
    }

    protected function get($uri, $queryArgs = [], $payload = null) {
        $response = $this->request('GET', $uri, $queryArgs, $payload);
        $data = json_decode($response->getBody(), true);
        return $data;
    }

    public function capture($paymentId, $value, $currency, $idempotenceKey) {
        $data = $this->post('payments/' . $paymentId . '/capture' , $idempotenceKey, [], [
            'amount' => [
                'value' => $value,
                'currency' => $currency,
            ],
        ]);

        return $data;
    }

}