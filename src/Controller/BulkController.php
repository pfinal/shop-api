<?php

namespace ApiBundle\Controller;

use AdminBundle\Service\AddressService;
use AdminBundle\Service\OrderService;
use Entity\Address;
use Entity\BulkSku;
use Entity\Image;
use Entity\Order;
use Entity\Product;
use Entity\Sku;
use Entity\Spell;
use Entity\SpellOrder;
use Entity\User;
use Leaf\DB;
use Leaf\Json;
use Leaf\Pagination;
use Leaf\Request;
use Service\Auth;
use Service\SpellService;
use Vo\OrderCreateVo;

/**
 * 团购相关的API
 *
 * @author Wang Manyuan
 * @since 1.0
 */
class BulkController
{
    /**
     * 查询团购库存信息
     *
     * method
     *      GET
     *
     * params
     *      bulkSkuId  团购商品库存的id
     *
     *
     * url
     *       api/bulk/sku-detail?token=token
     *
     * response
     *
     * ```
     *
     * {
     *      "status": true,
     *      "data": {
     *          "bulkSkuInfo": {
     *              "product_id": x, 商品ID
     *              "sku_id": x,     库存ID
     *              "bulk_id": xxx,  团购商品ID
     *              "price": "xx",   团购价
     *              "created_at": "xxxx-xx-xx xx:xx:xx", 新增时间
     *              ...
     *          },
     *          "skuInfo": {
     *              "product_id": xx,      商品ID
     *              "color": "xx",         颜色
     *              "size": "xx",          尺码
     *              "version": "xx",       版本
     *              "quantity": xx,        库存量
     *              "price": "xx",         价格
     *              "original_price": "xx",  原价
     *              ...
     *          },
     *          "productInfo": {
     *              "category_id": x,        分类ID
     *              "brand_id": x,           品牌ID
     *              "sale_num": x,           销量
     *              "name": "xxx",           名称
     *              "cover_image_url": "http://localhost/leafshop/web/uploads/uploads/201807/13/5b4878305d437.jpg",
     *              ...
     *          }
     *      },
     *      "code": "0"
     * }
     *
     * ```
     *
     * @Route api/bulk/sku-detail
     */
    public function skuDetail(Request $request)
    {
        $bulkSkuId = (int)$request->get('bulkSkuId');

        $error = [];

        $resultData = SpellService::checkAndGetInfoForBulkSku($bulkSkuId, $error);

        if ($resultData === false) {
            return Json::renderWithFalse($error['message'], $error['code']);
        }

        return Json::renderWithTrue($resultData);
    }

    /**
     * 获取运费
     *
     * url
     *      api/bulk/express-fee?token=Token
     *
     * method
     *       GET
     *
     * params
     *       addressId        地址ID
     *       bulkSkuId        团购库存ID
     *
     * response
     *```
     * {
     *    "status": true,
     *    "data": {"enabled":true, "express_fee":"6.00", "description":"基础运费"}
     * }
     * ```
     * @Route api/bulk/express-fee
     */
    public function expressFee(Request $request)
    {
        $addressId = (int)$request->get('addressId', 0);
        $bulkSkuId = (int)$request->get('bulkSkuId');
        $quantity = 1; // 这里暂时团购只支持数量为1的

        $userId = Auth::getId();

        $address = AddressService::findOneByMemberAndId($userId, $addressId);

        if ($address == null) {
            return Json::renderWithFalse('地址有误，请重新选择');
        }

        $error = [];

        $resultData = SpellService::checkAndGetInfoForBulkSku($bulkSkuId, $error);

        if ($resultData === false) {
            return Json::renderWithFalse($error['message'], $error['code']);
        }

        $bulkSkuInfo = $resultData['bulkSkuInfo'];

        $totalFee = $bulkSkuInfo['price'] * $quantity;

        $error = '';

        $data = $data = OrderService::calcExpressFee([$resultData['productInfo']], $quantity, $totalFee, $address, $error);

        if ($data === false) {
            return Json::renderWithFalse($error);
        }

        return Json::renderWithTrue($data);
    }

