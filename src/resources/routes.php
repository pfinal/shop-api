<?php

use Leaf\Route;

Route::group(['middleware' => 'cors'], function () {
    Route::annotation('ApiBundle\Controller\ProductController');
    Route::annotation('ApiBundle\Controller\TimelimitController');
    Route::annotation('ApiBundle\Controller\CategoryController');
    Route::annotation('ApiBundle\Controller\ImageController');
    Route::annotation('ApiBundle\Controller\RegionController');
    Route::annotation('ApiBundle\Controller\WechatController');
    Route::annotation('ApiBundle\Controller\NewsController');
    Route::annotation('ApiBundle\Controller\ConfigController');
});

Route::group(['middleware' => ['cors', 'token']], function () {
    Route::annotation('ApiBundle\Controller\TicketController');
    Route::annotation('ApiBundle\Controller\AddressController');
    Route::annotation('ApiBundle\Controller\BulkController');
    Route::annotation('ApiBundle\Controller\CommentController');
    Route::annotation('ApiBundle\Controller\DeliveryController');
    Route::annotation('ApiBundle\Controller\TicketRushController');
});

