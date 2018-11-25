<?php

namespace ApiBundle\Controller;

use Carbon\Carbon;
use Entity\Address;
use Entity\Region;
use Leaf\Log;
use Leaf\Util;
use Service\Auth;
use Leaf\DB;
use Leaf\Json;
use Leaf\Request;
use Leaf\Validator;

/**
 * 用户
 * @author  Liu Zongxin
 * @since   1.0
 */
class AddressController
{

    /**
     * 我的收货地址列表
     *
     * url
     *      api/address?token=TOKEN
     *
     * method
     *      GET
     *
     * response
     *```
     * {
     *    "status": true,
     *    "data": [
     *        {
     *            "id": 1,
     *            "user_id": 1,
     *            "name": "111",
     *            "province": 0,
     *            "city": 0,
     *            "district": 0,
     *            "detail": "",
     *            "zip": "",
     *            "phone": "",
     *            "mobile": "",
     *            "email": "",
     *            "is_default": 0,
     *            "created_at": "1970-01-01 00:00:00",
     *            "updated_at": "1970-01-01 00:00:00"
     *        },
     *        {
     *            "id": 3,
     *            "user_id": 1,
     *            "name": "sffsf",
     *            "province": 0,
     *            "city": 0,
     *            "district": 0,
     *            "detail": "",
     *            "zip": "",
     *            "phone": "",
     *            "mobile": "",
     *            "email": "",
     *            "is_default": 0,
     *            "created_at": "1970-01-01 00:00:00",
     *            "updated_at": "1970-01-01 00:00:00"
     *        }
     *    ]
     * }
     * ```
     * @Route api/address
     */
    public function index()
    {
        $userId = Auth::getId();
        $AddressList = DB::table(Address::tableName())
            ->where('user_id=?', [$userId])
            ->where('status=?', [Address::STATUS_YES])
            ->orderBy('is_default desc')
            ->asEntity(Address::className())
            ->findAll();

        return Json::renderWithTrue($AddressList);
    }

    /**
     * @author  Lai Jingfeng
     *
     * 收货地址详情
     *
     * url
     *      api/address/detail?id=ID&token=TOKEN
     *
     * params
     *       id        收货地址ID
     *
     * method
     *      POST
     *
     * response
     *```
     * {
     *    "status": true,
     *    "data":
     *        {
     *            "id": 1,
     *            "user_id": 1,
     *            "name": "111",
     *            "province": 0,
     *            "city": 0,
     *            "district": 0,
     *            "detail": "",
     *            "zip": "",
     *            "phone": "",
     *            "mobile": "",
     *            "email": "",
     *            "is_default": 0,
     *            "created_at": "1970-01-01 00:00:00",
     *            "updated_at": "1970-01-01 00:00:00"
     *        }
     * }
     * ```
     * @Route api/address/detail
     */
    public function detail(Request $request)
    {
        $id = $request->get('id');

        $userId = Auth::getId();
        $address = DB::table(Address::tableName())
            ->where('user_id=?', [$userId])
            ->where('status=?', [Address::STATUS_YES])
            ->asEntity(Address::className())
            ->findByPk($id);

        if ($address == null) {
            return Json::renderWithFalse('操作的数据不存在');
        }

        return Json::renderWithTrue($address);
    }

