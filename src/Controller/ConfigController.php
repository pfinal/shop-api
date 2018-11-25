<?php

namespace ApiBundle\Controller;

use Entity\Config;
use Leaf\DB;
use Leaf\Json;
use Leaf\Request;

class ConfigController
{
    /**
     * 获取对应的配置信息
     *
     * method
     *      GET
     *
     * url
     *     api/config?token=TOKEN
     *
     * params
     *      type    类型 (拼团规则：拼团规则;关于我们;关于我们)
     *
     * response
     *
     * ```
     * {
     *      "status": true,
     *      "data": {
     *          'file_url': '图片地址',
     *          ...
     *      },
     * }
     *```
     *
     * @Route api/config
     */
    public function index(Request $request)
    {
        $type = $request->get('type');

        $typeList = Config::typeList();

        if (!array_key_exists($type, $typeList)) {
            return Json::renderWithFalse("参数有误");
        }

        $entity = DB::table(Config::tableName())
            ->asEntity(Config::className())
            ->where('type = ?', [$type])
            ->findOne();

        return Json::renderWithTrue($entity);
    }

    /**
     * 配置初始化信息
     *
     * method
     *      GET
     *
     * url
     *     api/config/init
     *
     * response
     *
     * ```
     * {
     *      "status": true,
     *      "data": [,
     *          {
     *              'type': '类型', // 拼团规则、关于我们、服务保障、客服电话
     *              'content': '内容',
     *              'file_url': '图片地址',
     *              ...
     *          }
     *      ],
     * }
     *```
     *
     * @Route api/config/init
     */
    public function init(Request $request)
    {
        $typeList = [
            Config::拼团规则,
            Config::关于我们,
            Config::服务保障,
            Config::客服电话,
        ];

        $list = DB::table(Config::tableName())
            ->asEntity(Config::className())
            ->whereIn('type', $typeList)
            ->findAll();

        /** @var $list Config[] */

        $data = [];

        foreach($list as $value) {
            $data[] = [
                'type' => $value['type'],
                'content' => $value['content'],
                'file' => $value['file'],
                'file_url' => $value->getFileUrl(),
            ];
        }

        return Json::renderWithTrue($data);
    }

}