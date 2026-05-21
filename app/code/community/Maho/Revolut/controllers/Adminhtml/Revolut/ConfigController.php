<?php

/**
 * Maho
 *
 * @package    Maho_Revolut
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class Maho_Revolut_Adminhtml_Revolut_ConfigController extends Mage_Adminhtml_Controller_Action
{
    public const ADMIN_RESOURCE = 'system/config/payment';

    public const WEBHOOK_EVENT_TYPES = [
        'ORDER_COMPLETED',
        'ORDER_AUTHORISED',
        'ORDER_PAYMENT_DECLINED',
        'ORDER_PAYMENT_FAILED',
    ];

    public function registerWebhookAction(): void
    {
        $result = ['success' => false, 'message' => ''];

        try {
            $storeId = $this->_resolveStoreId();
            [$apiKey, $sandbox] = $this->_resolveCredentials($storeId);

            if ($apiKey === '') {
                $result['message'] = $this->__('Merchant API key is empty. Enter it in the field above first.');
                $this->_respondJson($result);
                return;
            }

            $webhookUrl = Mage::getUrl('revolut/webhook', [
                '_secure' => true,
                '_nosid' => true,
            ]);

            /** @var Maho_Revolut_Model_Api $api */
            $api = Mage::getModel('maho_revolut/api');
            $api->setExplicitCredentials($apiKey, $sandbox);
            $response = $api->createWebhook($webhookUrl, self::WEBHOOK_EVENT_TYPES);

            $webhookId = (string) $response['id'];
            $signingSecret = (string) $response['signing_secret'];

            $configScope = $this->_resolveConfigScope($storeId);
            $encryptedSecret = (string) Mage::helper('core')->encrypt($signingSecret);

            Mage::getModel('core/config')->saveConfig(
                'maho_revolut/credentials/webhook_id',
                $webhookId,
                $configScope['scope'],
                $configScope['scope_id'],
            );
            Mage::getModel('core/config')->saveConfig(
                'maho_revolut/credentials/webhook_secret',
                $encryptedSecret,
                $configScope['scope'],
                $configScope['scope_id'],
            );
            $appConfig = Mage::getConfig();
            if ($appConfig !== null) {
                $appConfig->reinit();
            }

            $result['success'] = true;
            $result['message'] = $this->__('Webhook registered (ID %s). Signing secret saved.', $webhookId);
            $result['webhook_id'] = $webhookId;
        } catch (\Throwable $e) {
            $result['message'] = $this->__('Webhook registration failed: %s', $e->getMessage());
            Mage::logException($e);
        }

        $this->_respondJson($result);
    }

    /**
     * @return array{0: string, 1: bool}
     */
    protected function _resolveCredentials(?int $storeId): array
    {
        /** @var Maho_Revolut_Helper_Data $helper */
        $helper = Mage::helper('maho_revolut');

        $apiKey = (string) $this->getRequest()->getParam('api_key');
        // Obscure fields render as '******' until edited — fall back to saved value.
        if ($apiKey === '' || preg_match('/^\*+$/', $apiKey)) {
            $apiKey = $helper->getApiKey($storeId);
        }

        $sandboxParam = $this->getRequest()->getParam('sandbox');
        if ($sandboxParam === null || $sandboxParam === '') {
            $sandbox = $helper->isSandbox($storeId);
        } else {
            $sandbox = (string) $sandboxParam === '1';
        }

        return [$apiKey, $sandbox];
    }

    protected function _resolveStoreId(): ?int
    {
        $storeCode = $this->getRequest()->getParam('store');
        if ($storeCode) {
            $store = Mage::app()->getStore($storeCode);
            if ($store !== null) {
                return (int) $store->getId();
            }
        }

        $websiteCode = $this->getRequest()->getParam('website');
        if ($websiteCode) {
            $website = Mage::app()->getWebsite($websiteCode);
            $defaultStore = $website !== null ? $website->getDefaultStore() : null;
            if ($defaultStore !== null) {
                return (int) $defaultStore->getId();
            }
        }

        return null;
    }

    /**
     * @return array{scope: string, scope_id: int}
     */
    protected function _resolveConfigScope(?int $storeId): array
    {
        $websiteCode = $this->getRequest()->getParam('website');
        if ($websiteCode) {
            $website = Mage::app()->getWebsite($websiteCode);
            if ($website !== null) {
                return ['scope' => 'websites', 'scope_id' => (int) $website->getId()];
            }
        }
        if ($storeId !== null) {
            return ['scope' => 'stores', 'scope_id' => $storeId];
        }
        return ['scope' => 'default', 'scope_id' => 0];
    }

    protected function _respondJson(array $result): void
    {
        $this->getResponse()->setHeader('Content-Type', 'application/json');
        $this->getResponse()->setBody((string) Mage::helper('core')->jsonEncode($result));
    }
}
