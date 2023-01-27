<?php

namespace Netflying\Afterpay\data;

use Netflying\Payment\data\Merchant as MerchantModel;

/**
 * 支付通道基础数据结构
 */
class Merchant extends MerchantModel
{
    protected $apiAccount = [
        'merchant_id' => 'string',
        'secret_key' => 'string',
    ];
    protected $apiAccountNull = [
        'merchant_id' => null,
        'secret_key' => null,
    ];
    protected $apiData = [
        /**
         * API请求的URL token变量自动被apiData['authorization_token']替换
         * /v2/checkouts
         */
        'endpoint' => 'string',
        /**
         * test: https://api.us-sandbox.afterpay.com   https://global-api-sandbox.afterpay.com
         * live: https://api.us.afterpay.com   https://global-api.afterpay.com
         */
        'endpoint_domain' => 'string',
        /**
         * capture 捕获结算订单
         * /v2/payments/capture
         */
        'capture_url' => 'string',
    ];
    protected $apiDataNull = [
        'endpoint'   => "",
        'endpoint_domain' => "",
        'capture_url' => "",
    ];
}