    /**
     * 新增拼团订单
     *
     * method
     *      POST
     *
     * params
     * ```
     *  bulkSkuId       int     团购商品库存ID
     *  spellId         int     拼团ID  默认不传，即为开团       不传或传入为0：即为新开团；传入值：则代表拼该值对应的团购
     *  payType         int     支付类型     通过 api/order/pay-type 获取
     *  deliveryType    int     运输方式   通过 api/order/delivery-type 获取
     *  invoiceType     int     发票类型  通过 api/order/invoice-type 获取
     *  invoiceTitle    string  发票抬头 发票类型为『不开发票』时，此字段为空即可
     *  invoiceContent  string  发票内容 发票类型为『不开发票』时，此字段为空即可
     *  message         string  留言 可空
     *  addressId       int     地址id  通过api/address接口获取
     *  ticketDetailId  int     选用的卡券id  通过api/ticket/my?type=10&status=1 接口获取
     * ```
     *
     * url
     *      api/bulk/create-spell?token=TOKEN
     *
     * response
     *      {"status": true,  "data": {"number":"xxx", "order_id": "订单ID"}}
     *
     * @Route api/bulk/create-spell
     * @Method post
     */
    public function createSpell(Request $request)
    {
        //获取用户id和订单表信息
        $userId = Auth::getId();

        // 拼团ID  默认不传
        // 不传或传入为0：新开团
        // 传入值：则代表根据对应的拼团进行拼单
        $spellId = (int)$request->get('spellId', 0);

        //获取新增订单的操作对象
        $orderCreateVo = new OrderCreateVo([
            'payType' => (int)$request->get('payType', 0),
            'deliveryType' => (int)$request->get('deliveryType', 0),
            'invoiceType' => (int)$request->get('invoiceType', 0),
            'invoiceTitle' => $request->get('invoiceTitle', ''),
            'invoiceTaxpayerIdent' => $request->get('invoiceTaxpayerIdent', ''),
            'invoiceContent' => $request->get('invoiceContent', ''),
            'message' => $request->get('message', ''),
            'addressId' => (int)$request->get('addressId', 0),
            'ticketDetailId' => $request->get('ticketDetailId'),
        ]);

        $bulkSkuId = (int)$request->get('bulkSkuId'); // 商品库存ID
        $quantity = 1; // 这里暂时团购只支持数量为1的

        $error = '';
        $errorCode = '';

        $result = SpellService::create($userId, $spellId, $bulkSkuId, $quantity, $orderCreateVo, $error, $errorCode);

        if ($result === false) {
            return Json::renderWithFalse($error, $errorCode);
        }

        return Json::renderWithTrue([
            'number' => $result['order_number'],
            'order_id' => $result['order_id'],
            'spell_id' => $result['spell_id'],
        ]);
    }

    /**
     * 拼团订单
     *
     * @Route api/bulk/spell-order
     */
    public function spellOrder(Request $request)
    {
        $status = (int)$request->get('status');
        $pageSize = $request->get('pageSize', 100); // 默认为100条

        $statusList = [
            Spell::STATUS_ING,
            Spell::STATUS_SUCCESS,
            Spell::STATUS_FAIL,
        ];

        if (!in_array($status, $statusList)) {
            return Json::renderWithFalse("类型有误");
        }

        $userId = Auth::getId();

        $from = 'FROM %s AS sorder LEFT JOIN %s AS o ON sorder.order_id = o.id';
        $from = sprintf($from, SpellOrder::tableName(), Order::tableName());

        $from .= ' WHERE sorder.status != ?'; // 排除初始状态的拼团，初始状态即为为付过款的拼团数据
        $from .= ' and sorder.user_id = ? and sorder.status = ?';

        $params = [
            Spell::STATUS_DEFAULT,
            $userId,
            $status,
        ];

        $page = new Pagination();

        $page->itemCount = DB::getConnection()->queryScalar('SELECT COUNT(*) ' . $from, $params);

        $page->pageSize = $pageSize;

        $sql = 'SELECT o.*,sorder.spell_id as spell_id,sorder.id as spell_order_id ' . $from . ' ORDER BY sorder.id desc  LIMIT ' . $page->limit;

        $orderList = DB::table('')
            ->asEntity(Order::className())
            ->findAllBySql($sql, $params);

        $orderList = OrderService::handleOrderApiReturnData($orderList);

        return Json::renderWithTrue([
            'list' => $orderList,
            'page' => $page
        ]);
    }

