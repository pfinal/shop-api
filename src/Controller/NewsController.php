<?php

namespace ApiBundle\Controller;

use Leaf\DB;
use Leaf\Json;
use Leaf\Request;
use WechatBundle\Entity\News;

class NewsController
{
    /**
     * @Route api/news
     */
    public function index(Request $request)
    {
        $tag = (int)$request->get('tag');

        $list = DB::table(News::tableName())->asEntity(News::className())
            ->where('tag=?', $tag)
            ->where('kind=1')
            ->where('status=?', News::STATUS_YES)
            ->findAll();

        return Json::renderWithTrue($list);
    }

    /**
     * @Route api/news/detail
     */
    public function detail(Request $request)
    {
        $id = (int)$request->get('id');

        $news = DB::table(News::tableName())->asEntity(News::className())
            ->where('id=?', $id)
            ->where('kind=1')
            ->where('status=?', News::STATUS_YES)
            ->findOne();

        //更新阅读量
        if ($news != null) {
            DB::table(News::tableName())
                ->where('id=?', $news['id'])
                ->increment('page_view');
        }

        return Json::renderWithTrue($news);
    }


}