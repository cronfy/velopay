<?php

namespace cronfy\velopay;

interface InvoiceInterface
{
    /**
     * @return OrderPaymentDataInterface
     */
    public function getStorage();

    /**
     * @param OrderPaymentDataInterface $value
     * @return
     */
    public function setStorage(OrderPaymentDataInterface $value);
}