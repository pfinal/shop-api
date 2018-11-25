<?php

namespace ApiBundle\Controller;

use AdminBundle\Service\OrderService;
use AdminBundle\Service\ProductService;
use Entity\Category;
use Entity\Comment;
use Entity\Config;
use Entity\Product;
use Entity\ProductContent;
use Entity\Sku;
use Entity\Spell;
use Leaf\Cache;
use Leaf\DB;
use Leaf\Json;
use Leaf\Pagination;
use Leaf\Request;
use Leaf\Util;
use Service\Auth;
use Service\CategoryService;
use Service\RegionService;

class ProductController
{
    /**
     * 商品列表
     *
     * method
     *      GET
     *
     * params
     *
     *      categoryId  分类id
     *      page  当前页码 可选默认为1
     *      pageSize  可选参数默认为20
     *
     * url
     *       api/product
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
     * @Route api/product
     */
    public function index(Request $request)
    {
        $categoryId = $request->get('categoryId', 0);
        $pageSize = $request->get('pageSize', 20);
        $currentPage = (int)$request->get('page', 1); // 20180701 带上页码做缓存的key

        $cacheKey = 'api.product.' . $categoryId . '.pageSize.' . $pageSize . '.page.' . $currentPage;
        $data = Cache::get($cacheKey);
        if ($data) {
            return Json::renderWithTrue($data);
        }

        $categoryIds = [$categoryId];

        //查询子分类Ids
        if ($categoryId != 0) {
            $categoryList = DB::table(Category::tableName())->field('id')->where('path like :path', ['0,' . $categoryId . '%'])->findAll();
            $categoryIds = array_merge($categoryIds, array_column($categoryList, 'id'));
        }

        $query = DB::table(Product::tableName())
            ->asEntity(Product::className())
            ->where('status=?', [Product::STATUS_DISPLAY]);

        if ($categoryId != 0) {
            $query = $query->whereIn('category_id', $categoryIds);
        }

        $queryCount = clone $query;
        $page = new Pagination();
        $page->pageSize = $pageSize;
        $page->itemCount = $queryCount->count();
        $page->currentPage = $currentPage; // 20180701 添加

        /** @var Product[] $productList */
        $productList = $query->limit($page->limit)->orderBy('sort,online_at desc')->findAll();

        //查询商品库存
        $productArr = ProductService::handleProductReturnList($productList);

        $data = [
            'list' => $productArr,
            'page' => $page,
        ];

        Cache::set($cacheKey, $data, 30);//秒

        return Json::renderWithTrue($data);
    }

    /**
     * 商品搜索
     *
     * method
     *      GET
     *
     * params
     *      keyword 搜索关键字
     *      price_begin 最低价
     *      price_end  最高价
     *      category_id 分类Id  ：  这个会查询出该分类下以及该分类下的子级分类中的商品
     *
     *      page  当前页码 可选默认为1
     *      pageSize  可选参数默认为20
     *
     * url
     *       api/product/search
     *
     * response
     *
     *```
     * {
     *   "status": true,
     *   "data": {
     *           "list": [
     *           {
     *               "skuId": 1001,
     *               "name": "xxx商品",
     *               "price": 100.2
     *               "image": 201612/20/5836ecc62d137.jpg,
     *           },
     *          {
     *               "skuId": 1002,
     *               "name": "xxx商品2",
     *               "price": 100.2
     *              "image": 201612/20/5836ecc62d137.jpg,
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
     *
     * @Route api/product/search
     */
    public function search(Request $request)
    {
        //接收数据
        $keyword = $request->get('keyword');
        $price_begin = $request->get('price_begin');
        $price_end = $request->get('price_end');
        $categoryId = (int)($request->get('category_id', 0));

        $query = DB::table(Product::tableName())->where('status=?', [Product::STATUS_DISPLAY]);

        // 分类
        if ($categoryId) {

            // 查询该分类以及分类下的子分类
            $children = CategoryService::getChildren($categoryId);
            $categoryIds = Util::arrayColumn($children, 'id');
            $categoryIds[] = $categoryId; // 加入父类分类ID本身
            $categoryIds = array_unique($categoryIds);

            if (count($categoryIds) <= 0) {
                $query->where('category_id = -1');
            } else {
                $query->whereIn('category_id', $categoryIds);
            }

        }

        //模糊查询数据库关键字匹配的数据
        if (!empty($keyword)) {
            $query->where('name like ?', ['%' . $keyword . '%']);
        }

        //模糊查询数据库价格匹配的数据
        if (!empty($price_begin)) {
            $query->where('price >= ?', [$price_begin]);

        }

        if (!empty($price_end)) {
            $query->where('price <= ?', [$price_end]);

        }

        //查询商品的信息以及分页
        $page = new Pagination();
        $page->pageSize = $request->get('pageSize', 20);
        $queryCount = clone $query;
        $page->itemCount = $queryCount->count();

        /** @var Product[] $productList */
        $productList = $query
            ->limit($page->limit)
            ->asEntity(Product::className())
            ->orderBy('sort,online_at desc')  // 20181105 根据商品的上架时间展示
            ->findAll();

        //查询商品的封面图片信息
        $productArr = ProductService::handleProductReturnList($productList);

        $data['list'] = $productArr;
        $data['page'] = $page;

        return Json::renderWithTrue($data);
    }

