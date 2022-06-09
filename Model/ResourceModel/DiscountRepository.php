<?php
declare(strict_types=1);

namespace Ortto\Connector\Model\ResourceModel;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Exception;
use Magento\SalesRule\Api\CouponRepositoryInterface;
use Magento\SalesRule\Api\Data\ConditionInterface;
use Magento\SalesRule\Api\Data\CouponInterface;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\SalesRule\Api\Data\ConditionInterfaceFactory;
use Magento\SalesRule\Model\CouponGenerator;
use Ortto\Connector\Api\Data\DiscountInterface;
use Ortto\Connector\Api\Data\PriceRuleResponseInterface;
use Ortto\Connector\Model\Data\DiscountFactory;
use Ortto\Connector\Model\Data\PriceRuleResponseFactory;
use Ortto\Connector\Api\Data\PriceRuleInterface;
use Ortto\Connector\Api\DiscountRepositoryInterface;
use Ortto\Connector\Helper\Config;
use Ortto\Connector\Helper\Data;
use Ortto\Connector\Helper\To;
use Ortto\Connector\Logger\OrttoLoggerInterface;
use Magento\SalesRule\Api\Data\RuleInterfaceFactory;

class DiscountRepository implements DiscountRepositoryInterface
{
    const NO_FREE_SHIPPING = 0;
    const APPLY_FREE_SHIPPING_TO_MATCHING_ITEMS_ONLY = 1;
    const APPLY_FREE_SHIPPING_TO_CART_WITH_MATCHING_ITEMS = 2;

    private OrttoLoggerInterface $logger;
    private RuleRepositoryInterface $ruleRepository;
    private CouponRepositoryInterface $couponRepository;
    private RuleInterfaceFactory $rule;
    private ConditionInterfaceFactory $conditionFactory;
    private GroupRepositoryInterface $groupRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private CategoryRepositoryInterface $categoryRepository;
    private ProductRepositoryInterface $productRepository;
    private CouponFactory $couponFactory;
    private Data $helper;
    private DiscountFactory $discountFactory;
    private PriceRuleResponseFactory $ruleResponseFactory;
    private CouponGenerator $couponGenerator;

    /**
     * @param OrttoLoggerInterface $logger
     * @param CouponRepositoryInterface $couponRepository
     * @param RuleRepositoryInterface $ruleRepository
     * @param RuleInterfaceFactory $rule
     * @param ConditionInterfaceFactory $conditionFactory
     * @param GroupRepositoryInterface $groupRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param CategoryRepositoryInterface $categoryRepository
     * @param ProductRepositoryInterface $productRepository
     * @param CouponFactory $couponFactory
     * @param DiscountFactory $discountFactory
     * @param PriceRuleResponseFactory $ruleResponseFactory
     * @param CouponGenerator $couponGenerator
     * @param Data $helper
     */
    public function __construct(
        OrttoLoggerInterface $logger,
        CouponRepositoryInterface $couponRepository,
        RuleRepositoryInterface $ruleRepository,
        RuleInterfaceFactory $rule,
        ConditionInterfaceFactory $conditionFactory,
        GroupRepositoryInterface $groupRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CategoryRepositoryInterface $categoryRepository,
        ProductRepositoryInterface $productRepository,
        CouponFactory $couponFactory,
        DiscountFactory $discountFactory,
        PriceRuleResponseFactory $ruleResponseFactory,
        CouponGenerator $couponGenerator,
        Data $helper
    ) {
        $this->logger = $logger;
        $this->ruleRepository = $ruleRepository;
        $this->couponRepository = $couponRepository;
        $this->rule = $rule;
        $this->conditionFactory = $conditionFactory;
        $this->helper = $helper;
        $this->groupRepository = $groupRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->categoryRepository = $categoryRepository;
        $this->productRepository = $productRepository;
        $this->couponFactory = $couponFactory;
        $this->discountFactory = $discountFactory;
        $this->ruleResponseFactory = $ruleResponseFactory;
        $this->couponGenerator = $couponGenerator;
    }

