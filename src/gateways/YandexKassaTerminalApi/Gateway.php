<?php
/**
 * Created by PhpStorm.
 * User: cronfy
 * Date: 01.11.17
 * Time: 13:36
 */

namespace cronfy\velopay\gateways\YandexKassaTerminalApi;

use cronfy\velopay\gateways\AbstractGateway;
use cronfy\velopay\InvoiceInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Ramsey\Uuid\Uuid;
use yii\db\Exception;
use GuzzleHttp\Psr7;

class Gateway extends AbstractGateway
{
    public $shopId;
    public $shopPassword;

    public $returnUrl;

    public $debug = false;

    public $receipt = false;

    public function start()
    {
        /** @var InvoiceInterface $invoice */
        $invoice = $this->getInvoice();

        try {
            $requestData = [
                'amount' => [
                    'value' => $invoice->getAmountValue(),
                    'currency' => $invoice->getAmountCurrency(),
                ],
                'payment_method_data' => [
                    'type' => 'cash',
                ],
                "confirmation" => [
                    'type' => 'redirect',
                    "return_url" => $this->returnUrl
                ],
            ];
            if ($this->receipt) {
                $requestData['receipt'] = $invoice->getReceiptData();
            }
//            D($requestData);
            $response = $this->request('POST', 'payments', [], $requestData);
        } catch (ClientException $e) {
//            echo Psr7\str($e->getRequest());
//            if ($e->hasResponse()) {
//                echo Psr7\str($e->getResponse());
//            }
//
//            D($e->getMessage());
            throw $e;
        }

        return $this->processResponse($response);
    }

    public function process() {
        return $this->start();
    }

    public function processResponse($response) {
        $data = json_decode($response->getBody(), true);
//        D((string)$response->getBody());

        $result = null;

        switch ($data['status']) {
            case 'pending':
                // TODO:
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

                throw new \Exception("Unknown pending response");
            case 'waiting_for_capture':
                if (!$this->getInvoice()->isAmountEqualsTo($data['amount']['value'], $data['amount']['currency'])) {
                    throw new \Exception("Wrong amount for capture");
                }
                $response = $this->request('POST', 'payments/' . $data['id'] . '/capture' , [], [
                    'amount' => [
                        'value' => $this->getInvoice()->getAmountValue(),
                        'currency' => $this->getInvoice()->getAmountCurrency(),
                    ],
                ]);
                $data = json_decode($response->getBody(), true);
                if ($data['status'] === 'waiting_for_capture') {
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
                break;
            case 'canceled':
                $this->getInvoice()->getStorage()->ensureDelete();
                $this->status = static::STATUS_CANCELED;
                break;
            default:
                D(['NEW STATUS', $data]);
                throw new \Exception("Unknown status");
        }

//        D($result);
    }

    protected function getKassaUrl() {
        if ($this->testMode) {
            throw new Exception("Not implemented");
        }
        return 'https://payment.yandex.net/api/v3/';
    }

    /**
     * @return Client
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

    protected function getStorage() {
        return $this->getInvoice()->getStorage();
    }

    protected function getIdempotenceKey($uri) {
       $storage = $this->getStorage();
       $data = $storage->getData();
       $storageKey = $this->getSid() . '.IdempotenceKey.' . $uri;
       if (!isset($data[$storageKey])) {
           $data[$storageKey] = Uuid::uuid4()->toString();
           $storage->setData($data);
       }

        return $data[$storageKey];
    }

    protected function request($method, $uri, $queryArgs = [], $payload = null) {
        $options = ['query' => []];
        if ($queryArgs) $options['query'] = array_merge($options['query'], $queryArgs);
        if ($payload) $options['json'] = $payload;
        $options['auth'] = [$this->shopId, $this->shopPassword];
        $options['headers'] = [
            'Idempotence-Key' => $this->getIdempotenceKey($uri),
        ];
        return $this->getClient()->request($method, $uri, $options);
    }

}