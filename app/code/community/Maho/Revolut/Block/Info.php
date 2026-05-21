<?php

/**
 * Maho
 *
 * @package    Maho_Revolut
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
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
