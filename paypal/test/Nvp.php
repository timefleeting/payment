<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-31 13:55:07 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-10-26 18:01:51
 */

namespace Netflying\PaypalTest;

use Netflying\Payment\common\Utils;
use Netflying\Payment\common\Request;

use Netflying\Paypal\data\NvpMerchant;
use Netflying\PaymentTest\Data;

class Nvp
{

    protected $url = '';

    public $type = 'Paypal';

    public $typeClass = "Nvp";

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
    public function setMerchant($realMerchant = [])
    {
        $merchant = [
            'type' => 'Paypal',
            'type_id' => 'Paypal',
            'is_test' => 1,
            'call_route' => $this->url,
            'merchant' => 'sb-iatgu5187084@business.example.com',
            'api_account' => [
                'version' => '**',
                'user' => '**',
                'password' => '***',
                'signature' => '****',
            ],
            'api_data' => [
                'brand_name' => 'callie',
                'endpoint' => 'https://api-3t.sandbox.paypal.com/nvp',
                'token_direct' => 'https://www.sandbox.paypal.com/webscr?cmd=_express-checkout&useraction=commit&token={$token}',
            ],
            'type_info' => [
                'title' => 'paypal',
            ]
        ];
        $this->merchant = Utils::arrayMerge($merchant,$realMerchant);
        return $this;
    }
    /**
     * 提交支付
     *
     * @return void
     */
    public function pay()
    {
        $Instance = $this->getInstance();
        $PaypalLog = new Log;
        $Data = new Data;
        $Order = $Data->order();
        $redirect = $Instance->log($PaypalLog)->purchase($Order);
        return $redirect;
    }
    
    /**
     * 登录地址授权
     *
     * @return void
     */
    public function authoirzation()
    {
        $PaypalLog = new Log;
        $Data = new Data;
        $Order = $Data->order();
        $redirect = $this->getInstance()->log($PaypalLog)->authoirzation($Order);
        return $redirect;
    }
    /**根据token获取详情 */
    public function tokenDetails($token)
    {
        return $this->getInstance()->tokenCheckoutDetails($token);
    }
    /**
     * 根据token获取地址
     */
    public function tokenAddress($token)
    {
        return $this->getInstance()->tokenAddress($token);
    }
    /**
     * 最后确认提交支付
     *
     * @return void
     */
    public function continue()
    {
        $data = Utils::mapData([
            'type' => '',
            'act' => '',
            'sn' => '',
        ],Request::receive());
        if ($data['act']=='return_url') {
            return $this->getInstance()->doPurchase();
        }
        return Request::receive();
    }
    /**
     * 支付回调
     *
     * @return void
     */
    public function notify()
    {
        return $this->getInstance()->notify();
    }

    protected function getInstance()
    {
        $Merchant = new NvpMerchant($this->merchant);
        $class = "Netflying\\" . $this->type . "\\lib\\" . $this->typeClass;
        $Payment = new $class($Merchant);
        return $Payment;
    }

}
