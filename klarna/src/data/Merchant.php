<?php

namespace Netflying\Klarna\data;

use Netflying\Payment\data\Merchant as MerchantModel;

/**
 * 支付通道基础数据结构
 */
class Merchant extends MerchantModel
{
    protected $apiAccount = [
        'username' => 'string',
        'password' => 'string',
    ];
    protected $apiAccountNull = [
        'username' => null,
        'password' => null,
    ];
    protected $apiData = [
        /**
         * API请求的URL token变量自动被apiData['authorization_token']替换
         * /payments/v1/authorizations/{$token}/order
         */
        'endpoint' => 'string',
        /**
         * sandbox: https://api.playground.klarna.com
         * live: https://api.klarna.com
         */
        'endpoint_domain' => 'string',
        /**
         * 初始化js sdk需要的token
         * /payments/v1/sessions
         */
        'client_session_url' => 'string',
        /**
         * capture地址
         * /ordermanagement/v1/orders/{$id}/captures
         */
        'capture_url' => 'string',
        /**
         * 查询订单状态地址
         * /ordermanagement/v1/orders/{$id}
         */
        'orders_url' => 'string',
        /**
         * js sdk获取的token授权码
         * 初始化可以先为空, 执行对象时必须有值。
         */
        'authorization_token' => 'string',
    ];
    protected $apiDataNull = [
        'endpoint'   => '',
        'endpoint_domain' => '',
        'client_session_url' => '',
        'capture_url' => '',
        'orders_url' => '',
        'authorization_token' => '',
    ];
}