    /**
     * 商品详情
     *
     * method
     *      GET
     *
     * params
     *      skuId  库存的id
     *
     *
     * url
     *       api/product/detail?skuId=1001
     *
     * response
     *
     * ```
     * {
     *   "status": true,
     *   "data": {
     *           "product":
     *           {
     *               "id": 1001,
     *               "name": "xxx商品",
     *               "category_id": "分类id",
     *               "content":"xxxxxxxxxxxxxx"
     *              },
     *         "skuList": [
     *              {
     *            "color": "红色",
     *           "size": "3.5英寸",
     *           "version": "国行",
     *           "quantity": 120,
     *           "price": 500
     *          },
     *          {
     *            "color": "黑色",
     *           "size": "3.5英寸",
     *           "version": "国行",
     *           "quantity": 120,
     *           "price": 600
     *          }
     *           ],
     *          "imageList": [
     *               {
     *                  "type": 2,  // 1：主图；2：详情
     *                  "file": "xxx",
     *                  "url": "图片地址",
     *              },
     *              ...
     *          ],
     *          "contentList": [
     *              {
     *                  "url": "xxx",    图文详情的图片URL
     *                  ...
     *              }
     *          ]
     *          'customerTel':'客服电话',
     *          'timelimitInfo':'秒杀信息',
     *
     *       }
     *}
     *```
     *
     * @Route api/product/detail
     */
    public function detail(Request $request)
    {
        //接受数据，查看数据库中是否有数据
        $skuId = $request->get('skuId');
        $sku = DB::table(Sku::tableName())
            ->findByPk($skuId);
        if ($sku == null) {
            return Json::renderWithFalse('数据信息不存在', 'no sku');
        }

        if ($sku['status'] != Sku::STATUS_DISPLAY) {
            return Json::renderWithFalse('该库存已被删除', 'sku status');
        }

        /** @var Product $product */
        $product = DB::table(Product::tableName())
            ->asEntity(Product::className())
            ->where('status=?', [Product::STATUS_DISPLAY])
            ->findByPk($sku['product_id']);

        if ($product == null) {
            return Json::renderWithFalse('商品信息不存在');
        }

        //查询库存数据
        $tempSkuList = DB::table(Sku::tableName())
            ->asEntity(Sku::className())
            ->where('product_id=?', [$sku['product_id']])
            ->where('status=?', [Sku::STATUS_DISPLAY])
            ->findAll();

        if (count($tempSkuList) <= 0) {
            return Json::renderWithFalse("商品信息有误", 'no sku data');
        }

        // 20181031 只展示有库存的属性
        $skuList = [];

        foreach ($tempSkuList as $tempSku) {
            // 排除掉没有剩余数量的库存
            if ($tempSku['quantity'] <= 0) {
                continue;
            }

            $skuList[] = $tempSku;
        }

        // 检测将当前查询的库存信息放入数组
        $tempSkuList = Util::arrayColumn($tempSkuList, null, 'id');

        if (!isset($tempSkuList[$skuId])) {
            return Json::renderWithFalse("当前商品参数信息有误");
        }

        $skuList = Util::arrayColumn($skuList, null, 'id');

        if (!isset($skuList[$skuId])) {
            $skuList[$skuId] = $tempSkuList[$skuId];
        }

        $skuList = array_values($skuList);

        //查询图片数据
        $imageList = $product->apiShowImg();

        // 将每个库存对应的团购信息查询添加上
        foreach ($skuList as $key => $val) {

            /** @var $val Sku */

            $skuList[$key]['bulk_sku_info'] = $val->bulkSkuInfo();
        }

        // 商品对应的拼团中的数据
        // 根据人数从多到少，新增时间从先到后，筛选出5个拼团中的数据供选择
        $spellList = DB::table(Spell::tableName())
            ->asEntity(Spell::className())
            ->where('product_id = ?', [$product['id']])
            ->where('status = ?', [Spell::STATUS_ING])
            ->where('person < person_total')
            ->where('created_at >= ?', [date('Y-m-d H:i:s', time() - Spell::EXPIRE_TIME)])// 未超时
            ->orderBy('person desc,id asc')
            ->limit(5)
            ->findAll();

        // 查询出每个团的团长信息
        foreach ($spellList as $key => $spell) {

            /** @var $spell Spell */

            $spellList[$key]['user_info'] = $spell->userInfo();

            $spellList[$key]['user_info']['avatar_url'] = $spellList[$key]['user_info']->avatarUrl();
        }

        // 查出最新的两条评论以及评论的总量
        $commentList = DB::table(Comment::tableName())
            ->asEntity(Comment::className())
            ->where('product_id = ?', [$product['id']])
            ->orderBy('id desc')
            ->limit(2)
            ->findAll();

        /** @var Comment[] $commentList */

        // 评论人的信息
        foreach ($commentList as $key => $item) {
            $commentList[$key]['user_info'] = $item->userInfo();
        }

        $commentCount = DB::table(Comment::tableName())
            ->where('product_id = ?', [$product['id']])
            ->count();

        // 图文详情
        $contentList = DB::table(ProductContent::tableName())
            ->asEntity(ProductContent::className())
            ->where('product_id = ?', [$product['id']])
            ->findAll();

        // 服务保障
        $service = DB::table(Config::tableName())
            ->asEntity(Config::className())
            ->where('type = ?', [Config::服务保障])
            ->findOne();

        $serviceGuarantee = '';

        if ($service != null) {
            $serviceGuarantee = $service->getFileUrl();
        }

        $tempProductData = $product;
        $tempProductData['image'] = $product->getCoverImageUrl();
        $tempProductData['bulk_info'] = $product->getBulkInfo();

        // 前端展示的销量
        $tempProductData['sale_num'] = $tempProductData['sale_num'] + $tempProductData['basic_sale_num'];

        // 秒杀信息
        $timelimitInfo = $product->effectTimelimit;

        // 每个库存的秒杀数据
        foreach($skuList as $key => $value) {
            $skuList[$key]['timelimit_sku'] = $value->effectTimelimit();
        }

        //组装数据
        $data = [
            'product' => $tempProductData,
            'skuList' => $skuList,
            'imageList' => $imageList,
            'spellList' => $spellList,
            'commentList' => $commentList,
            'commentCount' => $commentCount,
            'contentList' => $contentList,
            'serviceGuarantee' => $serviceGuarantee,
            'timelimitInfo' => $timelimitInfo,
        ];

        return Json::renderWithTrue($data);
    }

