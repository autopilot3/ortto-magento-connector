<?php
declare(strict_types=1);


namespace Autopilot\AP3Connector\Service;

use Autopilot\AP3Connector\Api\ConfigurationReaderInterface;
use Autopilot\AP3Connector\Api\ImageIdInterface;
use Autopilot\AP3Connector\Api\SyncCategoryInterface;
use Autopilot\AP3Connector\Helper\Config;
use Autopilot\AP3Connector\Helper\To;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class ConfigurationReader implements ConfigurationReaderInterface
{

    private EncryptorInterface $encryptor;
    private ScopeConfigInterface $scopeConfig;

    private string $apiKey;

    public function __construct(EncryptorInterface $encryptor, ScopeConfigInterface $scopeConfig)
    {
        $this->encryptor = $encryptor;
        $this->scopeConfig = $scopeConfig;
        $this->apiKey = '';
    }

    /**
     * @inheirtDoc
     */
    public function isActive(string $scopeType, int $scopeId): bool
    {
        return To::bool($this->scopeConfig->getValue(Config::XML_PATH_ACTIVE, $scopeType, $scopeId));
    }

    /**
     * @inheirtDoc
     */
    public function getAPIKey(string $scopeType, int $scopeId): string
    {
        if ($this->apiKey !== '') {
            return $this->apiKey;
        }
        $encrypted = trim((string)$this->scopeConfig->getValue(Config::XML_PATH_API_KEY, $scopeType, $scopeId));
        if (empty($encrypted)) {
            return "";
        }
        $this->apiKey = $this->encryptor->decrypt($encrypted);
        return $this->apiKey;
    }

    /**
     * @inheirtDoc
     */
    public function isAutoSyncEnabled(string $scopeType, int $scopeId, string $category): bool
    {
        switch ($category) {
            case SyncCategoryInterface::CUSTOMER:
                return To::bool($this->scopeConfig->getValue(
                    Config::XML_PATH_SYNC_CUSTOMER_AUTO_ENABLED,
                    $scopeType,
                    $scopeId
                ));
            case SyncCategoryInterface::ORDER:
                return To::bool($this->scopeConfig->getValue(
                    Config::XML_PATH_SYNC_ORDER_AUTO_ENABLED,
                    $scopeType,
                    $scopeId
                ));
            default:
                return false;
        }
    }

    /**
     * @inheirtDoc
     */
    public function isNonSubscribedCustomerSyncEnabled(string $scopeType, int $scopeId): bool
    {
        return To::bool($this->scopeConfig->getValue(
            Config::XML_PATH_SYNC_CUSTOMER_NON_SUBSCRIBED_ENABLED,
            $scopeType,
            $scopeId
        ));
    }

    /**
     * @inheirtDoc
     */
    public function isAnonymousOrderSyncEnabled(string $scopeType, int $scopeId): bool
    {
        return To::bool($this->scopeConfig->getValue(
            Config::XML_PATH_SYNC_ANONYMOUS_ORDERS_ENABLED,
            $scopeType,
            $scopeId
        ));
    }

    /**
     * @inheirtDoc
     */
    public function getPlaceholderImages(string $scopeType, int $scopeId): array
    {
        return [
            ImageIdInterface::IMAGE => $this->scopeConfig->getValue(
                Config::XML_PATH_IMAGE_PLACE_HOLDER,
                $scopeType,
                $scopeId
            ),
            ImageIdInterface::SMALL => $this->scopeConfig->getValue(
                Config::XML_PATH_SMALL_IMAGE_PLACE_HOLDER,
                $scopeType,
                $scopeId
            ),
            ImageIdInterface::SWATCH => $this->scopeConfig->getValue(
                Config::XML_PATH_SWATCH_IMAGE_PLACE_HOLDER,
                $scopeType,
                $scopeId
            ),
            ImageIdInterface::THUMBNAIL => $this->scopeConfig->getValue(
                Config::XML_PATH_THUMBNAIL_IMAGE_PLACE_HOLDER,
                $scopeType,
                $scopeId
            ),
        ];
    }
}