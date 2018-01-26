<?php
/**
 * Created by PhpStorm.
 * User: cronfy
 * Date: 07.11.17
 * Time: 13:38
 */

namespace cronfy\velopay;

use cronfy\cart\common\exceptions\EnsureSaveException;

interface InvoiceInterface
{
    /**
     * Должен гарантированно сохранить данные или бросить эксепшен, если не получилось.
     * @return void
     * @throws EnsureSaveException
     */
    public function ensureSave();

    /**
     * Должен гарантированно удалить данные или бросить эксепшен, если не получилось.
     * Также должен делать так, чтобы getIsDeleted() возвращал true.
     * @return void
     * @throws EnsureSaveException
     */
    public function ensureDelete();

    /**
     * Сообщает, была ли этот объект удален из БД.
     * @return bool
     */
    public function getIsDeleted();

    /**
     * Возвращает объект/массив с данными
     * @return array|\ArrayAccess
     */
    public function getGatewayData();

    /**
     * @param $value \ArrayAccess|array
     * @return void
     */
    public function setGatewayData($value);

    /**
     * @return string
     */
    public function getGatewaySid();

    /**
     * @param $value string
     * @return void
     */
    public function setGatewaySid($value);

    /**
     * @param $sid string
     * @return void
     */
    public function setGatewayTransactionSid($sid);

    /**
     * @return string
     */
    public function getGatewayTransactionSid();

    /**
     * Получить sid счета для внешних ссылок. Используется для сокрытия реального id.
     * @return string
     */
    public function getExternalSid();

    /**
     * @param $sid string
     * @return integer
     */
    public static function getIdByExternalSid($sid);

    /**
     * @return string
     */
    public function getAmountCurrency();

    /**
     * @return float
     */
    public function getAmountValue();

    /**
     * @param $value float
     * @param $currency string
     * @return bool
     */
    public function isAmountEqualsTo($value, $currency);

}