<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-31 13:55:07 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-06-14 17:06:39
 */

namespace Netflying\KlarnaTest;

use Netflying\Payment\common\Utils;
use Netflying\PaymentTest\Data;

use Netflying\Klarna\data\Merchant;

class Klarna
{

    protected $url = '';

    public $type = 'Klarna';

    public $typeClass = 'Klarna';

    protected $merchant = [];

    /**
     * @param $url 回调通知等相对路径
     *
     * @param string $url 站点回调通知相对路径
     */
    public function __construct($url='')
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
        // $liveApiUrl = [
        //     'eu' => "https://api.klarna.com/",
        //     'na' => "https://api-na.klarna.com/",
        //     'oc' => "https://api-oc.klarna.com/",
        // ];
        // $testApiUrl = [
        //     'eu' => "https://api.playground.klarna.com/",
        //     'na' => "https://api-na.playground.klarna.com/",
        //     'oc' => "https://api-oc.playground.klarna.com/",
        // ];
        $merchant = [
            'type' => $this->type,
            'type_class' => $this->type,
            'call_route' => $this->url,
            'is_test' => 1,
            'merchant' => '****',
            'api_account' => [
                'username' => '*****',
                'password' => '*****',
            ],
            'api_data' => [
                'endpoint_domain' => 'https://api.playground.klarna.com',
                'endpoint'   => '/payments/v1/authorizations/{$token}/order',
                'client_session_url' => '/payments/v1/sessions',
                'capture_url' => '/ordermanagement/v1/orders/{$id}/captures',
                'orders_url' => '/ordermanagement/v1/orders/{$id}',
                'authorization_token' => '',
            ],
            'type_info' => [
                'title' => $this->type
            ]
        ];
        $merchant = Utils::arrayMerge($merchant,$realMerchant);
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
