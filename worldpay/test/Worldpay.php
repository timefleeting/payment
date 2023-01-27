<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-31 13:55:07 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-06-11 23:45:47
 */

namespace Netflying\WorldpayTest;

use Netflying\Payment\common\Utils;
use Netflying\Payment\common\Request;
use Netflying\PaymentTest\Data;
use Netflying\Payment\common\Openssl;

use Netflying\Worldpay\data\Merchant;

use Netflying\Payment\data\CreditCard;
use Netflying\Payment\data\CreditCardSsl;
use Netflying\Payment\data\RequestCreate;

class Worldpay
{

    protected $url = '';

    public $type = 'Worldpay';

    public $typeClass = 'Worldpay';

    protected $merchant = [];

    protected $creditCard = [];

    protected $CreditCardSsl = [];

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
        $merchant = [
            'type' => $this->type,
            'type_id' => $this->typeClass,
            'call_route' => $this->url,
            'is_test' => 1,
            'merchant' => '****',
            'api_account' => [
                'user' => '****',
                'password' => '*****',
                'installation_id' => '*****',
                'encypt_key' => '*****',
            ],
            'api_data' => [
                'endpoint' => 'https://%s:%s@secure-test.worldpay.com/jsp/merchant/xml/paymentService.jsp',
                'api_3ds' => [
                    'iss' => '****',
                    'org_unit_id' => '******',
                    'jwt_mac_key' => '******',
                    'challege_url' => 'https://centinelapistag.cardinalcommerce.com/V2/Cruise/StepUp'
                ],
            ],
            'type_info' => [
                'title' => $this->typeClass,
            ]
        ];
        $merchant = Utils::arrayMerge($merchant,$realMerchant);
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
            'reference' => [
                'threeds_id' => 'asdfasdfasdfasdf',
                'encrypt_data' => 'adsfasdfasdfasdfadsf',
            ]
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
