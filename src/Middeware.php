<?php
/**
 * Created by PhpStorm.
 * User: cronfy
 * Date: 04.02.19
 * Time: 14:54
 */

namespace cronfy\velopay;


use cronfy\velopay\gateways\AbstractGateway;

/**
 * Для изменения работы gateway
 */
class Middeware
{
    /**
     * @param $originator AbstractGateway
     * @param $eventSid string
     * @param $eventData mixed
     * @return mixed process result
     */
    public function processEvent($originator, $eventSid, $eventData) {}
}