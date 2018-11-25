<?php

namespace ApiBundle\Controller;

use Leaf\Application;
use Leaf\Cache;
use Leaf\DB;
use Leaf\Json;
use Leaf\Pagination;
use Leaf\Request;
use PFinal\Wechat\Kernel;
use PFinal\Wechat\Service\OAuthService;
use Service\Auth;
use TicketBundle\Entity\Ticket;
use TicketBundle\Entity\TicketDetail;
use TicketBundle\Service\TicketService;

class TicketController
{
    /**
     * 我的卡券
     *
     * method
     *      GET
     *
     * params
     *     status 0全部(默认) 1未使用  2已使用
     *     type  0全部(默认) 10 代金券 20 礼品券 30红包
     *     limit 默认500
     *     page  页码数 可以不传，默认为1
     * url
     *    api/ticket/my?token=TOKEN
     *
     * response
     *
     * ```
     * {
     *   "status": true,
     *   "data": {
     *     "page": {
     *       "itemCount": 1,
     *       "currentPage": 1,
     *       "offset": 0,
     *       "pageSize": 20,
     *       "pageCount": 1,
     *       "prevPage": 1,
     *       "nextPage": 1
     *     },
     *     "data": [
     *       {
     *         "id": 1002,
     *         "status": 1,   //1 未使用  2 已使用
     *         "begin_timestamp": 1513612800,   //起用时间
     *         "end_timestamp": 1516377599,     //到期时间
     *         "code": "5A38CB3446138CE2",      //核销码
     *         "created_at": "2017-12-19 16:17:56",
     *         "status_alias": "未使用",
     *         "ticket": {
     *           "title": "10元代金券",
     *           "type":10,               // 10.代金券 20.礼品券 30.红包
     *           "type_alias": "代金券",
     *           "logo_url": "",
     *           "reduce_cost": "10.00",  //减免金额
     *           "least_cost": "100.00",  //抵扣条件
     *           "notice": "使用提醒",
     *           "description": "使用说明"
     *         }
     *       }
     *     ]
     *   },
     *   "code": "0"
     * }
     * ```
     *
     * @Route api/ticket/my
     */
    public function my(Request $request)
    {
        $status = (int)$request->get('status', 0);
        $type = (int)$request->get('type', 0);

        $query = DB::table(TicketDetail::tableName() . ' as detail')
            ->field('detail.*')
            ->where('user_id=?', Auth::getId())
            ->join(Ticket::tableName() . ' as ticket', 'ticket.id=detail.ticket_id')
            ->asEntity(TicketDetail::className());

        if ($status > 0) {

            if ($status == TicketDetail::STATUS_UNUSED) {
                //未使用状态时，显示结束时间大于等于当前时间并且状态为未使用的数据
                $query->where('detail.status=? and detail.end_timestamp>=?', [$status, time()]);
            } else if ($status == TicketDetail::STATUS_USED) {
                //已使用状态时，只显示最近一个月
                $query->where('detail.status=? and detail.exchange_timestamp>=?', [$status, strtotime("-1 month")]);
            } else if ($status == TicketDetail::STATUS_INVALID) {
                //已过期状态时，只显示未使用并且结束时间小于当前时间的数据
                $query->where('detail.status=? and detail.end_timestamp<?', [TicketDetail::STATUS_UNUSED, time()]);
            }

        }

        if ($type > 0) {
            $query->where('ticket.type=?', $type);
        }

        //分页
        $page = new Pagination();

        $page->pageSize = $request->get('limit', 500);

        $queryCount = clone $query;
        $page->itemCount = $queryCount->count();

        $list = $query
            ->limit($page->limit)
            ->findAll();

        return Json::renderWithTrue([
            'data' => $list,
            'page' => $page,
        ]);
    }


    /**
     * 领取卡券
     * @Route api/ticket/receive
     * @ClearMiddleware token
     */
    public function receive(Application $app, Request $request)
    {
        $config = $app['wechat.config'];

        Kernel::init($config);

        $openid = OAuthService::getOpenid();

        $ticketId = 0;

        $sk = $request->get('sk');
        if ($sk == '83729374241209' && time() < strtotime('2018-06-20')) {
            $ticketId = 1007;
        }

        $cacheKey = 'ticket:receive:' . $sk . ':' . $openid;
        if (Cache::get($cacheKey)) {
            $message = '您不能重复领取';
        } else {
            $bool = TicketService::sendWithOpenid($ticketId, $config['appId'], $openid, $error);

            if ($bool) {
                $message = '领取成功! 下单支付时直接抵扣';

                Cache::set($cacheKey, true, 60 * 60 * 24 * 10);

            } else {
                $message = '领取失败 ' . $error;
            }
        }


        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>卡券活动</title>
   <link rel="stylesheet" href="https://res.wx.qq.com/open/libs/weui/1.1.2/weui.min.css">
  </head>
  <body style="padding-top:80px; text-align: center;font-size: 14px;color:#333;">
      <div class="icon-box">
        <i class="weui-icon-success weui-icon_msg"></i>
    </div>
    <br>
    <div>{$message}</div>
    <br>
    <div style="width: 80%;margin: 0 auto;">
    <a href="http://leafshop-front.it266.com/" class="weui-btn weui-btn_primary">确定</a>
</div>
    <script>
        setTimeout(function(){
            window.location = "http://leafshop-front.it266.com/"
        },8000)
    </script>
  </body>
</html>
HTML;

    }


}