<?php

namespace ApiBundle\Controller;

use Entity\Delivery;
use Leaf\DB;
use Leaf\Json;
use Leaf\Request;

class DeliveryController
{
    /**
     * 订单对应的物流信息
     *
     * method
     *      GET
     * url
     *     api/delivery?token=TOKEN
     *
     * params
     *      orderId 订单ID
     *
     * response
     *
     * ```
     *
     * {
     *      "status": true,
     *      "data": {
     *          "number": "xxx",          物流编号
     *          "carrier_info": {         物流公司
     *              "name": "xx",         名称
     *              ...
     *          },
     *          ...
     *      },
     *      "code": "0"
     * }
     *
     * ```
     *
     * @Route api/delivery
     */
    public function index(Request $request)
    {
        $orderId = (int)$request->get('orderId');

        $deliveryInfo = DB::table(Delivery::tableName())
            ->asEntity(Delivery::className())
            ->where('order_id = ?', [$orderId])
            ->findOne();

        if ($deliveryInfo == null) {
            return Json::renderWithFalse("订单无物流信息");
        }

        // 物流公司
        $deliveryInfo['carrier_info'] = $deliveryInfo->carrier;

        return Json::renderWithTrue($deliveryInfo);
    }
}