    /**
     * 检测商品在该地区是否可以配送
     *
     * method
     *      GET
     *
     * params
     *      productId   商品ID
     *      regionCode  地区code
     *
     *
     * url
     *       api/product/check-region?token='xxx'
     *
     * response
     *
     * ```
     * {
     *   "status": true,
     *   "data": {
     *      'check': '1',  1表示可以；0表示禁止
     *   }
     * }
     *```
     *
     * @Route api/product/check-region
     */
    public function checkRegion(Request $request)
    {
        $productId = (int)$request->get('productId');
        $regionCode = (int)$request->get('regionCode');

        $check = RegionService::checkProductRegion($productId, $regionCode);

        if ($check) {
            return Json::renderWithTrue(['check' => 1]);
        }

        return Json::renderWithTrue(['check' => 0]);
    }

    /**
     * 检测所选商品数组中石油有无法配送的商品
     *
     * method
     *      GET
     *
     * params   由以下参数组成的JSON字符串
     *      productIds   商品IDs 商品ID 组成的数组
     *      regionCode  地区code
     *
     *
     * url
     *       api/product/check-region-fro-product-ids?token='xxx'
     *
     * response
     *
     * ```
     * {
     *   "status": true,
     *   "data": {
     *      'check': '1',  1表示可以；0表示禁止
     *      'productIds': [1001, 1002,...], // 禁止的商品IDs
     *   }
     * }
     *```
     *
     * @Route api/product/check-region-fro-product-ids
     */
    public function checkRegionForProductIds(Request $request)
    {
        $ajaxData = $request->get('data');

        $ajaxData = json_decode($ajaxData, true);

        $productIds = $ajaxData['productIds'];
        $regionCode = $ajaxData['regionCode'];

        $noProductIds = [];

        foreach ($productIds as $productId) {
            $check = RegionService::checkProductRegion($productId, $regionCode);

            if (!$check) {
                $noProductIds[] = $productId;
            }
        }

        if (count($noProductIds) <= 0) {
            return Json::renderWithTrue(['check' => 1,]);
        }

        return Json::renderWithTrue(['check' => 0, 'productIds' => $noProductIds]);
    }

