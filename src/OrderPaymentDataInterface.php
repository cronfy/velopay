<?php
/**
 * Created by PhpStorm.
 * User: cronfy
 * Date: 07.11.17
 * Time: 13:38
 */

namespace cronfy\velopay;

use cronfy\cart\common\exceptions\EnsureSaveException;

interface OrderPaymentDataInterface
{
    /**
     * Требуется ли сохранение данных. Сохранение требуется только если в процессе обработки
     * платежа гейтом он в данных что-то изменил.
     * @return bool
     */
    public function requiresSave();

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
    public function getData();

    /**
     * @param $value \ArrayAccess|array
     * @return void
     */
    public function setData($value);

}