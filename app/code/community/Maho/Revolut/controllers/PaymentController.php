<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Revolut
 */

declare(strict_types=1);

class Maho_Revolut_PaymentController extends Mage_Core_Controller_Front_Action
{
    /**
     * Create the Revolut order and redirect the customer to Revolut's hosted page.
     */
    #[Maho\Config\Route('/revolut/payment/redirect')]
    public function redirectAction(): void
    {
        $session = Mage::getSingleton('checkout/session');
        $orderIncrementId = $session->getLastRealOrderId();

        if (!$orderIncrementId) {
            $this->_redirect('checkout/cart');
            return;
        }

        $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
        if (!$order->getId()) {
            $this->_redirect('checkout/cart');
            return;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            $this->_redirect('checkout/cart');
            return;
        }

        try {
            /** @var Maho_Revolut_Model_Method_RevolutPay $method */
            $method = $payment->getMethodInstance();
            $result = $method->createRevolutOrder($order);

            $session->setRevolutQuoteId($session->getQuoteId());
            $session->unsQuoteId();

            if ($result['checkout_url'] === '') {
                Mage::throwException(
                    Mage::helper('maho_revolut')->__('Revolut API error: %s', 'missing checkout_url in response'),
                );
            }

            $this->getResponse()->setRedirect($result['checkout_url']);
        } catch (\Throwable $e) {
            Mage::logException($e);
            Mage::getSingleton('core/session')->addError(
                Mage::helper('maho_revolut')->__('Unable to initialize payment. Please try again.'),
            );
            $this->_restoreCart($order);
            $this->_redirect('checkout/cart');
        }
    }

    /**
     * Customer returns from Revolut. The webhook is authoritative for capture,
     * but we re-fetch the order to handle the case where the webhook hasn't
     * landed yet.
     */
    #[Maho\Config\Route('/revolut/payment/success')]
    public function successAction(): void
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getRevolutQuoteId(true));

        $orderIncrementId = $session->getLastRealOrderId();
        if ($orderIncrementId) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
            if ($order->getId()) {
                $this->_syncOrderState($order);
                $quote = $session->getQuote();
                if ($quote->getId()) {
                    $quote->setIsActive(0)->save();
                }
            }
        }

        $this->_redirect('checkout/onepage/success', ['_secure' => true]);
    }

    /**
     * Customer cancelled on Revolut's side.
     */
    #[Maho\Config\Route('/revolut/payment/cancel')]
    public function cancelAction(): void
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getRevolutQuoteId(true));

        $orderIncrementId = $session->getLastRealOrderId();
        if ($orderIncrementId) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
            if ($order->getId()) {
                $this->_restoreCart($order);
            }
        }

        $this->_redirect('checkout/cart');
    }

    /**
     * Synchronously check Revolut order state and capture if completed.
     * Called from successAction in case the webhook is delayed.
     */
    protected function _syncOrderState(Mage_Sales_Model_Order $order): void
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }
        $revolutOrderId = (string) $payment->getAdditionalInformation('revolut_order_id');
        if ($revolutOrderId === '') {
            return;
        }
        if ($order->getState() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
            return;
        }

        try {
            /** @var Maho_Revolut_Model_Api $api */
            $api = Mage::getModel('maho_revolut/api', ['store_id' => (int) $order->getStoreId()]);
            $result = $api->retrieveOrder($revolutOrderId);
            $state = (string) ($result['state'] ?? '');

            if (in_array($state, ['COMPLETED', 'AUTHORISED'], true)) {
                /** @var Maho_Revolut_Helper_Data $helper */
                $helper = Mage::helper('maho_revolut');
                $currency = (string) ($result['currency'] ?? $order->getBaseCurrencyCode());
                $amountMinor = (int) ($result['amount'] ?? $payment->getAdditionalInformation('revolut_amount'));
                $amount = $helper->fromMinorUnits($amountMinor, $currency);

                if ($state === 'AUTHORISED') {
                    $payment->registerAuthorizationNotification($amount);
                } else {
                    $payment->registerCaptureNotification($amount);
                }
                $order->save();
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    protected function _restoreCart(Mage_Sales_Model_Order $order): void
    {
        if ($order->canCancel()) {
            $order->cancel()->save();
        }
        $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
        if ($quote->getId()) {
            $quote->setIsActive(1)->setReservedOrderId('')->save();
            Mage::getSingleton('checkout/session')->replaceQuote($quote);
        }
    }
}
