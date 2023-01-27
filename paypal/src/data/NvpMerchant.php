<?php
/*
 * @Author: He bin 
 * @Date: 2022-01-26 15:15:22 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-11-14 15:39:54
 */

namespace Netflying\Paypal\data;

/**
 * 支付通道基础数据结构
 */
class NvpMerchant extends Merchant
{
    protected $apiData = [
        /**
         * API请求的URL,获取token,根据token查订单详情等
         * live: https://api-3t.paypal.com/nvp
         * sandbox: https://api-3t.sandbox.paypal.com/nvp
         */
        'endpoint' => 'string',
        /**
         * 取到TOKEN后需要跳转到的链接
         * 变量{$token}
         * live: https://www.paypal.com/webscr?cmd=_express-checkout&useraction=commit&token={$token}
         * sandbox: https://www.sandbox.paypal.com/webscr?cmd=_express-checkout&useraction=commit&token={$token}
         */
        'token_direct' => 'string',
        //是否显示站点名字
        'brand_name' => 'string',
        //是否显示物流信息
        'show_shipping' => 'bool',
        //回调地址
        'call_url' => 'array',
    ];
    protected $apiDataNull = [
        'endpoint' => '',
        'token_direct' => '',
        'brand_name' => '',
        'show_shipping' => true,
        'call_url' => []
    ];

}
