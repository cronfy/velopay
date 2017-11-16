<?php

use yii\db\Migration;

/**
 * Handles the creation of table `invoice`.
 */
class m171107_103222_create_order_payment_data_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function up()
    {
        $this->createTable('order_payment_data', [
            'id' => $this->primaryKey(),
            'sid' => $this->string()->notNull()->unique(),

            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),

            'data' => $this->string(1024),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function down()
    {
        $this->dropTable('order_payment_data');
    }
}