    /**
     * 个人中心展示的商品
     *
     * method
     *      GET
     *
     * url
     *       api/product/list-for-person
     *
     * response
     *
     * ```
     * {
     *   "status": true,
     *   "data": [
     *      {
     *          "skuId": 1001,
     *          "name": "xxx商品",
     *          "price": 100.2
     *          "file": 201612/20/5836ecc62d137.jpg,
     *      },
     *      {
     *          "skuId": 1002,
     *          "name": "xxx商品2",
     *          "price": 100.2
     *         "file": 201612/20/5836ecc62d137.jpg,
     *      },
     *      ...
     *   ]
     * }
     *```
     *
     * @Route api/product/list-for-person
     */
    public function listForPerson(Request $request)
    {
        $productList = DB::table(Product::tableName())
            ->asEntity(Product::className())
            ->where('status=?', [Product::STATUS_DISPLAY])
            ->orderBy('sort,online_at desc') // 20181105 根据商品的上架时间展示
            ->limit(6)
            ->findAll();

        /** @var Product[] $productList */

        //查询商品库存
        $productArr = ProductService::handleProductReturnList($productList);

        return Json::renderWithTrue($productArr);
    }

    /**
     * 获取商品的购买上限
     *
     * method
     *      GET
     *
     * params
     *      productId   商品ID
     *
     * url
     *     api/product/had-buy-num
     *
     * response
     *
     * ```
     * {
     *   "status": true,
     *   "data": "会员已经购买的数量"
     * }
     *```
     *
     * @Middleware token
     * @Route api/product/had-buy-num
     */
    public function hadBuyNum(Request $request)
    {
        $productId = $request->get('productId', 0);
        $productId = (int)$productId;

        $userId = Auth::getId();

        $hadBuyNum = OrderService::getHadBuyNum($userId, $productId);

        return Json::renderWithTrue($hadBuyNum);
    }

//
//    /**
//     * 新品推荐的数据接口
//     *
//     * method
//     *      GET
//     * params
//     *      type  new|hot
//     *      limit  传过来的前台要显示的最新的数量，可不填，默认10
//     * url
//     *      api/product/top
//     *
//     * response
//     *
//     * ```
//     * {
//     *   "status": true,
//     *   "data": {
//     *           "list":[
//     *           {
//     *                "skuId":1001,
//     *                "name":清华同方最新的k98电脑
//     *                "price":4000
//     *                "image": 201612/20/5836ecc62d137.jpg,
//     *
//     *              }，
//     *               {
//     *                "skuId":1002,
//     *                "name":联想最新的BBEK
//     *                "price":6000
//     *                "image": 201612/20/5836ecc62d137.jpg,
//     *              }，
//     *              {
//     *                 "skuId":1003,
//     *                "category_id":1001
//     *                "name":苹果最新的A1800
//     *                 "price":16000
//     *                   "image": 201612/20/5836ecc62d137.jpg,
//     *              },
//     *              {
//     *                "skuId":1004,
//     *                "name":酷派最新的款型
//     *                "price":1900
//     *                "image": 201612/20/5836ecc62d137.jpg,
//     *              }，
//     *                {
//     *                "skuId":1005,
//     *                "name":小米最新的款型
//     *                 "price":2000
//     *                 "image": 201612/20/5836ecc62d137.jpg,
//     *              }，
//     *              {
//     *                 "skuId":1006,
//     *                "name":苹果最新的款型
//     *                 "price":7000
//     *                 "image": 201612/20/5836ecc62d137.jpg,
//     *              },
//     *          ]
//     *
//     *
//     *   }
//     * }
//     *```
//     *
//     * @Route api/product/top
//     */
//    public function top(Request $request)
//    {
//        $limit = $request->get('limit', 10);
//        $type = $request->get('type');
//
//        $cacheKey = 'productNew' . $type . $limit;
//
//        $data = Cache::get($cacheKey);
//        if ($data !== false) {
//            $arr['list'] = $data;
//            return Json::renderWithTrue($arr);
//        }
//
//        switch ($type) {
//            case 'hot':
//                $productList = DB::table(Product::tableName())
//                    ->where('status=?', [Product::STATUS_DISPLAY])
//                    ->orderBy('-id')
//                    ->limit($limit)
//                    ->findAll();
//                break;
//            case 'new':
//                $productList = DB::table(Product::tableName())
//                    ->where('status=?', [Product::STATUS_DISPLAY])
//                    ->orderBy('-id')
//                    ->limit($limit)
//                    ->findAll();
//                break;
//            default:
//                return Json::renderWithFalse('type error');
//        }
//
//        if (count($productList) <= 0) {
//            return Json::renderWithTrue([
//                'list' => []
//            ]);
//        }
//
//        $data = [];
//
//        //查询封面图片，组装数据
//        foreach ($productList as $key => $product) {
//
//            //库存
//            $sku = DB::table(Sku::tableName())->where('product_id=?', [$product['id']])->where('status = ?', [Sku::STATUS_DISPLAY])->limit($limit)->findOne();
//            if ($sku == null) {
//                continue;
//            }
//            $data[$key] = [
//                'skuId' => $sku['id'],
//                'name' => $product['name'],
//                'price' => $product['price'],
//                'image' => '',
//            ];
//
//            //图片
//            //type为1表示主图
//            $image = DB::table(\Entity\Image::tableName())->where('product_id=? and type=?', [$product['id'], \Entity\Image::TYPE_COVER])->findOne();
//            if ($image != null) {
//                $data[$key]['image'] = Url::asset('uploads/avatar/' . dirname($image['file']) . '/m/' . basename($image['file']), true);
//            }
//        }
//
//
//        //Cache::set($cacheKey, $data, 60);
//
//        $arr['list'] = $data;
//        return Json::renderWithTrue($arr);
//    }

}