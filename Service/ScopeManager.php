<?php
declare(strict_types=1);

namespace Ortto\Connector\Service;

use Ortto\Connector\Api\ConfigScopeInterface;
use Ortto\Connector\Api\ConfigurationReaderInterface;
use Ortto\Connector\Api\ScopeManagerInterface;
use Ortto\Connector\Helper\To;
use Ortto\Connector\Logger\OrttoLoggerInterface;
use Ortto\Connector\Model\Scope;
use Ortto\Connector\Model\ScopeFactory;
use Exception;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\InvalidArgumentException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

class ScopeManager implements ScopeManagerInterface
{
    private StoreManagerInterface $storeManager;
    private OrttoLoggerInterface $logger;
    private ConfigurationReaderInterface $configReader;
    private ScopeFactory $scopeFactory;
    private RequestInterface $request;
    private UrlInterface $urlInterface;

    public function __construct(
        StoreManagerInterface $storeManager,
        OrttoLoggerInterface $logger,
        ConfigurationReaderInterface $configReader,
        ScopeFactory $scopeFactory,
        RequestInterface $request,
        UrlInterface $urlInterface
    ) {
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->configReader = $configReader;
        $this->scopeFactory = $scopeFactory;
        $this->request = $request;
        $this->urlInterface = $urlInterface;
    }

    public function getActiveScopes(): array
    {
        $result = [];
        $websites = $this->storeManager->getWebsites();
        foreach ($websites as $website) {
            try {
                $scope = $this->initialiseScope(ScopeInterface::SCOPE_WEBSITE, To::int($website->getId()), $websites);
                if ($scope->isExplicitlyConnected()) {
                    $result[] = $scope;
                }
            } catch (Exception $e) {
                $this->logger->error($e, "Failed to initialise website scope");
            }
        }

        $stores = $this->storeManager->getStores();
        foreach ($stores as $store) {
            try {
                $scope = $this->initialiseScope(
                    ScopeInterface::SCOPE_STORE,
                    To::int($store->getId()),
                    $websites,
                    $stores
                );
                if ($scope->isExplicitlyConnected()) {
                    $result[] = $scope;
                }
            } catch (Exception $e) {
                $this->logger->error($e, "Failed to initialise store scope");
            }
        }

        return $result;
    }

    public function getCurrentConfigurationScope(string $scopeType = '', ?int $scopeId = null): Scope
    {
        if (empty($scopeType)) {
            $scopeType = ScopeInterface::SCOPE_WEBSITE;
        }
        try {
            if ($scopeType === ScopeInterface::SCOPE_WEBSITE) {
                if (empty($scopeId)) {
                    $websiteId = To::int($this->request->getParam($scopeType, -1));
                } else {
                    $websiteId = $scopeId;
                }
                if ($websiteId != -1) {
                    return $this->initialiseScope($scopeType, $websiteId);
                }
            }

            $scopeType = ScopeInterface::SCOPE_STORE;
            if (empty($scopeId)) {
                $storeId = To::int($this->request->getParam($scopeType, -1));
            } else {
                $storeId = $scopeId;
            }
            if ($storeId != -1) {
                return $this->initialiseScope($scopeType, $storeId);
            }
        } catch (Exception $e) {
            $this->logger->error($e, "Failed to get current configuration scope");
        }
        return $this->scopeFactory->create();
    }

    /**
     * @param string $type
     * @param int $id
     * @param WebsiteInterface[] $websites
     * @param StoreInterface[] $stores
     * @return ConfigScopeInterface
     * @throws InvalidArgumentException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function initialiseScope(
        string $type,
        int $id,
        array $websites = [],
        array $stores = []
    ): ConfigScopeInterface {
        $scope = $this->scopeFactory->create();
        $scope->setId($id);
        $scope->setType($type);
        if (empty($stores)) {
            $stores = $this->storeManager->getStores();
        }
        switch ($type) {
            case ScopeInterface::SCOPE_WEBSITE:
                $scope->setWebsiteId($id);
                $website = $this->storeManager->getWebsite($id);
                $scope->setName($website->getName());
                $scope->setCode($website->getCode());
                $scope->setIsExplicitlyConnected(!empty($this->configReader->getAPIKey($type, $id)));
                $scope->setBaseURL((string)$this->urlInterface->getBaseUrl());
                foreach ($stores as $store) {
                    if (To::int($store->getWebsiteId()) === $id) {
                        $scope->addStoreId(To::int($store->getId()));
                    }
                }
                break;
            case ScopeInterface::SCOPE_STORE:
                /** @var Store $store */
                $store = $this->storeManager->getStore($id);
                $websiteId = To::int($store->getWebsiteId());
                $scope->setWebsiteId($websiteId);
                $websiteAPIKey = $this->configReader->getAPIKey(ScopeInterface::SCOPE_WEBSITE, $websiteId);
                $storeAPIKey = $this->configReader->getAPIKey($type, $id);
                $scope->setIsExplicitlyConnected($websiteAPIKey !== $storeAPIKey && !empty($storeAPIKey));
                $scope->setName($store->getName());
                $scope->setBaseURL((string)$store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true));
                $scope->setCode($store->getCode());
                $scope->addStoreId($id);
                $scope->setParent($this->initialiseScope(ScopeInterface::SCOPE_WEBSITE, $websiteId));
                break;
            default:
                throw new InvalidArgumentException(new Phrase("Unsupported scope type $type"));
        }

        return $scope;
    }
}