    /**
     * 新增收货地址
     * url
     *     api/address/create?token=TOKEN
     *
     * method
     *        POST
     *
     * params
     *       name        收货人姓名
     *       province    省code
     *       city        市code
     *       district    区code
     *       detail      详细地址
     *       zip         邮编
     *       phone       电话
     *       mobile      手机
     *       email       邮箱
     *       is_default  默认地址  1
     * response
     *       {"status": true,  "data": "新增地址成功"}
     *
     * @Route api/address/create
     */
    public function create(Request $request)
    {
        $province = $request->get('province');
        $city = $request->get('city');
        $district = $request->get('district');

        $userId = Auth::getId();

        //一个用户最多10个地址
        $count = DB::table(Address::tableName())
            ->where('user_id=? and status =?', [$userId, Address::STATUS_YES])
            ->count();

        if ($count >= 10) {
            return Json::renderWithFalse('您已不能新增地址');
        }

        $provinceRegion = DB::table(Region::tableName())->where('code=?', $province)->asEntity(Address::className())->findOne();
        if ($provinceRegion == null) {
            return Json::renderWithFalse('省不能为空');
        }

        $cityRegion = DB::table(Region::tableName())->where('code=?', $city)->asEntity(Address::className())->findOne();
        if ($cityRegion == null) {
            return Json::renderWithFalse('市不能为空');
        }

        $districtRegion = DB::table(Region::tableName())->where('code=?', $district)->asEntity(Address::className())->findOne();
        if ($districtRegion == null) {
            return Json::renderWithFalse('区不能为空');
        }

        $data = [
            'name' => $request->get('name'),
            'province' => $provinceRegion->code,
            'city' => $cityRegion->code,
            'district' => $districtRegion->code,
            'detail' => $request->get('detail'),
            'zip' => $request->get('zip'),
            'phone' => $request->get('phone', ''),
            'mobile' => $request->get('mobile'),
            'email' => $request->get('email', ''),
            //第一条地址，自动为默认地址
            'is_default' => $count == 0 ? Address::DEFAULT_YES : $request->get('is_default', 0),
        ];

        $rules = [
            [['name', 'province', 'city', 'district', 'detail', 'mobile', 'is_default'], 'required'],
            [['province', 'city', 'district', 'zip', 'is_default'], 'integer'],
            [['phone', 'mobile'], 'integer'],
            [['name', 'detail', 'zip'], 'trim'],
            ['mobile', 'mobile'],
            ['email', 'email'],
            ['name', 'string', 'length' => [1, 20]],
            ['detail', 'string', 'length' => [2, 50]],
            ['is_default', 'in', 'range' => [Address::DEFAULT_YES, Address::DEFAULT_NO]]
        ];

        $labels = [
            'name' => '收货人姓名',
            'province' => '省',
            'city' => '市',
            'district' => '区县',
            'detail' => '详细地址',
            'phone' => '联系电话',
            'mobile' => '手机号',
            'email' => '邮箱',
            'zip' => '邮编',
            'is_default' => '默认地址',
        ];

        if (!Validator::Validate($data, $rules, $labels)) {
            return Json::renderWithFalse(Validator::getFirstError());
        }

        $data['user_id'] = $userId;

        //判断默认地址
        if ($data['is_default'] == Address::DEFAULT_YES) {

            //将其它地址变更为非默认(一个用户只有一个默认地址)
            DB::table(Address::tableName())
                ->where('user_id=? and status!=?', [$userId, Address::STATUS_DELETE])
                ->update(['is_default' => Address::DEFAULT_NO]);
        }

        $id = DB::table(Address::tableName())->insertGetId($data);


        if ($id > 0) {
            return Json::renderWithTrue($id);
        } else {
            return Json::renderWithFalse('系统错误,请稍后再试！');
        }
    }


    /**
     * 修改收货地址
     * url
     *     api/address/update?addressId=ADDRESS_ID&token=TOKEN
     *
     * method
     *       POST
     *
     * params
     *       name        收货人姓名
     *       province    省
     *       city        市
     *       district    区
     *       detail      详细地址
     *       zip         邮编
     *       phone       电话
     *       mobile      手机
     *       email       邮箱
     *       is_default  默认地址
     *
     * response
     *      {"status": true,  "data": "修改地址成功"}
     *
     * @Route api/address/update
     */
    public function update(Request $request)
    {
        $addressId = $request->get('addressId');

        $province = $request->get('province');
        $city = $request->get('city');
        $district = $request->get('district');

        $userId = Auth::getId();

        $arr = DB::table(Address::tableName())
            ->where('user_id=?', [$userId])
            ->findByPk($addressId);

        if ($arr == null) {
            return Json::renderWithFalse('地址数据不存在');
        }

        $provinceRegion = DB::table(Region::tableName())->where('code=?', $province)->asEntity(Address::className())->findOne();
        if ($provinceRegion == null) {
            return Json::renderWithFalse('省不能为空');
        }

        $cityRegion = DB::table(Region::tableName())->where('code=?', $city)->asEntity(Address::className())->findOne();
        if ($cityRegion == null) {
            return Json::renderWithFalse('市不能为空');
        }

        $districtRegion = DB::table(Region::tableName())->where('code=?', $district)->asEntity(Address::className())->findOne();
        if ($districtRegion == null) {
            return Json::renderWithFalse('区不能为空');
        }

        $data = [
            'name' => $request->get('name'),
            'province' => $provinceRegion->code,
            'city' => $cityRegion->code,
            'district' => $districtRegion->code,
            'detail' => $request->get('detail'),
            'zip' => $request->get('zip'),
            'phone' => $request->get('phone', ''),
            'mobile' => $request->get('mobile'),
            'email' => $request->get('email', ''),
            'is_default' => $request->get('is_default', 0),
        ];

        $rules = [
            [['name', 'province', 'city', 'district', 'detail', 'mobile', 'is_default'], 'required'],
            [['province', 'city', 'district', 'zip', 'is_default'], 'integer'],
            [['phone', 'mobile'], 'integer'],
            [['name', 'detail', 'zip'], 'trim'],
            ['mobile', 'mobile'],
            ['email', 'email'],
            ['name', 'string', 'length' => [1, 20]],
            ['detail', 'string', 'length' => [2, 60]],
            ['is_default', 'in', 'range' => [Address::DEFAULT_YES, Address::DEFAULT_NO]]
        ];

        $labels = [
            'name' => '收货人姓名',
            'province' => '省',
            'city' => '市',
            'district' => '区县',
            'detail' => '详细地址',
            'phone' => '联系电话',
            'mobile' => '手机号',
            'email' => '邮箱',
            'zip' => '邮编',
            'is_default' => '默认地址',
        ];

        if (!Validator::Validate($data, $rules, $labels)) {
            return Json::renderWithFalse(Validator::getFirstError());
        }

        //判断默认地址
        if ($data['is_default'] == Address::DEFAULT_YES) {

            //修改其它默认地址为非默认,一个用户，只有一个默认地址
            DB::table(Address::tableName())
                ->where('user_id=? and status !=? ', [$userId, Address::STATUS_DELETE])
                ->update(['is_default' => Address::DEFAULT_NO]);
        }

        $data['updated_at'] = Carbon::now();

        $rowsCount = DB::table(Address::tableName())
            ->where('id=?', [$addressId])
            ->where('user_id=?', [$userId])
            ->update($data);

        if ($rowsCount) {
            return Json::renderWithTrue('修改地址成功');
        } else {
            return Json::renderWithFalse('系统错误,请稍后再试！');
        }
    }

