<?php
/**
 * Created by PhpStorm.
 * User: cronfy
 * Date: 07.11.17
 * Time: 13:38
 */

use cronfy\cart\common\exceptions\EnsureSaveException;
use cronfy\velopay\OrderPaymentDataInterface;
use paulzi\jsonBehavior\JsonBehavior;
use paulzi\jsonBehavior\JsonField;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

class OrderPaymentData extends ActiveRecord implements OrderPaymentDataInterface
{
    public function behaviors() {
        return [
            [
                'class' => JsonBehavior::className(),
                'attributes' => ['data'],
            ],
            [
                'class' => TimestampBehavior::className(),
            ],
        ];
    }

    // автор предложил решение проще: https://github.com/paulzi/yii2-json-behavior#how-to
    public function getDirtyAttributes($names = null)
    {
        $jsonAttributes = [];

        // get json attributes
        foreach ($this->behaviors as $behavior) {
            if (is_a($behavior, JsonBehavior::class)) {
                $jsonAttributes = array_merge($jsonAttributes, $behavior->attributes);
            }
        }

        // from native getDirtyAttributes()
        if ($names === null) {
            $names = $this->attributes();
        }

        $jsonCheckDirty = [];
        // remember which json attributes we want to check
        foreach ($names as $k => $name) {
            if (in_array($name, $jsonAttributes)) {
                $jsonCheckDirty[] = $name;
                unset($names[$k]); // exclude json attributes from native check
            }
        }

        // native check
        $dirtyAttributes = parent::getDirtyAttributes($names);

        $jsonDirty = [];
        // check whether json attributes were changed
        foreach ($jsonCheckDirty as $jsonAttribute) {
            if (isset($this->oldAttributes[$jsonAttribute])) {
                if (((string) $this->$jsonAttribute) !== $this->oldAttributes[$jsonAttribute]) {
                    $jsonDirty[] = $jsonAttribute;
                }
            } else {
                if ((string) $this->$jsonAttribute) {
                    $jsonDirty[] = $jsonAttribute;
                }
            }
        }

        // merge native result with our result
        foreach ($jsonDirty as $jsonAttribute) {
            $dirtyAttributes[$jsonAttribute] = $this->$jsonAttribute;
        }

        return $dirtyAttributes;
    }

    // сохраняем только при изменении данных, установленных шлюзом
    public function requiresSave() {
        $dirtyAttributes = $this->dirtyAttributes;
        return isset($dirtyAttributes['data']);
    }

    public function ensureSave() {
        if (!$this->save()) {
            throw new EnsureSaveException("Failed to save model");
        }
    }

    public function ensureDelete() {
        if (false === parent::delete()) {
            throw new EnsureSaveException();
        }
        $this->_deleted = true;
    }

    protected $_deleted = false;
    public function getIsDeleted()
    {
        return $this->_deleted;
    }

    public function getData() {
        return $this->data;
    }

    public function setData($value) {
        if (is_a($value, JsonField::class)) {
            $this->data = $value;
        } else {
            $this->data->set($value);
        }
    }
}