    /**
     * @throws Exception
     */
    public function createPriceRule(PriceRuleInterface $rule): PriceRuleResponseInterface
    {
        $err = $rule->validate();
        if (!empty($err)) {
            throw $this->helper->newHTTPException($err, 400);
        }
        try {
            $newRule = $this->rule->create();
            $this->initialiseRule($newRule, $rule, false);
            $this->setConditions($newRule, $rule, false);
            $this->setCustomerGroups($newRule);
            $priceRule = $this->ruleRepository->save($newRule);
            $response = $this->ruleResponseFactory->create();
            $response->setId(To::int($priceRule->getRuleId()));
            return $response;
        } catch (\Exception $exception) {
            $this->logger->error($exception, "Failed to create new price rule");
            throw $this->helper->newHTTPException(sprintf('Internal Server Error: %s', $exception->getMessage()));
        }
    }

    /**
     * @throws Exception
     * @throws LocalizedException
     */
    public function updatePriceRule(PriceRuleInterface $rule): PriceRuleResponseInterface
    {
        $err = $rule->validate();
        if (!empty($err)) {
            throw $this->helper->newHTTPException($err, 400);
        }
        try {
            $existing = $this->ruleRepository->getById($rule->getId());
            // This should not happen. Just in case
            if (empty($existing)) {
                throw $this->helper->newHTTPException(sprintf('Rule ID %d was not found', $rule->getId()), 404);
            }
            $this->initialiseRule($existing, $rule, true);
            $this->setConditions($existing, $rule, true);
            $priceRule = $this->ruleRepository->save($existing);
            $response = $this->ruleResponseFactory->create();
            $response->setId(To::int($priceRule->getRuleId()));
            return $response;
        } catch (NoSuchEntityException $e) {
            throw $this->helper->newHTTPException(sprintf('Rule ID %d was not found', $rule->getId()), 404);
        }
    }

    /**
     * @throws Exception
     */
    public function deletePriceRule(int $ruleId): void
    {
        try {
            $this->ruleRepository->deleteById($ruleId);
        } catch (NoSuchEntityException $e) {
            return;
        } catch (LocalizedException|\Exception $e) {
            $this->logger->error($e, sprintf("Failed to delete price rule ID %d", $ruleId));
            throw $this->helper->newHTTPException(sprintf('Internal Server Error: %s', $e->getMessage()));
        }
    }

