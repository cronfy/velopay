<?php
/**
 * Created by PhpStorm.
 * User: cronfy
 * Date: 01.11.17
 * Time: 13:44
 */

namespace cronfy\velopay\gateways;


use cronfy\velopay\InvoiceInterface;
use cronfy\velopay\OrderPaymentDataInterface;

abstract class AbstractGateway
{
    // гейт требует запросить у посетителя данные, например, номер карты, cvc и пр.
    const STATUS_WAITING_USER_INPUT = 'waiting user input';
    // гейт требует перенаправить посетителя на другую страницу (как правило на платежную систему)
    const STATUS_SUGGEST_USER_REDIRECT = 'suggest user redirect';
    // гейт просит подождать - оплата в процессе
    const STATUS_PENDING = 'pending';
    // завершено успешно
    const STATUS_PAID = 'paid';
    // платеж отменен
    const STATUS_CANCELED = 'canceled';
    // ошибка
    const STATUS_ERROR = 'error';

    public $testMode;

    public $status;
    public $statusDetails;
    
    protected $_invoice;
    public function setInvoice(InvoiceInterface $invoice) {
        $this->_invoice = $invoice;
    }

    /**
     * @return InvoiceInterface
     */
    public function getInvoice()
    {
        return $this->_invoice;
    }

    abstract public function start();
    abstract public function process();

    protected $_sid;

    public function getSid() {
        return $this->_sid;
    }

    public function setSid($value) {
        $this->_sid = $value;
    }

    protected $_log;
    public function getLog() {
        return $this->_log;
    }
    public function setLog($value) {
        $this->_log = $value;
    }

    protected $_returnUrl;
    public function getReturnUrl() {
        return $this->_returnUrl;
    }
    public function setReturnUrl($value) {
        $this->_returnUrl = $value;
    }


}