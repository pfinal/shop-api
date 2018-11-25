<?php

namespace ApiBundle\Controller;

use Carbon\Carbon;
use Entity\TicketRush;
use Entity\TicketRushRecord;
use Leaf\DB;
use Leaf\Json;
use Leaf\Request;
use Service\Auth;
use TicketBundle\Entity\Ticket;
use TicketBundle\Service\TicketService;

class TicketRushController
{
    /**
     * 可领取卡券列表
     *
     * @Route /api/ticket-rush
     * @Method get
     *
     * @api {get} /api/ticket-rush
     *
     * @apiParam {string} sortby 排序字段 [可选] 默认为id,支持排序的字段:id
     * @apiParam {string} order 排序方式 [可选] 默认降序, asc升序 desc降序
     * @apiSuccess
     *
     * ```
     * {
     *   "status": true,
     *   "data": {
     *     [
     *       {
     *         "id": Id,
     *         "ticket_id": 卡券id,
     *         "quantity": 卡券数量,
     *         "title": 标题,
     *         "status": 状态,
     *         "created_at": CreatedAt,
     *         "updated_at": UpdatedAt,
     *         "ticket": {卡券信息},
     *         "ticketRushRecord": null,  //为null时表示当前用户未领取
     *       }
     *     ]
     *   },
     *   "code": "0"
     * }
     * ```
     * @apiError {"status":true,"data":"原因", code: "-1"}
     */
    public function index(Request $request)
    {
        //过滤条件
        $condition = [];
        $params = [];
        $search = $request->all();

        //if (!empty($search['id'])) {
        //    $condition[] = 'id = :id';
        //    $params[':id'] = $search['id'];
        //}

        //if (!empty($search['name'])) {
        //    $condition[] = 'name like :name';
        //    $params[':name'] = '%' . trim($search['name']) . '%';
        //}

        $sortBy = $request->get('sortby', 'id');
        $order = strtolower($request->get('order', 'desc'));

        if (!in_array($sortBy, ['id'])) {
            return Json::renderWithFalse('指定的排序字段不支持');
        }
        if (!in_array($order, ['asc', 'desc'])) {
            return Json::renderWithFalse('指定的排序方式不支持');
        }

        //数据
        /** @var TicketRush[] $data */
        $data = TicketRush::with(['ticket'])
            ->where('status=?', TicketRush::STATUS_YES)
            ->where(implode(' and ', $condition), $params)
            ->orderBy($sortBy . ' ' . $order)
            ->findAll();

        $result = [];
        foreach ($data as $v) {
            $ticket = $v->ticket;
            if ($ticket == null) {
                continue;
            }

            //当前用户是否有领取
            $v->ticketRushRecord = TicketRushRecord::where('ticket_rush_id=? and user_id=?', [$v->id, Auth::getId()])
                ->findOne();

            if ($ticket->status == Ticket::STATUS_YES && $ticket->quantity > 0) {
                $result[] = $v;
            }
        }

        return Json::renderWithTrue($result);
    }


    /**
     * 接收领取卡券
     *
     * @Route /api/ticket-rush/receive
     * @Method post
     *
     * @api {post} /api/ticket-rush/create
     * @apiParam {string} id  ticket rush id
     * @apiSuccess {"status":true,"data": null}
     * @apiError {"status":true,"data":"原因", code: "-1"}
     */
    public function receive(Request $request)
    {
        $id = $request->get('id');

        DB::getConnection()->beginTransaction();

        /** @var TicketRush $ticketRush */
        $ticketRush = TicketRush::wherePk($id)->lockForUpdate()->findOne();

        if ($ticketRush == null) {
            DB::getConnection()->rollBack();
            return Json::renderWithFalse('指定ID不存在');
        }

        if ($ticketRush->quantity != 0) {
            if ($ticketRush->record_total >= $ticketRush->quantity) {
                DB::getConnection()->rollBack();
                return Json::renderWithFalse('您来晚了，卡券已领完', 'QUANTITY');
            }
        }

        $ticket = $ticketRush->ticket;
        if ($ticket == null || $ticket->status != Ticket::STATUS_YES || $ticket->quantity <= 0) {
            DB::getConnection()->rollBack();
            return Json::renderWithFalse('您来晚了，卡券已领完', 'QUANTITY');
        }

        //当前用户是否有领取
        $ticketRushRecord = TicketRushRecord::where('ticket_rush_id=? and user_id=?', [$id, Auth::getId()])
            ->lockForUpdate()
            ->findOne();

        if ($ticketRushRecord != null) {
            DB::getConnection()->rollBack();
            return Json::renderWithFalse('您已领取此卡券，不能重复领取', 'EXISTS');
        }

        TicketRush::wherePk($id)->increment('record_total', 1, ['updated_at' => Carbon::now()]);

        $ticketDetailId = TicketService::sendWithUserId(Auth::getId(), $ticket->id, $err);
        if ($ticketDetailId <= 0) {
            DB::getConnection()->rollBack();
            return Json::renderWithFalse($err);
        }

        DB::table(TicketRushRecord::tableName())
            ->insert([
                'ticket_rush_id' => $id,
                'ticket_detail_id' => $ticketDetailId,
                'user_id' => Auth::getId(),
            ]);

        DB::getConnection()->commit();

        return Json::renderWithTrue('领取成功');
    }
}
