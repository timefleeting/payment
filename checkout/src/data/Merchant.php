<?php

namespace Netflying\Checkout\data;

use Netflying\Payment\data\Merchant as MerchantModel;

/**
 * 支付通道基础数据结构
 */
class Merchant extends MerchantModel
{
    protected $apiAccount = [
        "public_id" => 'string',
        "public_value" => 'string',
        "secret_id" => 'string',
        "secret_value" => 'string',
        "access_id" => 'string',
        "access_value" => 'string',
        "authorization_key" => 'string',
        "signature_key" => 'string',
        "processing_channel_id" => 'string', //The processing channel to be used for the payment. 创建public_id时可获得
        'is_3ds' => 'bool'
    ];
    protected $apiAccountNull = [
        "public_id" => null,
        "public_value" => null,
        "secret_id" => null,
        "secret_value" => null,
        "access_id" => null,
        "access_value" => null,
        "authorization_key" => null,
        "signature_key" => null,
        "processing_channel_id" => "",
        'is_3ds' => 0
    ];
    protected $apiData = [];
    protected $apiDataNull = [];
}
