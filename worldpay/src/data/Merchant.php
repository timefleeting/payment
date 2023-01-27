<?php
/*
 * @Author: He bin 
 * @Date: 2022-01-26 15:15:22 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-09-23 17:01:41
 */

namespace Netflying\Worldpay\data;

use Netflying\Payment\data\Merchant as MerchantModel;

/**
 * 支付通道基础数据结构
 */
class Merchant extends MerchantModel
{
    protected $apiAccount = [
        'user' => 'string',
        'password' => 'string',
        'installation_id' => 'string',
        'encypt_key' => 'string',
    ];
    protected $apiAccountNull = [
        'user' => null,
        'password' => null,
        'installation_id' => null,
        'encypt_key' => null, //验证码
    ];
    protected $apiData = [
        /**
         * API请求的URL. 支持使用占位符自动填充
         * live: https://%s:%s@secure.worldpay.com/jsp/merchant/xml/paymentService.jsp
         * sandbox: https://%s:%s@secure-test.worldpay.com/jsp/merchant/xml/paymentService.jsp
         */
        'endpoint' => 'string',
        //flex 3ds api配置参数. 为空不走3ds
        'api_3ds' => 'array',
    ];
    protected $apiDataNull = [
        'endpoint'   => '',
        'api_3ds' => [], //为空3ds强制不开启
    ];

    protected $api3ds = [
        'iss' => 'string',
        'org_unit_id' => 'string',
        'jwt_mac_key' => 'string',
        //3ds form提交地址
        'challege_url' => 'string',
        //3ds 设备收集地址
        'collect_url' => 'string',
        //giropay
        'swift_code' => 'string',
    ];

    protected $api3dsNull = [
        'iss' => '',
        'org_unit_id' => '',
        'jwt_mac_key' => '',
        'challege_url' => '',
        'collect_url' => '',
        'swift_code' => ''
    ];

    protected $threedsParams = [
        'jwt' => 'string',
    ];
    
    protected $threedsParamsNull = [
        'jwt' => ''
    ];

}
