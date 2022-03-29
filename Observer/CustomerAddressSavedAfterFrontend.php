<?php
declare(strict_types=1);

namespace Autopilot\AP3Connector\Observer;

use Autopilot\AP3Connector\Helper\Data;
use Autopilot\AP3Connector\Helper\To;
use Autopilot\AP3Connector\Logger\AutopilotLoggerInterface;
use Autopilot\AP3Connector\Api\AutopilotClientInterface;
use Autopilot\AP3Connector\Api\ScopeManagerInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Model\ResourceModel\CustomerRepository;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Exception;

class CustomerAddressSavedAfterFrontend implements ObserverInterface
{
    private AutopilotLoggerInterface $logger;
    private ScopeManagerInterface $scopeManager;
    private AutopilotClientInterface $autopilotClient;
    private CustomerRepository $customerRepository;
    private Data $helper;

    public function __construct(
        AutopilotLoggerInterface $logger,
        ScopeManagerInterface $scopeManager,
        AutopilotClientInterface $autopilotClient,
        CustomerRepository $customerRepository,
        Data $helper
    ) {
        $this->logger = $logger;
        $this->scopeManager = $scopeManager;
        $this->autopilotClient = $autopilotClient;
        $this->customerRepository = $customerRepository;
        $this->helper = $helper;
    }

    public function execute(Observer $observer)
    {
        try {
            $event = $observer->getEvent();
            /** @var AddressInterface $address */
            $address = $event->getData("customer_address");
            if (empty($address)) {
                $this->logger->warn("Customer address was not provided");
                return;
            }
            $customer = $this->customerRepository->getById(To::int($address->getCustomerId()));
            $scopes = $this->scopeManager->getActiveScopes();
            foreach ($scopes as $scope) {
                if (!$this->helper->shouldExportCustomer($scope, $customer)) {
                    continue;
                }
                $this->autopilotClient->importContacts($scope, [$customer]);
            }
        } catch (Exception $e) {
            $this->logger->error($e, 'CustomerAddressSavedAfterFrontend: Failed to export the customer');
        }
    }
}