<?php

namespace ApiBundle\Controller;

use AdminBundle\Service\ProductService;
use Entity\Product;
use Entity\Timelimit;
use Leaf\Cache;
use Leaf\DB;
use Leaf\Json;
use Leaf\Request;
use Leaf\Util;

/**
 * 秒杀商品
 */
class TimelimitController
{
    /**
     * 秒杀列表
     *
     * method
     *      GET
     *
     * params
     *      page  当前页码 可选默认为1
     *      pageSize  可选参数默认为20
     *
     * url
     *       api/timelimit
     *
     * response
     *
     * ```
     * {
     *   "status": true,
     *   "data": {
     *           "list": [
     *           {
     *               "skuId": 1001,
     *               "name": "xxx商品",
     *               "price": 100.2
     *               "file": 201612/20/5836ecc62d137.jpg,
     *           },
     *          {
     *               "skuId": 1002,
     *               "name": "xxx商品2",
     *               "price": 100.2
     *              "file": 201612/20/5836ecc62d137.jpg,
     *           }
     *               ],
     *       "page": {
     *            "itemCount": 100,
     *           "currentPage": 1,
     *           "offset": 0,
     *           "pageSize": 20,
     *           "pageCount": 5
     *           }
     *       }
     *    }
     *```
     *
     * @Route api/timelimit
     */
    public function index(Request $request)
    {
        $pageSize = $request->get('pageSize', 20);
        $currentPage = (int)$request->get('page', 1); // 20180701 带上页码做缓存的key

        $cacheKey = 'api.timelimit.pageSize.' . $pageSize . '.page.' . $currentPage;
        $data = Cache::get($cacheKey);
        if ($data) {
            return Json::renderWithTrue($data);
        }

        // 获取秒杀数据
        $startTime = time();
        $endTime = time();

        $dataProvider = Timelimit::where('status=?', [Timelimit::STATUS_YES])
            ->where('begin <= ? and (begin + duration_second >= ?)', [$startTime, $endTime])// 已开始、未结束的
            ->orderBy('id desc')
            ->paginate($pageSize);

        $timelimitList = $dataProvider->getData();

        $productIds = Util::arrayColumn($timelimitList, 'product_id');

        $productArr = [];

        if (count($productIds) > 0) {
            $productList = DB::table(Product::tableName())
                ->asEntity(Product::className())
                ->whereIn('id', $productIds)
                ->where('status=?', [Product::STATUS_DISPLAY])
                ->orderBy('sort,online_at desc')
                ->findAll();

            /** @var Product[] $productList */

            //查询商品库存
            $productArr = ProductService::handleProductReturnList($productList);

        }

        $data = [
            'list' => $productArr,
            'page' => $dataProvider->getPage(),
        ];

        Cache::set($cacheKey, $data, 30);//秒

        return Json::renderWithTrue($data);
    }
}