    /**
     * 团购详情
     *
     * @ClearMiddleware token
     * @Route api/bulk/spell-order-detail
     */
    public function spellOrderDetail(Request $request)
    {
        $spellOrderId = (int)$request->get('spellOrderId');

        $spellOrder = DB::table(SpellOrder::tableName())
            ->asEntity(SpellOrder::className())
            ->where('status != ?', [Spell::STATUS_DEFAULT])
            ->where('status != ?', [Spell::STATUS_DELETE])
            ->findByPk($spellOrderId);

        if ($spellOrder == null) {
            return Json::renderWithFalse("拼团数据不存在", 'no spell_order');
        }

        $spellId = $spellOrder['spell_id'];

        // 团购信息
        // 团购订单的人员信息

        $spell = DB::table(Spell::tableName())
            ->asEntity(Spell::className())
            ->where('status != ?', [Spell::STATUS_DEFAULT])
            ->where('status != ?', [Spell::STATUS_DELETE])
            ->findByPkOrFail($spellId);

        // 团购人员信息
        $userSql = 'select user.id,user.avatar,user.nickname,user.username,user.mobile,user.email ';
        $userSql .= ' from %s as sorder left join %s as user on sorder.user_id = user.id';
        $userSql .= ' where sorder.spell_id = ? and sorder.status != ? and sorder.status != ? order by sorder.id';
        $userSql = sprintf($userSql, SpellOrder::tableName(), User::tableName());
        $userParams = [
            $spellId,
            Spell::STATUS_DEFAULT,
            Spell::STATUS_DELETE,
        ];

        $userList = DB::table('')
            ->asEntity(User::className())
            ->findAllBySql($userSql, $userParams);

        foreach ($userList as $key => $user) {
            /** @var $user User */
            $userList[$key]['avatar_url'] = $user->avatarUrl();
        }

        // 拼团订单的商品详情
        $productResult = self::spellProductDetail($spellOrder['sku_id'], $error);
        if ($productResult === false) {
            return Json::renderWithFalse($error);
        }

        // 检测是否拼过团
        $checkSpellUser = 0; // 默认没有
        $token = $request->get('token');
        if ($token) {
            //查数据库
            $tokenInfo = DB::table('token')->findOne('token=?', [$token]);

            if ($tokenInfo != null) {

                $count = DB::table(SpellOrder::tableName())
                    ->where('spell_id = ? and user_id = ?', [$spellId, $tokenInfo['user_id']])
                    ->where('status != ?', [Spell::STATUS_DELETE])
                    ->count();
                if ($count > 0) {
                    $checkSpellUser = 1;
                }
            }
        }

        return Json::renderWithTrue([
            'spell_order' => $spellOrder, // 拼团订单
            'spell' => $spell, // 团购
            'userList' => $userList, // 拼团成员信息
            'product' => $productResult['product'],
            'skuList' => $productResult['skuList'],
            'imageList' => $productResult['imageList'],
            'checkSpellUser' => $checkSpellUser,
        ]);
    }

    /**
     * 拼团订单的商品详情
     * @param $skuId
     * @param string $error
     * @return array|bool
     */
    private function spellProductDetail($skuId, &$error = '')
    {
        //接受数据，查看数据库中是否有数据
        $sku = DB::table(Sku::tableName())->findByPk($skuId);
        if ($sku == null) {
            $error = '库存信息有误';
            return false;
        }

        /** @var Product $product */
        $product = DB::table(Product::tableName())
            ->asEntity(Product::className())
            ->findByPk($sku['product_id']);

        if ($product == null) {
            $error = '商品信息不存在';
            return false;
        }

        //查询库存数据
        $skuList = DB::table(Sku::tableName())
            ->asEntity(Sku::className())
            ->where('product_id=?', [$sku['product_id']])
            ->findAll();

        if (count($skuList) <= 0) {
            $error = '商品信息有误';
            return false;
        }

        //查询图片数据
        $imageList = $product->apiShowImg();

        // 将每个库存对应的团购信息查询添加上
        foreach ($skuList as $key => $val) {

            /** @var $val Sku */

            $skuList[$key]['bulk_sku_info'] = $val->bulkSkuInfo();
        }

        return [
            'product' => $product,
            'skuList' => $skuList,
            'imageList' => $imageList,
        ];
    }

}