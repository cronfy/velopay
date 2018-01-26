<?php
/**
 * Created by PhpStorm.
 * User: cronfy
 * Date: 01.11.17
 * Time: 13:36
 */

namespace cronfy\velopay\gateways\YandexKassaApi;

class Gateway extends BaseGateway {

    public function getPaymentMethod()
    {
        return 'bank_card';
    }
}
