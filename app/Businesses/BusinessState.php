<?php

/*
 * This file is part of the Qsnh/meedu.
 *
 * (c) 杭州白书科技有限公司
 */

namespace App\Businesses;

use Carbon\Carbon;
use App\Constant\FrontendConstant;
use App\Exceptions\ServiceException;
use App\Services\Course\Models\Course;
use App\Services\Base\Services\ConfigService;
use App\Services\Member\Services\UserService;
use App\Services\Order\Services\OrderService;
use App\Services\Course\Services\CourseService;
use App\Services\Order\Services\PromoCodeService;
use App\Services\Member\Services\SocialiteService;
use App\Services\Base\Interfaces\ConfigServiceInterface;
use App\Services\Member\Interfaces\UserServiceInterface;
use App\Services\Order\Interfaces\OrderServiceInterface;
use App\Services\Course\Interfaces\CourseServiceInterface;
use App\Services\Order\Interfaces\PromoCodeServiceInterface;
use App\Services\Member\Interfaces\SocialiteServiceInterface;

class BusinessState
{

    /**
     * @param array $user
     * @param array $course
     * @param array $video
     * @return bool
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function canSeeVideo(array $user, array $course, array $video): bool
    {
        // 如果video的价格为0那么可以直接观看
        if ($video['charge'] === 0) {
            return true;
        }
        /**
         * @var CourseService $courseService
         */
        $courseService = app()->make(CourseServiceInterface::class);
        $course = $courseService->find($course['id']);
        // 如果课程免费就可以观看
        if ((int)$course['is_free'] === Course::IS_FREE_YES) {
            return true;
        }
        /**
         * @var UserService $userService
         */
        $userService = app()->make(UserServiceInterface::class);
        // 如果用户买了课程可以直接观看
        if ($userService->hasCourse($user['id'], $course['id'])) {
            return true;
        }
        // 如果用户买了当前视频可以直接观看
        if ($userService->hasVideo($user['id'], $video['id'])) {
            return true;
        }
        // 如果用户买了会员可以直接观看
        if ($this->isRole($user)) {
            return true;
        }
        return false;
    }

    /**
     * 订单是否支付.
     *
     * @param array $order
     *
     * @return bool
     */
    public function orderIsPaid(array $order): bool
    {
        return $order['status'] === FrontendConstant::ORDER_PAID;
    }

    /**
     * 是否需要绑定手机号
     *
     * @param array $user
     * @return bool
     */
    public function isNeedBindMobile(array $user): bool
    {
        return substr($user['mobile'], 0, 1) != 1 || mb_strlen($user['mobile']) !== 11;
    }

    /**
     * @param array $user
     * @return bool
     */
    public function isRole(array $user): bool
    {
        if (!$user['role_id'] || !$user['role_expired_at']) {
            return false;
        }
        if (Carbon::now()->gt($user['role_expired_at'])) {
            return false;
        }
        return true;
    }

    /**
     * 是否可以生成邀请码
     *
     * @param array $user
     * @return bool
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function canGenerateInviteCode(array $user): bool
    {
        /**
         * @var $configService ConfigService
         */
        $configService = app()->make(ConfigServiceInterface::class);
        /**
         * @var $promoCodeService PromoCodeService
         */
        $promoCodeService = app()->make(PromoCodeServiceInterface::class);
        $inviteConfig = $configService->getMemberInviteConfig();
        $isRole = $this->isRole($user);
        if ((bool)$inviteConfig['free_user_enabled'] === false && !$isRole) {
            // 开启了非会员无法生成优惠码
            return false;
        }
        $userPromoCode = $promoCodeService->userPromoCode($user['id']);
        if ($userPromoCode) {
            // 已经生成
            return false;
        }
        return true;
    }

    /**
     * @param int $loginUserId
     * @param array $promoCode
     * @return bool
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function promoCodeCanUse(int $loginUserId, array $promoCode): bool
    {
        // 自己不能使用自己的优惠码
        if ($promoCode['user_id'] === $loginUserId) {
            return false;
        }
        if ($promoCode['use_times'] > 0 && $promoCode['use_times'] - $promoCode['used_times'] <= 0) {
            // 使用次数已用完
            return false;
        }
        /**
         * @var $promoCodeService PromoCodeService
         */
        $promoCodeService = app()->make(PromoCodeServiceInterface::class);
        // 同一邀请码一个用户只能用一次
        $useRecords = $promoCodeService->getCurrentUserOrderPaidRecords($loginUserId, $promoCode['id']);
        if ($useRecords) {
            return false;
        }
        // 非用户邀请优惠码可以多次使用
        if (!$this->isUserInvitePromoCode($promoCode['code'])) {
            return true;
        }
        /**
         * @var $userService UserService
         */
        $userService = app()->make(UserServiceInterface::class);
        $user = $userService->find($loginUserId);
        if ((int)$user['is_used_promo_code'] === 1) {
            // 用户邀请优惠码只能使用一次
            return false;
        }
        return true;
    }

    /**
     * @param string $code
     * @return bool
     */
    public function isUserInvitePromoCode(string $code): bool
    {
        return strtolower($code[0]) === 'u';
    }

    /**
     * @param array $order
     * @return int
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function calculateOrderNeedPaidSum(array $order): int
    {
        /**
         * @var $orderService OrderService
         */
        $orderService = app()->make(OrderServiceInterface::class);
        $sum = $order['charge'] - $orderService->getOrderPaidRecordsTotal($order['id']);
        return $sum >= 0 ? $sum : 0;
    }

    /**
     * 是否购买了课程
     *
     * @param integer $userId
     * @param integer $courseId
     * @return boolean
     */
    public function isBuyCourse(int $userId, int $courseId): bool
    {
        if (!$userId) {
            return false;
        }
        /**
         * @var CourseService $courseService
         */
        $courseService = app()->make(CourseServiceInterface::class);
        $course = $courseService->find($courseId);
        if ($course['is_free'] === Course::IS_FREE_YES) {
            return true;
        }
        /**
         * @var $userService UserService
         */
        $userService = app()->make(UserServiceInterface::class);
        $user = $userService->find($userId, ['role']);
        if ($this->isRole($user)) {
            return true;
        }
        if ($userService->hasCourse($user['id'], $courseId)) {
            return true;
        }
        return false;
    }

    /**
     * 是否可以评论课程
     *
     * @param array $user
     * @param array $course
     * @return bool
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function courseCanComment(array $user, array $course): bool
    {
        if (!$user) {
            return false;
        }
        if ($this->isBuyCourse($user['id'], $course['id'])) {
            return true;
        }
        return false;
    }

    public function videoCanComment(array $user, array $video): bool
    {
        if (!$user) {
            return false;
        }
        /**
         * @var CourseService $courseService
         */
        $courseService = app()->make(CourseServiceInterface::class);
        $course = $courseService->find($video['course_id']);
        if ($this->canSeeVideo($user, $course, $video)) {
            return true;
        }
        return false;
    }

    /**
     * 是否开启微信公众号授权登录
     *
     * @return bool
     */
    public function isEnabledMpOAuthLogin(): bool
    {
        /**
         * @var ConfigService $configService
         */
        $configService = app()->make(ConfigServiceInterface::class);
        $mpWechatConfig = $configService->getMpWechatConfig();
        $enabledOAuthLogin = (int)($mpWechatConfig['enabled_oauth_login'] ?? 0);
        return $enabledOAuthLogin === 1;
    }

    /**
     * @return bool
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function enabledMpScanLogin(): bool
    {
        /**
         * @var ConfigService $configService
         */
        $configService = app()->make(ConfigServiceInterface::class);

        $mpWechatConfig = $configService->getMpWechatConfig();

        $enabledOAuthLogin = (int)($mpWechatConfig['enabled_scan_login'] ?? 0);

        return $enabledOAuthLogin === 1;
    }

    public function socialiteBindCheck(int $userId, string $app, string $appId): void
    {
        /**
         * @var SocialiteService $socialiteService
         */
        $socialiteService = app()->make(SocialiteServiceInterface::class);

        $hasBindSocialites = $socialiteService->userSocialites($userId);
        if (in_array($app, array_column($hasBindSocialites, 'app'))) {
            throw new ServiceException(__('您已经绑定了该渠道的账号'));
        }

        // 读取当前社交账号绑定的用户id
        if ($socialiteService->getBindUserId($app, $appId)) {
            throw new ServiceException(__('当前渠道账号已绑定了其它账号'));
        }
    }
}
