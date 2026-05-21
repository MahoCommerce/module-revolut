<?php

/**
 * Maho
 *
 * @package    Maho_Revolut
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class Maho_Revolut_Model_Cron
{
    /**
     * Catch orders stuck in pending_payment (e.g. webhook never arrived) and
     * reconcile them against Revolut's view of the order.
     */
    public function checkPendingPayments(): void
    {
        $orders = Mage::getModel('sales/order')->getCollection()
            ->addFieldToFilter('state', Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('created_at', ['gteq' => date('Y-m-d H:i:s', strtotime('-24 hours'))])
            ->setPageSize(50);

        $orders->getSelect()->join(
            ['payment' => Mage::getSingleton('core/resource')->getTableName('sales/order_payment')],
            'payment.parent_id = main_table.entity_id',
            [],
        );
        $orders->getSelect()->where('payment.method = ?', 'revolut_pay');

        foreach ($orders as $order) {
            try {
                $this->_checkOrder($order);
            } catch (\Throwable $e) {
                Mage::log(
                    "Revolut cron: error checking order #{$order->getIncrementId()}: {$e->getMessage()}",
                    Mage::LOG_ERROR,
                    'revolut.log',
                );
            }
        }
    }

    protected function _checkOrder(Mage_Sales_Model_Order $order): void
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }

        $revolutOrderId = (string) $payment->getAdditionalInformation('revolut_order_id');
        if ($revolutOrderId === '') {
            return;
        }

        $storeId = (int) $order->getStoreId();
        /** @var Maho_Revolut_Model_Api $api */
        $api = Mage::getModel('maho_revolut/api', ['store_id' => $storeId]);
        $result = $api->retrieveOrder($revolutOrderId);

        $state = (string) ($result['state'] ?? '');
        /** @var Maho_Revolut_Helper_Data $helper */
        $helper = Mage::helper('maho_revolut');
        $currency = (string) ($result['currency'] ?? $order->getBaseCurrencyCode());
        $amountMinor = (int) ($result['amount'] ?? $payment->getAdditionalInformation('revolut_amount'));
        $amount = $helper->fromMinorUnits($amountMinor, $currency);

        if ($state === 'COMPLETED') {
            $payment->registerCaptureNotification($amount);
            $order->save();
            Mage::log(
                "Revolut cron: captured order #{$order->getIncrementId()}",
                Mage::LOG_INFO,
                'revolut.log',
            );
        } elseif ($state === 'AUTHORISED') {
            $payment->registerAuthorizationNotification($amount);
            $order->save();
            Mage::log(
                "Revolut cron: authorised order #{$order->getIncrementId()}",
                Mage::LOG_INFO,
                'revolut.log',
            );
        } elseif (in_array($state, ['FAILED', 'CANCELLED'], true) && $order->canCancel()) {
            $order->cancel()->save();
            Mage::log(
                "Revolut cron: cancelled order #{$order->getIncrementId()} (state={$state})",
                Mage::LOG_INFO,
                'revolut.log',
            );
        }
    }
}
