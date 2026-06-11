<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Revolut
 */

declare(strict_types=1);

class Maho_Revolut_Block_Info extends Mage_Payment_Block_Info
{
    #[\Override]
    protected function _prepareSpecificInformation($transport = null): \Maho\DataObject
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $payment = $this->getInfo();
        $helper = Mage::helper('maho_revolut');

        $data = [];

        $revolutOrderId = $payment->getAdditionalInformation('revolut_order_id');
        if ($revolutOrderId) {
            $data[$helper->__('Revolut Order ID')] = $revolutOrderId;
        }

        return $transport->addData($data);
    }
}