    /**
     * @throws Exception
     * @throws LocalizedException
     */
    public function createDiscount(DiscountInterface $discount): DiscountInterface
    {
        $err = $discount->validate();
        if (!empty($err)) {
            throw $this->helper->newHTTPException($err, 400);
        }
        try {
            $rule = $this->ruleRepository->getById($discount->getRuleId());
            // This should not happen. Just in case
            if (empty($rule)) {
                throw $this->helper->newHTTPException(sprintf('Rule ID %d was not found', $discount->getRuleId()), 404);
            }
            if ($rule->getCouponType() === RuleInterface::COUPON_TYPE_NO_COUPON) {
                throw $this->helper->newHTTPException(
                    sprintf('Cannot add coupon to a rule with coupon type %s', $rule->getCouponType()),
                    400
                );
            }
        } catch (NoSuchEntityException $e) {
            throw $this->helper->newHTTPException(sprintf('Rule ID %d was not found', $discount->getRuleId()), 404);
        }

        $code = $discount->getCode();
        $autoGenerate = $rule->getUseAutoGeneration();
        if (!$autoGenerate && empty($code)) {
            throw $this->helper->newHTTPException('Coupon code cannot be empty', 400);
        }

        $ruleID = To::int($rule->getRuleId());

        try {
            // Auto generate (aka Unique) coupon
            if ($autoGenerate) {
                $data = [
                    'rule_id' => $ruleID,
                    'qty' => '1',
                    'length' => '12',
                    'format' => 'alphanum',
                ];
                if ($prefix = $discount->getCode()) {
                    $data['prefix'] = $prefix;
                }
                $discountCodes = $this->couponGenerator->generateCodes($data);
                if (empty($discountCodes)) {
                    $this->logger->warn("No discount code was generated", ['rule' => $ruleID]);
                    throw new \Exception(__('Could not generate discount codes'));
                }
                $response = $this->discountFactory->create();
                $response->setRuleId($ruleID);
                $response->setCode($discountCodes[0]);
                return $response;
            }

            // Shared coupon

            $this->searchCriteriaBuilder->addFilter(Coupon::KEY_IS_PRIMARY, true)
                ->addFilter(Coupon::KEY_RULE_ID, $ruleID);
            $primary = $this->couponRepository->getList($this->searchCriteriaBuilder->create())->getItems();
            if (!empty($primary)) {
                // Only one coupon can be primary
                // Update the code if needed and return the same coupon
                foreach ($primary as $primaryCoupon) {
                    if ($primaryCoupon->getCode() !== $discount->getCode()) {
                        $primaryCoupon->setCode($discount->getCode());
                        $primaryCoupon->setUsagePerCustomer($rule->getUsesPerCustomer());
                        $primaryCoupon->setUsageLimit($rule->getUsesPerCoupon());
                        $this->couponRepository->save($primaryCoupon);
                    }
                    $response = $this->discountFactory->create();
                    $response->setRuleId($ruleID);
                    $response->setCode((string)$primaryCoupon->getCode());
                    return $response;
                }
            }

            $now = $this->helper->nowUTC();
            $newCoupon = $this->couponFactory->create();
            $newCoupon->setCode($discount->getCode())
                ->setRuleId($ruleID)
                ->setType(CouponInterface::TYPE_MANUAL)
                ->setIsPrimary(true)
                ->setUsagePerCustomer($rule->getUsesPerCustomer())
                ->setCreatedAt($this->helper->toUTC($now))
                ->setUsageLimit($rule->getUsesPerCoupon());
            $created = $this->couponRepository->save($newCoupon);
            $response = $this->discountFactory->create();
            $response->setRuleId($ruleID);
            $response->setCode((string)$created->getCode());
            return $response;
        } catch (AlreadyExistsException $e) {
            $this->logger->error($e, "Duplicate coupon code");
            throw $this->helper->newHTTPException(sprintf('Duplicate coupon code %s', $discount->getCode()), 409);
        } catch (\Exception $e) {
            $this->logger->error($e, "Failed to create new coupon");
            throw $this->helper->newHTTPException(sprintf('Internal Server Error: %s', $e->getMessage()));
        }
    }

