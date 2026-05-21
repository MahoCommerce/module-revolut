<?php

/**
 * Maho
 *
 * @package    Maho_Revolut
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class Maho_Revolut_Block_Form extends Mage_Payment_Block_Form
{
    #[\Override]
    protected function _construct(): void
    {
        parent::_construct();
        $this->setMethodTitle(Mage::helper('maho_revolut')->__('Revolut Pay'));
    }
}
