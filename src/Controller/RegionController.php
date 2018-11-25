<?php

namespace ApiBundle\Controller;

use Entity\Region;
use Leaf\DB;
use Leaf\Json;
use Leaf\Request;
use Leaf\Util;
use PFinal\Ip2Region\Ip2Region;
use Service\RegionService;

class RegionController
{

    /**
     * 区域 (获取省市区信息)
     * method
     *     GET
     *
     * url
     *     api/region?parentCode=PARENT_CODE
     *
     * 中国的code为0,获取中国所有的省，parentCode值入0即可
     * 获取北京有哪些区，parentCode传入北京的code
     *
     * response
     *
     * {"status":true,"data":{"2":"北京","3":"安徽","4":"福建","5":"甘肃"}}
     *
     * @Route api/region
     */
    public function region(Request $request)
    {
        $parentCode = (int)$request->get('parentCode', 0);
        $regionList = DB::table(Region::tableName())
            ->where('parent_code =?', [$parentCode])
            ->findAll();

        $arr = [];
        foreach ($regionList as $item) {
            $arr[$item['code']] = $item['name'];
        }

        return Json::renderWithTrue($arr);
    }

    /**
     * 区域code转名称
     *
     * method
     *     GET
     *
     * url
     *     api/region/detail?codes=6,76
     *
     * response
     *
     * {"status":true,"data":{"6":"广东","76":"广州"}}
     *
     * @Route api/region/detail
     */
    public function regionDetail(Request $request)
    {
        $codes = explode(',', $request->get('codes'));
        if (count($codes) == 0) {
            return Json::renderWithFalse('参数错误');
        }

        $regionList = DB::table(Region::tableName())
            ->whereIn('code', $codes)
            ->findAll();

        return Json::renderWithTrue(Util::arrayColumn($regionList, 'name', 'code'));
    }

    /**
     * 所有地址信息
     *      省
     *          市
     *              区
     *
     * method
     *     GET
     *
     * url
     *     api/region/code-list
     *
     * response
     *
     * {
     *      "status": true,
     *      "data": [
     *          {
     *              "name": "北京市",
     *              "code": "xx",
     *              "children": [    // 下级数据
     *                  {
     *                      "name": "市区",
     *                      "code": "xx",
     *                      "children": [
     *                          {
     *                              "name": "市区",
     *                              "code": "xx",
     *                          },
     *                          ...
     *                      ]
     *                  },
     *                  ...
     *              ]
     *          },
     *          ...
     *      ]
     * }
     *
     * @Route api/region/code-list
     */
    public function codeList()
    {
        $regionList = RegionService::codelist();

        return Json::renderWithTrue($regionList);
    }

    /**
     * 根据 访客IP 定位 地址
     *
     * @Route api/region/info-by-ip
     */
    public function infoByIp(Request $request)
    {
//        $ip = $request->getClientIps();
//        $ip = end($ip);

        // 20181105 修改 当$_SERVER中有HTTP_X_FORWARDED_FOR，则优先使用该值
        $serverInfo = $_SERVER;
        if(isset($serverInfo['HTTP_X_FORWARDED_FOR']) && ($serverInfo['HTTP_X_FORWARDED_FOR'])) {
            $ip = $serverInfo['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $request->getClientIps();
            $ip = end($ip);
        }


        $location = [
            'code' => 11,
            'name' => '北京市'
        ];

        if ((!$ip) || ($ip == 'localhost')) {
            // 没有访客IP，默认为北京市
            return Json::renderWithTrue($location);
        }

        $ip2region = new Ip2Region();

        $info = $ip2region->btreeSearch($ip);

        /**
         * array(2) { ["city_id"]=> int(1015) ["region"]=> string(40) "中国|华东|江苏省|南京市|联通" }
         *
         * 没有数据的时候，city_id为0
         */

        if ((!isset($info['city_id'])) || (!isset($info['region']))) {
            return Json::renderWithTrue($location);
        }

        if (!$info['city_id']) {
            return Json::renderWithTrue($location);
        }

        // 处理，第三个为一级
        $arr = explode( '|',$info['region']);

        if (count($arr) < 3) {
            return Json::renderWithTrue($location);
        }

        $name = $arr[2];

        // 去数据库查询数据
        $region = RegionService::findParentOneByName($name);

        if ($region == null) {
            return Json::renderWithTrue($location);
        }

        $location = [
            'code' => $region['code'],
            'name' => $region['name'],
        ];

        return Json::renderWithTrue($location);
    }

}