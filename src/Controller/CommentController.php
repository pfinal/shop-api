<?php

namespace ApiBundle\Controller;

use Entity\Comment;
use Leaf\DB;
use Leaf\Json;
use Leaf\Pagination;
use Leaf\Request;
use Service\Auth;
use Service\CommentService;

class CommentController
{
    /**
     * 订单评价
     *
     * method
     *      POST
     * url
     *     api/comment/create?token=TOKEN
     *
     * params
     *      data        对以下参数做json之后传入
     *          orderId   订单ID
     *          OrderComment    订单评论内容 由 service、express 组成 均是由数字组成 在1到5之间
     *          Comment         商品评论数组
     *              [
     *                  {
     *                      'sku_id': '库存ID',
     *                      'score': '评分',
     *                      'content': '评论内容',
     *                  }
     *              ]
     *
     * response
     *
     * ```
     * {
     *      "status": true,
     *      "data": "评论成功,
     * }
     *```
     *
     * @Route api/comment/create
     * @Method post
     */
    public function create(Request $request)
    {
        $ajaxData = $request->get('data');
        $ajaxData = json_decode($ajaxData, true);

        $orderId = (int)$ajaxData['orderId'];
        $orderComment = $ajaxData['OrderComment'];
        $commentList = $ajaxData['Comment'];

        $memberId = Auth::getId();

        if (!CommentService::create($memberId, $orderId, $orderComment, $commentList, $error)) {
            return Json::renderWithFalse($error);
        }

        return Json::renderWithTrue("评论成功");
    }

    /**
     * 根据 商品ID  查询评论信息
     *
     * method
     *      GET
     * url
     *     api/comment?token=TOKEN
     *
     * params
     *      productId    商品ID
     *      pageSize     每页展示条数 默认不传为100
     *      page         展示的页码数
     *
     * response
     *
     * ```
     * {
     *      "status": true,
     *      "data": [
     *          {
     *              'score': '评分',
     *              'content': '评价内容',
     *              'user_info': {
     *                  'nickname': '昵称',
     *                  'avatar_url': '头像URL',
     *              },
     *              ...
     *          },
     *          ...
     *      ]
     * }
     *```
     *
     * @Route api/comment
     */
    public function index(Request $request)
    {
        $productId = (int)$request->get('productId');
        $pageSize = (int)$request->get('pageSize', 100);

        $page = new Pagination();
        $page->pageSize = $pageSize;

        $query = DB::table(Comment::tableName())
            ->where('product_id = ?', [$productId])
            ->orderBy('id desc');

        $queryCount = clone $query;
        $page->itemCount = $queryCount->count();

        $commentList = $query
            ->asEntity(Comment::className())
            ->limit($page->limit)
            ->orderBy('id desc')
            ->findAll();

        /** @var Comment[] $commentList */

        // 评论人的信息
        foreach ($commentList as $key => $item) {
            $commentList[$key]['user_info'] = $item->userInfo();
        }

        return Json::renderWithTrue([
            'page' => $page,
            'list' => $commentList,
        ]);
    }
}