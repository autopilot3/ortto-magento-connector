<?php
declare(strict_types=1);


namespace Ortto\Connector\Block;

use Ortto\Connector\Api\ConfigScopeInterface;
use Ortto\Connector\Api\Data\TrackingDataInterface as TD;
use Ortto\Connector\Api\TrackDataProviderInterface;
use Ortto\Connector\Helper\Config;
use Ortto\Connector\Logger\Logger;
use Ortto\Connector\Model\Api\CartDataFactory;
use Exception;

use Magento\Checkout\Model\Session;
use Magento\Framework\Serialize\JsonConverter;
use Magento\Framework\View\Element\Template;

class Checkout extends Template
{
    private TrackDataProviderInterface $trackDataProvider;
    private Logger $logger;
    private Session $session;
    private CartDataFactory $cartDataFactory;

    public function __construct(
        Template\Context $context,
        TrackDataProviderInterface $trackDataProvider,
        CartDataFactory $cartDataFactory,
        Logger $logger,
        Session $session,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->trackDataProvider = $trackDataProvider;
        $this->cartDataFactory = $cartDataFactory;
        $this->logger = $logger;
        $this->session = $session;
    }

    /**
     * @param string $event
     * @return array|bool
     */
    public function getCardEvent(string $event)
    {
        try {
            $factory = $this->cartDataFactory->create();
            $factory->load($this->session->getQuote());
            $cart = $factory->toArray();
            if (empty($cart)) {
                return false;
            }

            $trackingData = $this->trackDataProvider->getData();

            $payload = [
                'event' => $event,
                'scope' => [
                    ConfigScopeInterface::ID => $trackingData->getScopeId(),
                    ConfigScopeInterface::TYPE => $trackingData->getScopeType(),
                ],
                'data' => [
                    'cart' => $cart,
                ],
            ];
            return [
                TD::EMAIL => $trackingData->getEmail(),
                TD::PHONE => $trackingData->getPhone(),
                TD::PAYLOAD => JsonConverter::convert($payload),
            ];
        } catch (Exception $e) {
            $this->logger->error($e, "Failed to get cart data");
            return false;
        }
    }
}