    private function initialiseRule(RuleInterface $newRule, PriceRuleInterface $rule, bool $updateMode)
    {
        $newRule->setName($rule->getName())
            ->setUsesPerCoupon($rule->getTotalLimit())
            ->setUsesPerCustomer($rule->getPerCustomerLimit())
            // Will generate a unique coupon code per person when sending voucher email
            ->setUseAutoGeneration($rule->getIsUnique())
            ->setSortOrder($rule->getPriority())
            ->setIsRss($rule->getIsRss())
            ->setStopRulesProcessing($rule->getDiscardSubsequentRules())
            ->setApplyToShipping($rule->getApplyToShipping())
            ->setDiscountQty($rule->getMaxQuantity())
            ->setDiscountAmount($rule->getValue())
            ->setSimpleFreeShipping(self::NO_FREE_SHIPPING)
            ->setCouponType(RuleInterface::COUPON_TYPE_SPECIFIC_COUPON);

        if (!$updateMode) {
            $newRule->setDescription($rule->getDescription())
                ->setIsActive(true)
                ->setIsAdvanced(true)
                ->setWebsiteIds([$rule->getWebsiteId()]);
        }

        $startDate = $rule->getStartDate();
        if (!empty($startDate)) {
            $from = $this->helper->toUTC($startDate);
            if ($from !== Config::EMPTY_DATE_TIME) {
                $newRule->setFromDate($from);
            }
        } else {
            if ($updateMode) {
                $newRule->setFromDate('');
            }
        }

        $expiration = $rule->getExpirationDate();
        if (!empty($expiration)) {
            $to = $this->helper->toUTC($expiration);
            if ($to !== Config::EMPTY_DATE_TIME) {
                $newRule->setToDate($to);
            }
        } else {
            if ($updateMode) {
                $newRule->setToDate('');
            }
        }

        $type = $rule->getType();
        switch ($type) {
            case PriceRuleInterface::TYPE_FIXED_EACH_ITEM:
                $newRule->setSimpleAction(RuleInterface::DISCOUNT_ACTION_FIXED_AMOUNT);
                break;
            case PriceRuleInterface::TYPE_FIXED_CART_TOTAL:
                $newRule->setSimpleAction(RuleInterface::DISCOUNT_ACTION_FIXED_AMOUNT_FOR_CART);
                break;
            case PriceRuleInterface::TYPE_PERCENTAGE:
                $newRule->setSimpleAction(RuleInterface::DISCOUNT_ACTION_BY_PERCENT);
                break;
            case PriceRuleInterface::TYPE_FREE_SHIPPING:
                // https://docs.magento.com/user-guide/marketing/price-rules-cart-free-shipping.html
                $newRule->setSimpleAction(RuleInterface::DISCOUNT_ACTION_BY_PERCENT);
                $newRule->setApplyToShipping(true);
                // NOTE: There is a bug in Magento. `salesrules.simple_free_shipping` column is numeric
                if ($rule->getFreeShippingToMatchingItemsOnly()) {
                    $newRule->setSimpleFreeShipping(self::APPLY_FREE_SHIPPING_TO_MATCHING_ITEMS_ONLY);
                } else {
                    $newRule->setSimpleFreeShipping(self::APPLY_FREE_SHIPPING_TO_CART_WITH_MATCHING_ITEMS);
                }
                $newRule->setDiscountAmount(0);
                break;
            case PriceRuleInterface::TYPE_BUY_X_GET_Y_FREE:
                $newRule->setApplyToShipping(false);
                $newRule->setSimpleAction(RuleInterface::DISCOUNT_ACTION_BUY_X_GET_Y);
                // DiscountAmount (Rule value) = Y
                // DiscountStep = X
                $newRule->setDiscountStep($rule->getBuyXQuantity());
        }
    }

    /**
     * @throws LocalizedException
     */
    private function setCustomerGroups(RuleInterface $newRule)
    {
        $search = $this->searchCriteriaBuilder->create();
        $groupIDs = [];
        $groups = $this->groupRepository->getList($search)->getItems();
        foreach ($groups as $group) {
            $groupIDs[] = $group->getId();
        }

        if (!empty($groupIDs)) {
            $newRule->setCustomerGroupIds($groupIDs);
        }
    }

    private function setConditions(RuleInterface $newRule, PriceRuleInterface $rule, bool $updateMode)
    {
        /** @var ConditionInterface[] $ruleConditions */
        $ruleConditions = [];
        /** @var ConditionInterface[] $actionConditions */
        $actionConditions = [];

        $minAmount = $rule->getMinPurchaseAmount();
        if ($minAmount > 0) {
            $minAmountCondition = $this->conditionFactory->create();
            $minAmountCondition->setConditionType('Magento\SalesRule\Model\Rule\Condition\Address');
            $minAmountCondition->setAttributeName('base_subtotal');
            $minAmountCondition->setOperator(">=");
            $minAmountCondition->setValue($minAmount);
            $ruleConditions[] = $minAmountCondition;
        }

        $minQuantity = $rule->getMinQuantity();
        if ($minQuantity > 0) {
            $minQuantityCondition = $this->conditionFactory->create();
            $minQuantityCondition->setConditionType('Magento\SalesRule\Model\Rule\Condition\Address');
            $minQuantityCondition->setAttributeName('total_qty');
            $minQuantityCondition->setOperator(">=");
            $minQuantityCondition->setValue($minQuantity);
            $ruleConditions[] = $minQuantityCondition;
        }

        // Categories and Product rules are mutually exclusive (See $rule->validate())
        $ruleCategoryIDs = $rule->getRuleCategories();
        if (!empty($ruleCategoryIDs)) {
            $condition = $this->getCategoryConditions($ruleCategoryIDs);
            if (!empty($condition)) {
                $ruleConditions[] = $condition;
            }
        }

        $ruleProductIDs = $rule->getRuleProducts();
        if (!empty($ruleProductIDs)) {
            $condition = $this->getProductConditions($ruleProductIDs);
            if (!empty($condition)) {
                $ruleConditions[] = $condition;
            }
        }

        // Categories and Product rules are mutually exclusive (See $rule->validate())
        $actionCategoryIDs = $rule->getActionCategories();
        if (!empty($actionCategoryIDs)) {
            $condition = $this->getCategoryConditions($actionCategoryIDs);
            if (!empty($condition)) {
                $actionConditions[] = $condition;
            }
        }

        $actionProductIDs = $rule->getActionProducts();
        if (!empty($actionProductIDs)) {
            $condition = $this->getProductConditions($actionProductIDs);
            if (!empty($condition)) {
                $actionConditions[] = $condition;
            }
        }

        if (!empty($ruleConditions)) {
            $newRule->setCondition($this->initialiseCondition($ruleConditions));
        } else {
            if ($updateMode) {
                $newRule->setCondition($this->initialiseCondition());
            }
        }

        if (!empty($actionConditions)) {
            $newRule->setActionCondition($this->initialiseCondition($actionConditions));
        } else {
            if ($updateMode) {
                $newRule->setActionCondition($this->initialiseCondition());
            }
        }
    }

