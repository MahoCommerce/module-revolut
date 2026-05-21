<?php

/**
 * Maho
 *
 * @package    Maho_Revolut
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
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
