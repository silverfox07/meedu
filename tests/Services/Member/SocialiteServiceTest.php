<?php

/*
 * This file is part of the Qsnh/meedu.
 *
 * (c) 杭州白书科技有限公司
 */

namespace Tests\Services\Member;

use Tests\TestCase;
use Illuminate\Support\Str;
use App\Exceptions\ServiceException;
use App\Services\Member\Models\User;
use Illuminate\Support\Facades\Auth;
use App\Services\Member\Models\Socialite;
use App\Services\Member\Services\SocialiteService;
use App\Services\Member\Interfaces\SocialiteServiceInterface;

class SocialiteServiceTest extends TestCase
{

    /**
     * @var SocialiteService
     */
    protected $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(SocialiteServiceInterface::class);
    }

    public function test_getBindUserId()
    {
        $user = User::factory()->create();
        $socialite = Socialite::factory()->create(['user_id' => $user->id]);
        $userId = $this->service->getBindUserId($socialite->app, $socialite->app_user_id);
        $this->assertEquals($user->id, $userId);
    }

    public function test_bindApp()
    {
        $user = User::factory()->create();
        $app = 'app1';
        $appUserId = Str::random();
        $this->service->bindApp($user->id, $app, $appUserId, []);
        $userId = $this->service->getBindUserId($app, $appUserId);
        $this->assertEquals($user->id, $userId);
    }

    public function test_bindApp_repeat()
    {
        $this->expectException(ServiceException::class);

        $user = User::factory()->create();
        $app = 'app1';
        $appUserId = Str::random();
        $this->service->bindApp($user->id, $app, $appUserId, []);
        $this->service->bindApp($user->id, $app, $appUserId, []);
    }

    public function test_bindAppWithNewUser()
    {
        $app = 'app1';
        $appUserId = Str::random();
        $userId = $this->service->bindAppWithNewUser($app, $appUserId, []);
        $user = User::find($userId);
        $this->assertTrue(substr($user->mobile, 0, 1) != 1);
    }

    public function test_userSocialites()
    {
        $user = User::factory()->create();
        Socialite::factory()->count(4)->create(['user_id' => $user->id]);
        $list = $this->service->userSocialites($user->id);
        $this->assertEquals(4, count($list));
    }

    public function test_cancelBind()
    {
        $user = User::factory()->create();
        Auth::login($user);
        $app = 'app1';
        $appUserId = Str::random();
        $this->service->bindApp($user->id, $app, $appUserId, []);
        $this->service->cancelBind($app, $user['id']);
        $this->assertEmpty(Socialite::whereUserId($user->id)->where('app', $app)->whereAppUserId($appUserId)->first());
    }
}