    private function initialiseCondition(array $children = null): ConditionInterface
    {
        $condition = $this->conditionFactory->create();
        $condition->setConditionType('Magento\SalesRule\Model\Rule\Condition\Combine');
        $condition->setAggregatorType(ConditionInterface::AGGREGATOR_TYPE_ALL);
        $condition->setValue(true);
        $condition->setConditions($children);
        return $condition;
    }

    /**
     * @param int[] $categoryIDs
     * @return ConditionInterface|null
     */
    private function getCategoryConditions(array $categoryIDs): ?ConditionInterface
    {
        $validIDs = [];
        foreach ($categoryIDs as $categoryId) {
            try {
                if ($this->categoryRepository->get($categoryId)) {
                    $validIDs[] = $categoryId;
                }
            } catch (NoSuchEntityException $e) {
                $this->logger->warn('Product category was not found', ['category_id' => $categoryId]);
                continue;
            }
        }
        if (!empty($validIDs)) {
            $productCondition = $this->conditionFactory->create();
            $productCondition->setConditionType('Magento\SalesRule\Model\Rule\Condition\Product');
            $productCondition->setAttributeName('category_ids');
            $productCondition->setValue($validIDs);
            $productCondition->setOperator("()");

            $condition = $this->conditionFactory->create();
            $condition->setValue(1);
            $condition->setConditionType('Magento\SalesRule\Model\Rule\Condition\Product\Found');
            $condition->setConditions([$productCondition]);
            return $condition;
        }
        return null;
    }

    /**
     * @param int[] $productIDs
     * @return ConditionInterface|null
     */
    private function getProductConditions(array $productIDs): ?ConditionInterface
    {
        $productSKUs = [];
        $this->searchCriteriaBuilder->addFilter('entity_id', $productIDs, 'in');
        $products = $this->productRepository->getList($this->searchCriteriaBuilder->create())->getItems();
        foreach ($products as $product) {
            $productSKUs[] = $product->getSku();
        }

        if (count($productSKUs) != count($productIDs)) {
            $this->logger->warn(
                'Some products was not found for the price rule',
                ['requested' => $productIDs, 'found' => $productSKUs]
            );
        }

        if (!empty($productSKUs)) {
            $productCondition = $this->conditionFactory->create();
            $productCondition->setConditionType('Magento\SalesRule\Model\Rule\Condition\Product');
            $productCondition->setAttributeName('sku');
            $productCondition->setValue($productSKUs);
            $productCondition->setOperator("()");

            $condition = $this->conditionFactory->create();
            $condition->setValue(1);
            $condition->setConditionType('Magento\SalesRule\Model\Rule\Condition\Product\Found');
            $condition->setConditions([$productCondition]);
            $condition->setAggregatorType(ConditionInterface::AGGREGATOR_TYPE_ALL);
            return $condition;
        }
        return null;
    }
}