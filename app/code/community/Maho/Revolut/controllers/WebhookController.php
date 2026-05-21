<?php

/**
 * Maho
 *
 * @package    Maho_Revolut
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class Maho_Revolut_WebhookController extends Mage_Core_Controller_Front_Action
{
    /**
     * CSRF is enforced via Revolut's signed headers, not the form key.
     */
    #[\Override]
    public function preDispatch(): self
    {
        $this->setFlag('', self::FLAG_NO_PRE_DISPATCH, true);
        return parent::preDispatch();
    }

    /**
     * Receive a webhook event from Revolut.
     *
     * Body shape: `{ event: "ORDER_COMPLETED", order_id: "<uuid>", merchant_order_ext_ref: "<our-increment-id>" }`.
     * We verify the HMAC signature, look up the order, then re-fetch the canonical
     * state from Revolut before updating Maho — never trust the webhook body alone.
     */
    public function indexAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->getResponse()->setHttpResponseCode(405);
            return;
        }

        $body = (string) $this->getRequest()->getRawBody();
        if ($body === '') {
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        /** @var Maho_Revolut_Helper_Data $helper */
        $helper = Mage::helper('maho_revolut');

        try {
            /** @var array<string, mixed> $data */
            $data = (array) Mage::helper('core')->jsonDecode($body);
        } catch (\Throwable $e) {
            $helper->log('Revolut webhook: invalid JSON body', Mage::LOG_WARNING);
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        $event = (string) ($data['event'] ?? '');
        $revolutOrderId = (string) ($data['order_id'] ?? '');
        $extRef = (string) ($data['merchant_order_ext_ref'] ?? '');

        $order = $this->_findOrder($extRef, $revolutOrderId);
        if (!$order) {
            $helper->log(
                "Revolut webhook: no order found for ext_ref={$extRef} revolut_order_id={$revolutOrderId}",
                Mage::LOG_WARNING,
            );
            $this->getResponse()->setHttpResponseCode(404);
            return;
        }

        $storeId = (int) $order->getStoreId();

        $signatureHeader = (string) $this->getRequest()->getHeader('Revolut-Signature');
        $timestampHeader = (string) $this->getRequest()->getHeader('Revolut-Request-Timestamp');
        $signingSecret = $helper->getWebhookSecret($storeId);

        if (!$helper->verifyWebhookSignature($body, $signatureHeader, $timestampHeader, $signingSecret)) {
            $helper->log(
                "Revolut webhook: signature verification failed for order #{$order->getIncrementId()}",
                Mage::LOG_WARNING,
            );
            $this->getResponse()->setHttpResponseCode(401);
            return;
        }

        try {
            $this->_applyEvent($order, $event, $revolutOrderId);
            $this->getResponse()->setHttpResponseCode(200);
        } catch (\Throwable $e) {
            Mage::logException($e);
            $this->getResponse()->setHttpResponseCode(500);
        }
    }

    protected function _findOrder(string $extRef, string $revolutOrderId): ?Mage_Sales_Model_Order
    {
        if ($extRef !== '') {
            /** @var Mage_Sales_Model_Order $order */
            $order = Mage::getModel('sales/order')->loadByIncrementId($extRef);
            if ($order->getId()) {
                $payment = $order->getPayment();
                if ($payment && (string) $payment->getAdditionalInformation('revolut_order_id') === $revolutOrderId) {
                    return $order;
                }
            }
        }
        return null;
    }

    /**
     * Re-fetch the order from Revolut and apply state changes to Maho.
     */
    protected function _applyEvent(Mage_Sales_Model_Order $order, string $event, string $revolutOrderId): void
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }

        $storeId = (int) $order->getStoreId();
        /** @var Maho_Revolut_Model_Api $api */
        $api = Mage::getModel('maho_revolut/api', ['store_id' => $storeId]);
        $remote = $api->retrieveOrder($revolutOrderId);

        $state = (string) ($remote['state'] ?? '');
        /** @var Maho_Revolut_Helper_Data $helper */
        $helper = Mage::helper('maho_revolut');
        $currency = (string) ($remote['currency'] ?? $order->getBaseCurrencyCode());
        $amountMinor = (int) ($remote['amount'] ?? $payment->getAdditionalInformation('revolut_amount'));
        $amount = $helper->fromMinorUnits($amountMinor, $currency);

        switch ($state) {
            case 'COMPLETED':
                if ($order->getState() === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                    $payment->registerCaptureNotification($amount);
                    $order->save();
                    $helper->log("Revolut webhook: captured order #{$order->getIncrementId()} ({$event})", Mage::LOG_INFO);
                }
                break;
            case 'AUTHORISED':
                if ($order->getState() === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                    $payment->registerAuthorizationNotification($amount);
                    $order->save();
                    $helper->log("Revolut webhook: authorised order #{$order->getIncrementId()} ({$event})", Mage::LOG_INFO);
                }
                break;
            case 'FAILED':
            case 'CANCELLED':
                if ($order->canCancel()) {
                    $order->cancel()->save();
                    $helper->log("Revolut webhook: cancelled order #{$order->getIncrementId()} ({$event})", Mage::LOG_INFO);
                }
                break;
            default:
                $helper->log("Revolut webhook: ignoring state={$state} for order #{$order->getIncrementId()}", Mage::LOG_INFO);
        }
    }
}
