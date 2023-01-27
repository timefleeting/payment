<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-31 13:55:07 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-06-10 17:01:04
 */

namespace Netflying\AfterpayTest;

use Netflying\Payment\common\Utils;
use Netflying\PaymentTest\Data;

use Netflying\Afterpay\data\Merchant;

class Afterpay
{

    protected $url = '';

    public $type = 'Afterpay';

    public $typeClass = "Afterpay";

    protected $merchant = [];


    /**
     * @param $url 回调通知等相对路径
     *
     * @param string $url 站点回调通知相对路径
     */
    public function __construct($url = '')
    {
        $this->url = $url;
    }

    /**
     * 商家数据结构
     *
     * @return this
     */
    public function setMerchant(array $realMerchant = [])
    {
        /**
         * test: https://api.us-sandbox.afterpay.com   https://global-api-sandbox.afterpay.com
         * live: https://api.us.afterpay.com   https://global-api.afterpay.com
         */
        $merchant = [
            'type' => $this->type,
            'type_class' => $this->typeClass,
            'type_id' => $this->type,
            'is_test' => 1,
            'merchant' => '****',
            'call_route' => $this->url,
            'api_account' => [
                'merchant_id' => '*****',
                'secret_key' => '*****',
            ],
            'api_data' => [
                'endpoint_domain' => 'https://api.us-sandbox.afterpay.com',
                'endpoint'   => '/v2/checkouts',
                'capture_url' => '/v2/payments/capture',
            ],
            'type_info' => [
                'title' => $this->type
            ]
        ];
        $merchant = Utils::arrayMerge($merchant, $realMerchant);
        $this->merchant = $merchant;
        return $this;
    }

    /**
     * 提交支付
     *
     * @return Redirect
     */
    public function pay()
    {
        $Data = new Data;
        $Order = $Data->order();
        $Log = new Log;
        $redirect = $this->getInstance()->log($Log)->purchase($Order);
        return $redirect;
    }

    protected function getInstance()
    {
        $Merchant = new Merchant($this->merchant);
        $class = "Netflying\\" . $this->type . "\\lib\\" . $this->typeClass;
        $Payment = new $class($Merchant);
        return $Payment;
    }

    
}
