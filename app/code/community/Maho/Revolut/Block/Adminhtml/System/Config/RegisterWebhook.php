<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Revolut
 */

declare(strict_types=1);

class Maho_Revolut_Block_Adminhtml_System_Config_RegisterWebhook extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    #[\Override]
    protected function _getElementHtml(\Maho\DataObject $element): string
    {
        $this->setTemplate('maho/revolut/system/config/register-webhook.phtml');
        $this->setData('element', $element);
        $this->setData('ajax_url', Mage::helper('adminhtml')->getUrl('adminhtml/revolut_config/registerWebhook'));
        return $this->_toHtml();
    }
}