    /**
     * 删除收货地址
     *
     * url
     * ```
     *   api/address/delete?addressId=ADDRESS_ID&token=TOKEN
     * ```
     * method
     *       POST
     *
     * response
     *      {"status": true,  "data": "删除地址成功"}
     *      {"status": false,  "data": "原因"}
     *
     * @Route api/address/delete
     */
    public function delete(Request $request)
    {
        $addressId = $request->get('addressId');

        $userId = Auth::getId();

        $arr = DB::table(Address::tableName())
            ->where('user_id=?', [$userId])
            ->findByPk($addressId);

        if ($arr == null) {
            return Json::renderWithFalse('非法操作');
        }

        $rows = DB::table(Address::tableName())
            ->where('id = ?', [$addressId])
            ->update(['status' => Address::STATUS_DELETE, 'updated_at' => Carbon::now()]);

        if ($rows == 1) {
            return Json::renderWithTrue('删除地址成功');
        } else {
            return Json::renderWithFalse('系统错误,请稍后再试');
        }
    }

    /**
     *
     * 改变默认收货地址
     *
     * url
     *      api/address/change-default-address?id=ID&token=TOKEN
     *
     * method
     *       POST
     *
     * params
     *       id        地址ID
     *
     * response
     *```
     *      {"status": true,  "data": "修改默认收货地址成功"}
     *      {"status": false,  "data": "原因"}
     * ```
     * @Route api/address/change-default-address
     * @author  Lai Jingfeng
     */
    public function changeDefaultAddress(Request $request)
    {
        $id = $request->get('id');

        $address = DB::table(Address::tableName())
            ->where('status=?', [Address::STATUS_YES])
            ->findByPk($id);

        if ($address == null) {
            return Json::renderWithFalse('操作的数据不存在');
        }

        DB::getConnection()->beginTransaction();

        //修改其它默认地址为非默认
        $rowsCount = DB::table(Address::tableName())
            ->where('user_id=?', [$address['user_id']])
            ->update(['is_default' => Address::DEFAULT_NO, 'updated_at' => Carbon::now()]);

        if ($rowsCount <= 0) {
            DB::getConnection()->rollBack();
            return Json::renderWithFalse('修改其它默认地址为非默认失败');
        }

        $result = DB::table(Address::tableName())
            ->wherePk($address['id'])
            ->update(['is_default' => Address::DEFAULT_YES, 'updated_at' => Carbon::now()]);

        if ($result <= 0) {
            DB::getConnection()->rollBack();
            return Json::renderWithFalse('修改默认地址失败');
        }

        DB::getConnection()->commit();
        return Json::renderWithTrue('修改默认收货地址成功');
    }

    /**
     * 获取默认收货地址，无默认地址时，返回{"status": true,data:null}
     *
     * url
     *      api/address/default?token=Token
     *
     * response
     *```
     * {
     *    "status": true,
     *    "data":
     *        {
     *            "id": 1,
     *            "user_id": 1,
     *            "name": "111",
     *            "province": 0,
     *            "city": 0,
     *            "district": 0,
     *            "detail": "",
     *            "zip": "",
     *            "phone": "",
     *            "mobile": "",
     *            "email": "",
     *            "is_default": 0,
     *            "created_at": "1970-01-01 00:00:00",
     *            "updated_at": "1970-01-01 00:00:00"
     *        }
     * }
     * ```
     * @Route api/address/default
     * @author  Huang Pan
     */
    public function defaultAddress()
    {
        $userId = Auth::getId();

        //如果没有默认地址，则使用一个普通地址作为默认地址
        $defaultAddress = DB::table(Address::tableName())
            ->where('user_id=? and status=?', [$userId, Address::STATUS_YES])
            ->orderBy('is_default desc, id desc')
            ->limit(2)
            ->asEntity(Address::className())
            ->findAll();

        if (count($defaultAddress) > 0) {
            $defaultAddress = $defaultAddress[0];
        } else {
            $defaultAddress = null;
        }

        return Json::renderWithTrue($defaultAddress);
    }

}