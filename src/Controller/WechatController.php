<?php

namespace ApiBundle\Controller;

use Carbon\Carbon;
use Entity\User;
use Entity\UserSource;
use Entity\UserWechat;
use Leaf\Application;
use Leaf\DB;
use Leaf\Json;
use Leaf\Log;
use Leaf\Redirect;
use Leaf\Request;
use Leaf\Util;
use PFinal\Wechat\Kernel;
use PFinal\Wechat\Service\JsService;
use PFinal\Wechat\Service\OAuthService;
use PFinal\Wechat\Service\QrcodeService;
use Service\Auth;
use WechatBundle\Entity\Source;
use WechatBundle\Service\ContactService;

class WechatController
{
    /**
     * 使用场景id，获取关注公众号的二维码 Url
     * @Route api/wechat/qrcode
     */
    public function qrcode(Request $request, Application $app)
    {
        $sourceId = $request->get('sourceId', 0);

        $entity = DB::table(Source::tableName())->findByPk($sourceId);

        //无效场景id，全部使用"0"
        $code = '0';
        if ($entity != null) {
            $code = (string)$entity['code'];
        }

        try {
            Kernel::init($app['wechat.config']);

            $res = QrcodeService::forever($code);
            $url = QrcodeService::url($res['ticket']);

            return Json::renderWithTrue($url);

        } catch (\Exception $ex) {
            $error = $ex->getMessage();
            return Json::renderWithFalse($error);
        }
    }

    /**
     * 使用会员推荐码，获取关注公众号的二维码 Url
     * @Route api/wechat/qrcodeWithRefereeCode
     */
    public function qrcodeWithRefereeCode(Request $request, Application $app)
    {
        $refereeCode = $request->get('refereeCode', '');

        $user = User::getUserWithRefereeCode($refereeCode);

        if ($user == null) {
            return Json::renderWithFalse('referee code error');
        }

        $code = 'uid_' . $user->id;

        try {
            Kernel::init($app['wechat.config']);

            $res = QrcodeService::forever($code);
            $url = QrcodeService::url($res['ticket']);

            return Json::renderWithTrue($url);

        } catch (\Exception $ex) {
            $error = $ex->getMessage();
            return Json::renderWithFalse($error);
        }
    }

    /**
     * 微信公众号JS-SDK签名
     *
     * 用于 wx.config({})
     *
     * @Route api/wechat/signature
     */
    public function signature(Request $request, Application $app)
    {
        $url = $request->get('url');
        $url = base64_decode($url);

        try {
            Kernel::init($app['wechat.config']);

            $arr = JsService::getSignPackage($url);
        } catch (\Exception $ex) {
            return Json::renderWithFalse($ex->getMessage());
        }
        return Json::renderWithTrue($arr);
    }


    /**
     * 绑定openid
     * 直接跳转到此接口,ajax无效
     *
     * @Route api/wechat/bind
     * @Middleware token
     */
    public function bindOpenid(Request $request, Application $app)
    {
        $redirectUrl = base64_decode($request->get('redirectUrl'));

        //不在微信中
        if (!Util::isWechatBrowser()) {
            return Redirect::to($redirectUrl);
        }

        /** @var User $user */
        $user = Auth::getUser();

        $config = $app['wechat.config'];

        //已绑定
        if ($user->getWechatOpenid($config['appId']) != '') {
            return Redirect::to($redirectUrl);
        }

        try {
            Kernel::init($config);

            $openid = OAuthService::getOpenid();

            DB::table(UserWechat::tableName())->insert([
                'appid' => $config['appId'],
                'user_id' => $user->getId(),
                'openid' => $openid,
                'created_at' => Carbon::now(),
            ]);

            //检查是否来自用户的推荐
            $us = DB::table(UserSource::tableName())->where('user_id=?', $user->getId())->findOne();
            if ($us == null) {
                $contact = ContactService::findContactByOpenId($config['appId'], $openid);
                if (!empty($contact['qrcode'])) {
                    if (preg_match('/^uid_(\d+)$/', $contact['qrcode'], $arr)) {
                        DB::table(UserSource::tableName())->insert(['referee_user_id' => $arr[1], 'user_id' => $user->getId()]);
                    }
                }
            }

        } catch (\Exception $ex) {
            Log::warning($ex->getMessage());
        }

        return Redirect::to($redirectUrl);
    }
}