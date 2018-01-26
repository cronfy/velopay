<?php
/**
 * Created by PhpStorm.
 * User: cronfy
 * Date: 01.11.17
 * Time: 13:36
 */

namespace cronfy\velopay\gateways\YandexKassaSberbankApi;

use cronfy\velopay\gateways\YandexKassaApi\BaseGateway;

class Gateway extends BaseGateway {

    public function getPaymentMethod()
    {
        return 'sberbank';
    }

}