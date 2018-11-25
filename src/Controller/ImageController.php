<?php

namespace ApiBundle\Controller;

use Leaf\Application;
use Leaf\Json;
use Leaf\Request;
use Leaf\Url;

class ImageController
{
    /**
     * 生成二维码
     *
     * composer require bacon/bacon-qr-code
     *
     * @Route api/image/qrcode
     */
    public function qrcode(Request $request)
    {
        $str = $request->get('content');

        $encode = $request->get('encode', '');
        if ($encode == 'base64') {
            $str = base64_decode($str);
        }

        $renderer = new \BaconQrCode\Renderer\Image\Png();
        $renderer->setHeight(256);
        $renderer->setWidth(256);

        $writer = new \BaconQrCode\Writer($renderer);

        //生成到文件
        //$writer->writeFile('Hello World!', 'qrcode.png');

        //输出到浏览器
        header('content-type: image/png');

        return $writer->writeString($str);
    }


    /**
     * 图片验证码
     *
     * method
     *      GET
     *
     * url
     *      api/image/captcha
     *
     * response
     *      {"status": true, "data":{"key":"xxx", "url":"http://www.example.com/xxx"}}
     *
     * @Route api/image/captcha
     * @Method get
     */
    public function captcha(Application $app)
    {
        $key = \Util\Captcha::makeKey();
        return Json::renderWithTrue(['key' => $key, 'url' => Url::to('api/captcha/show', ['key' => $key], true)]);
    }

    /**
     * 显示验证码图片
     * @Route api/captcha/show
     */
    public function showCaptcha(Request $request)
    {
        \Util\Captcha::showImage($request->get('key'));
    }
}