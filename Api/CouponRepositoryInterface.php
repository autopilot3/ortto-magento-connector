<?php
declare(strict_types=1);

namespace Autopilot\AP3Connector\Api;

use Autopilot\AP3Connector\Api\Data\CouponInterface;

/**
 *  Interface CouponRepositoryInterface
 * @api
 */
interface CouponRepositoryInterface
{
    /**
     * @param CouponInterface $coupon
     * @return void
     */
    public function create(CouponInterface $coupon);
}