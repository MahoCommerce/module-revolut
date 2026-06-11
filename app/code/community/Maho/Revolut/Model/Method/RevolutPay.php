<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Revolut
 */

declare(strict_types=1);

class Maho_Revolut_Model_Method_RevolutPay extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'revolut_pay';

    protected $_formBlockType = 'maho_revolut/form';
    protected $_infoBlockType = 'maho_revolut/info';

    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = true;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;
    protected $_isInitializeNeeded = true;
    protected $_canFetchTransactionInfo = true;

    protected ?Maho_Revolut_Model_Api $_api = null;

    #[\Override]
    public function isAvailable($quote = null): bool
    {
        if (!$this->_getRevolutHelper()->hasCredentials($quote?->getStoreId())) {
            return false;
        }
        return parent::isAvailable($quote);
    }

    public function getOrderPlaceRedirectUrl(): string
    {
        return Mage::getUrl('revolut/payment/redirect', ['_secure' => true]);
    }

    /**
     * @param \Maho\DataObject $stateObject
     */
    #[\Override]
    public function initialize($paymentAction, $stateObject): self
    {
        $stateObject->setData('state', Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('status', 'pending_payment');
        $stateObject->setData('is_notified', false);
        return $this;
    }

    /**
     * Create a Revolut order and return the hosted checkout URL.
     *
     * @return array{order_id: string, token: string, checkout_url: string}
     */
    public function createRevolutOrder(Mage_Sales_Model_Order $order): array
    {
        $helper = $this->_getRevolutHelper();
        $storeId = (int) $order->getStoreId();
        $payment = $order->getPayment();

        if (!$payment) {
            Mage::throwException($helper->__('No payment found for order.'));
        }

        $currency = (string) $order->getBaseCurrencyCode();
        $amountMinor = $helper->toMinorUnits((float) $order->getBaseGrandTotal(), $currency);

        $captureMode = $this->getConfigData('payment_action', $storeId) === self::ACTION_AUTHORIZE
            ? 'manual'
            : 'automatic';

        // Per Revolut's hosted-checkout guide, only amount/currency are required;
        // description / customer.email / redirect_url / merchant_order_ext_ref are
        // the documented useful optionals. We deliberately don't send customer.full_name
        // or a shipping payload here -- Revolut collects buyer details on the hosted
        // page and the official Magento 2 module follows the same minimal-create pattern.
        $data = array_filter([
            'amount' => $amountMinor,
            'currency' => strtoupper($currency),
            'capture_mode' => $captureMode,
            'merchant_order_ext_ref' => $order->getIncrementId(),
            'description' => $helper->__('Order #%s', $order->getIncrementId()),
            'customer' => array_filter([
                'email' => $order->getCustomerEmail(),
            ]),
            'redirect_url' => Mage::getUrl('revolut/payment/success', ['_secure' => true]),
        ]);

        $result = $this->_getApi($storeId)->createOrder($data);

        $payment->setAdditionalInformation('revolut_order_id', $result['id']);
        $payment->setAdditionalInformation('revolut_public_id', $result['token'] ?? '');
        $payment->setAdditionalInformation('revolut_amount', $amountMinor);
        $payment->setAdditionalInformation('revolut_currency', strtoupper($currency));
        $payment->setAdditionalInformation('revolut_capture_mode', $captureMode);
        $payment->save();

        return [
            'order_id' => (string) $result['id'],
            'token' => (string) ($result['token'] ?? ''),
            'checkout_url' => (string) ($result['checkout_url'] ?? ''),
        ];
    }

    /**
     * Capture is handled by the webhook (which calls registerCaptureNotification).
     * For manual-capture flows (admin invoice), call Revolut's capture endpoint.
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     */
    #[\Override]
    public function capture(\Maho\DataObject $payment, $amount): self
    {
        $revolutOrderId = (string) $payment->getAdditionalInformation('revolut_order_id');
        if ($revolutOrderId === '') {
            return $this;
        }

        $captureMode = (string) $payment->getAdditionalInformation('revolut_capture_mode');
        if ($captureMode === 'manual') {
            $helper = $this->_getRevolutHelper();
            $order = $payment->getOrder();
            $storeId = (int) $order->getStoreId();
            $currency = (string) $order->getBaseCurrencyCode();
            $amountMinor = $helper->toMinorUnits((float) $amount, $currency);
            $this->_getApi($storeId)->captureOrder($revolutOrderId, $amountMinor);
        }

        $payment->setTransactionId($revolutOrderId);
        $payment->setIsTransactionClosed(true);
        return $this;
    }

    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     */
    #[\Override]
    public function refund(\Maho\DataObject $payment, $amount): self
    {
        $helper = $this->_getRevolutHelper();
        $order = $payment->getOrder();
        $storeId = (int) $order->getStoreId();
        $revolutOrderId = (string) $payment->getAdditionalInformation('revolut_order_id');

        if ($revolutOrderId === '') {
            Mage::throwException($helper->__('Cannot refund: missing Revolut order data.'));
        }

        $currency = (string) $order->getBaseCurrencyCode();
        $amountMinor = $helper->toMinorUnits((float) $amount, $currency);

        $this->_getApi($storeId)->refundOrder(
            $revolutOrderId,
            $amountMinor,
            $currency,
            $helper->__('Refund for order #%s', $order->getIncrementId()),
        );

        $payment->setTransactionId($revolutOrderId . '-refund-' . bin2hex(random_bytes(4)));
        $payment->setIsTransactionClosed(false);
        return $this;
    }

    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     */
    #[\Override]
    public function void(\Maho\DataObject $payment): self
    {
        $helper = $this->_getRevolutHelper();
        $revolutOrderId = (string) $payment->getAdditionalInformation('revolut_order_id');
        if ($revolutOrderId === '') {
            Mage::throwException($helper->__('Cannot void: missing Revolut order id.'));
        }
        $storeId = (int) $payment->getOrder()->getStoreId();
        $this->_getApi($storeId)->cancelOrder($revolutOrderId);
        $payment->setIsTransactionClosed(true);
        return $this;
    }

    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     */
    #[\Override]
    public function cancel(\Maho\DataObject $payment): self
    {
        return $this->void($payment);
    }

    #[\Override]
    public function fetchTransactionInfo(\Mage_Payment_Model_Info $payment, $transactionId): array
    {
        $revolutOrderId = (string) $payment->getAdditionalInformation('revolut_order_id');
        if ($revolutOrderId === '') {
            return [];
        }
        $storeId = (int) $payment->getOrder()->getStoreId();
        try {
            return $this->_getApi($storeId)->retrieveOrder($revolutOrderId);
        } catch (\Throwable $e) {
            Mage::logException($e);
            return [];
        }
    }

    protected function _getRevolutHelper(): Maho_Revolut_Helper_Data
    {
        /** @var Maho_Revolut_Helper_Data */
        return Mage::helper('maho_revolut');
    }

    protected function _getApi(int $storeId): Maho_Revolut_Model_Api
    {
        if ($this->_api === null) {
            /** @var Maho_Revolut_Model_Api $api */
            $api = Mage::getModel('maho_revolut/api', ['store_id' => $storeId]);
            $this->_api = $api;
        }
        return $this->_api;
    }
}
