<?php

namespace ApiBundle\Controller;

use Entity\Category;
use Leaf\DB;
use Leaf\Json;
use Leaf\Request;
use Service\CategoryService;

class CategoryController
{
    /**
     * 分类信息
     *
     * url
     *      api/category
     *
     * method
     *      GET
     *
     * response
     *
     *```
     * {
     *    "status": true,
     *    "data": [
     *        {
     *              "id":"ID",
     *              "name":"分类名称",
     *              "parent_id":"父级",
     *              "property":"属性",
     *        }
     *    ]
     * }
     * ```
     *
     * @Route api/category
     */
    public function index(Request $request)
    {
        $parentId = (int)$request->get('pid', 0);

        $categoryList = DB::table(Category::tableName())
            ->asEntity(Category::className())
            ->where('parent_id=?', $parentId)
            ->where('status=?', [Category::STATUS_DISPLAY])
            ->orderBy('sort')
            ->findAll();

        return Json::renderWithTrue($categoryList);
    }

    /**
     * 根据父级ID，获取所有子级数据(包括直属子级和附属子级)
     *
     * url
     *      api/category/children
     *
     * method
     *      GET
     *
     * params
     *      pid 父级ID
     *
     * response
     *
     *```
     * {
     *    "status": true,
     *    "data": [
     *        {
     *              "id":"ID",
     *              "name":"分类名称",
     *              "parent_id":"父级",
     *              "property":"属性",
     *        }
     *    ]
     * }
     * ```
     *
     * @Route api/category/children
     */
    public function children(Request $request) {

        $pid = (int)$request->get('pid');

        if(!$pid) {
            return Json::renderWithFalse("请传入父级数据");
        }

        $data = CategoryService::getChildren($pid);

        return Json::renderWithTrue($data);
    }

}