<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Revolut
 */

declare(strict_types=1);

class Maho_Revolut_Model_Api
{
    protected ?int $_storeId = null;
    protected ?string $_explicitApiKey = null;
    protected ?bool $_explicitSandbox = null;

    public function __construct(array $args = [])
    {
        if (isset($args['store_id'])) {
            $this->_storeId = (int) $args['store_id'];
        }
    }

    public function setStoreId(int $storeId): self
    {
        $this->_storeId = $storeId;
        return $this;
    }

    /**
     * Override credentials read from store config. Used by the admin "Register
     * Webhook" button so a user can register a webhook before saving the form.
     */
    public function setExplicitCredentials(#[\SensitiveParameter]
        string $apiKey, bool $sandbox): self
    {
        $this->_explicitApiKey = $apiKey;
        $this->_explicitSandbox = $sandbox;
        return $this;
    }

    protected function _getHelper(): Maho_Revolut_Helper_Data
    {
        /** @var Maho_Revolut_Helper_Data */
        return Mage::helper('maho_revolut');
    }

    /**
     * Create a Revolut order. Returns the parsed response (including `id`,
     * `token`, `state`, `checkout_url`).
     *
     * @return array<string, mixed>
     * @throws Mage_Core_Exception
     */
    public function createOrder(array $data): array
    {
        $response = $this->_request('POST', '/api/orders', $data);
        if (!isset($response['id'])) {
            Mage::throwException(
                $this->_getHelper()->__('Revolut: Failed to create order. Response: %s', json_encode($response)),
            );
        }
        return $response;
    }

    /**
     * @return array<string, mixed>
     * @throws Mage_Core_Exception
     */
    public function retrieveOrder(string $orderId): array
    {
        return $this->_request('GET', '/api/orders/' . rawurlencode($orderId));
    }

    /**
     * Capture an authorized Revolut order. Optional partial amount in minor units.
     *
     * @return array<string, mixed>
     * @throws Mage_Core_Exception
     */
    public function captureOrder(string $orderId, ?int $amountMinor = null): array
    {
        $body = $amountMinor === null ? [] : ['amount' => $amountMinor];
        return $this->_request('POST', '/api/orders/' . rawurlencode($orderId) . '/capture', $body);
    }

    /**
     * @return array<string, mixed>
     * @throws Mage_Core_Exception
     */
    public function cancelOrder(string $orderId): array
    {
        return $this->_request('POST', '/api/orders/' . rawurlencode($orderId) . '/cancel');
    }

    /**
     * Create a webhook. Returns the parsed response, which includes the
     * `id` and the `signing_secret` (returned only at creation).
     *
     * @param list<string> $events
     * @return array<string, mixed>
     * @throws Mage_Core_Exception
     */
    public function createWebhook(string $url, array $events): array
    {
        $response = $this->_request('POST', '/api/1.0/webhooks', [
            'url' => $url,
            'events' => array_values($events),
        ]);
        if (!isset($response['signing_secret']) || !isset($response['id'])) {
            Mage::throwException(
                $this->_getHelper()->__('Revolut: webhook created but response missing id/signing_secret: %s', json_encode($response)),
            );
        }
        return $response;
    }

    public function refundOrder(string $orderId, int $amountMinor, string $currency, ?string $reason = null): array
    {
        $body = [
            'amount' => $amountMinor,
            'currency' => strtoupper($currency),
        ];
        if ($reason !== null && $reason !== '') {
            $body['merchant_order_ext_ref'] = $reason;
        }
        return $this->_request('POST', '/api/orders/' . rawurlencode($orderId) . '/refund', $body);
    }

    /**
     * Perform an HTTP request to the Revolut Merchant API.
     *
     * @return array<string, mixed>
     * @throws Mage_Core_Exception
     */
    protected function _request(string $method, string $endpoint, array $body = []): array
    {
        $helper = $this->_getHelper();
        $sandbox = $this->_explicitSandbox ?? $helper->isSandbox($this->_storeId);
        $baseUrl = $sandbox ? Maho_Revolut_Helper_Data::API_URL_SANDBOX : Maho_Revolut_Helper_Data::API_URL_LIVE;
        $url = $baseUrl . $endpoint;

        $apiKey = $this->_explicitApiKey ?? $helper->getApiKey($this->_storeId);
        if ($apiKey === '') {
            Mage::throwException($helper->__('Revolut API error: %s', $helper->__('Merchant API key is not configured.')));
        }

        $options = [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Revolut-Api-Version' => Maho_Revolut_Helper_Data::API_VERSION,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];

        if ($body && $method !== 'GET') {
            $options['body'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($helper->isLogEnabled($this->_storeId)) {
            $helper->log("--> {$method} {$endpoint} " . ($options['body'] ?? ''), Mage::LOG_DEBUG);
        }

        try {
            $client = \Symfony\Component\HttpClient\HttpClient::create();
            $response = $client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
            /** @var array<string, mixed> $result */
            $result = $content === '' ? [] : (array) Mage::helper('core')->jsonDecode($content);

            if ($helper->isLogEnabled($this->_storeId)) {
                $helper->log("<-- {$method} {$endpoint} {$statusCode} {$content}", Mage::LOG_DEBUG);
            }

            if ($statusCode >= 400) {
                $errorMsg = $result['message'] ?? $result['error'] ?? "HTTP {$statusCode}";
                $helper->log(
                    "Revolut API error: {$method} {$endpoint} -> {$statusCode}: {$content}",
                    Mage::LOG_ERROR,
                );
                Mage::throwException($helper->__('Revolut API error: %s', (string) $errorMsg));
            }

            return $result;
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            $helper->log(
                "Revolut API transport error: {$method} {$endpoint} -> {$e->getMessage()}",
                Mage::LOG_ERROR,
            );
            Mage::throwException($helper->__('Revolut connection error: %s', $e->getMessage()));
        }
    }
}
