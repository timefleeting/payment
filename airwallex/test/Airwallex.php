<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-31 13:55:07 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-06-13 00:40:14
 */

namespace Netflying\AirwallexTest;

use Netflying\Payment\common\Utils;
use Netflying\PaymentTest\Data;
use Netflying\Payment\common\Openssl;

use Netflying\Airwallex\data\Merchant;

use Netflying\Payment\data\CreditCard;
use Netflying\Payment\data\CreditCardSsl;

class Airwallex
{

    protected $url = '';

    public $type = 'Airwallex';

    public $typeClass = 'Airwallex';

    protected $merchant = [];

    protected $creditCard = [];

    protected $CreditCardSsl = [];

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
        $merchant = [
            'type' => $this->type,
            'type_class' => $this->typeClass,
            'call_route' => $this->url,
            'is_test' => 1,
            'merchant' => '****',
            'api_account' => [
                'client_id' => '*****',
                'api_key' => '*****',
                'publishable_key' => '*****',
                'webhook_secret' => '*****',
            ],
            'api_data' => [
                'endpoint_domain' => 'https://pci-api-demo.airwallex.com',
                'endpoint'   => '/api/v1/pa/payment_intents/create',
                'endpoint_confirm' => '/api/v1/pa/payment_intents/{$id}/confirm',
                'token_url' => '/api/v1/authentication/login',
                'org_id' => '******',
            ],
            'type_info' => [
                'title' => $this->typeClass,
            ]
        ];
        $merchant = Utils::arrayMerge($merchant, $realMerchant);
        $this->merchant = $merchant;
        return $this;
    }
    public function setCreditCard()
    {
        $this->creditCard = new CreditCard([
            'card_number'    => '4000000000000002',
            'expiry_month'   => '12',
            'expiry_year'    => '2025',
            'cvc'            => '123',
            'holder_name'    => 'join jack',
        ]);
        return $this;
    }
    public function setCreditCardSsl()
    {
        $this->setCreditCard();
        $card = $this->creditCard;
        $this->creditCardSsl = new CreditCardSsl([
            'encrypt' => Openssl::encrypt($card)
        ]);
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
        //设置卡信息
        $this->setCreditCardSsl();
        $Order->setCreditCard($this->creditCardSsl);
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
