<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Revolut
 */

declare(strict_types=1);

class Maho_Revolut_Model_System_Config_Source_PaymentAction
{
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE,
                'label' => Mage::helper('maho_revolut')->__('Authorize Only'),
            ],
            [
                'value' => Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE,
                'label' => Mage::helper('maho_revolut')->__('Authorize and Capture'),
            ],
        ];
    }
}
