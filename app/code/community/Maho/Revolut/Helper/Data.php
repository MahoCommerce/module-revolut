<?php

/**
 * Maho
 *
 * @package    Maho_Revolut
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class Maho_Revolut_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const API_URL_LIVE = 'https://merchant.revolut.com';
    public const API_URL_SANDBOX = 'https://sandbox-merchant.revolut.com';

    public const API_VERSION = '2024-09-01';

    protected $_moduleName = 'Maho_Revolut';

    public function isSandbox(?int $storeId = null): bool
    {
        return Mage::getStoreConfigFlag('maho_revolut/credentials/sandbox', $storeId);
    }

    public function isLogEnabled(?int $storeId = null): bool
    {
        return Mage::getStoreConfigFlag('maho_revolut/credentials/debug', $storeId);
    }

    /**
     * Write a line to var/log/revolut.log. Bypasses the global dev/log/active
     * flag when the module's own debug option is on, so users can capture
     * traffic without flipping logging globally.
     */
    public function log(string $message, Monolog\Level|int|null $level = null): void
    {
        Mage::log($message, $level, 'revolut.log', $this->isLogEnabled());
    }

    public function getApiBaseUrl(?int $storeId = null): string
    {
        return $this->isSandbox($storeId) ? self::API_URL_SANDBOX : self::API_URL_LIVE;
    }

    public function getApiKey(?int $storeId = null): string
    {
        // Auto-decrypted by Store::_processConfigValue because the default
        // node in config.xml carries backend_model=adminhtml/.../encrypted.
        return (string) Mage::getStoreConfig('maho_revolut/credentials/api_key', $storeId);
    }

    public function getWebhookSecret(?int $storeId = null): string
    {
        return (string) Mage::getStoreConfig('maho_revolut/credentials/webhook_secret', $storeId);
    }

    public function hasCredentials(?int $storeId = null): bool
    {
        return $this->getApiKey($storeId) !== '';
    }

    /**
     * Convert a decimal amount (e.g. 12.34) to Revolut's minor-unit integer (e.g. 1234).
     */
    public function toMinorUnits(float|string $amount, string $currency): int
    {
        $exponents = [
            'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0,
            'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0,
            'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
            'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        ];
        $exp = $exponents[strtoupper($currency)] ?? 2;
        return (int) round((float) $amount * (10 ** $exp));
    }

    /**
     * Convert a Revolut minor-unit integer back to a decimal amount.
     */
    public function fromMinorUnits(int $amount, string $currency): float
    {
        $exponents = [
            'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0,
            'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0,
            'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
            'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        ];
        $exp = $exponents[strtoupper($currency)] ?? 2;
        return $amount / (10 ** $exp);
    }

    /**
     * Verify a Revolut webhook signature.
     *
     * Revolut signs the payload as `v1.{timestamp}.{rawBody}` with HMAC-SHA256
     * using the per-webhook signing secret, and sends it as `Revolut-Signature: v1=<hex>`
     * with `Revolut-Request-Timestamp: <unix-milliseconds>`. We don't enforce a
     * staleness window -- the HMAC authenticates the payload and the webhook
     * controller is idempotent (only pending orders transition), so a replay is
     * a no-op.
     */
    public function verifyWebhookSignature(
        string $rawBody,
        string $signatureHeader,
        string $timestampHeader,
        string $signingSecret,
    ): bool {
        if ($signingSecret === '' || $signatureHeader === '' || $timestampHeader === '') {
            return false;
        }

        $payload = 'v1.' . $timestampHeader . '.' . $rawBody;
        $expected = 'v1=' . hash_hmac('sha256', $payload, $signingSecret);

        // Header may contain multiple comma-separated signatures (rotation overlap).
        foreach (array_map('trim', explode(',', $signatureHeader)) as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }
        return false;
    }
}
