<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-31 13:55:46 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-11-17 12:23:30
 */

namespace Netflying\Paypal\lib;

use Netflying\Payment\common\Utils;
use Netflying\Payment\common\Request as Rt;
use Netflying\Payment\lib\PayAbstract;
use Netflying\Payment\lib\Request;

use Netflying\Payment\data\Order;
use Netflying\Payment\data\OrderProduct;
use Netflying\Payment\data\Redirect;
use Netflying\Payment\data\OrderPayment;
use Netflying\Payment\data\RequestCreate;
use Netflying\Payment\data\Address;
use Netflying\Paypal\data\NvpMerchant;

class Nvp extends PayAbstract
{

    protected $jsSdk = __DIR__ . '/../js/nvp.js';

    //成功状态
    protected static $completeArr = ['completed'];
    //撤消
    protected static $cancelArr = [
        'canceled',
        'cancelled',
        'canceled-reversal'
    ];
    //退款
    protected static $refundArr = [
        'refunded',
        'reversed'
    ];

    public function __construct($Merchant = null, $Log = '', $Cache = '')
    {
        if (is_array($Merchant)) {
            $Merchant = new NvpMerchant($Merchant);
        }
        $this->merchant($Merchant);
        $this->envEndpoint();
        $this->log($Log);
        $this->cache($Cache);
    }

    /**
     * 设置api data环境地址
     *
     * @return void
     */
    protected function envEndpoint()
    {
        $Merchant = $this->merchant;
        $isTest = (bool)$Merchant['is_test'];
        $apiAccount = $Merchant['api_account'];
        $apiData = $Merchant['api_data'];
        $apiAccount['version'] = "61.0"; //pp版本号
        if ($isTest) {
            $apiData['endpoint'] = "https://api-3t.sandbox.paypal.com/nvp";
            $apiData['token_direct'] = 'https://www.sandbox.paypal.com/webscr?cmd=_express-checkout&useraction=commit&token={$token}';
        } else {
            $apiData['endpoint'] = "https://api-3t.paypal.com/nvp";
            $apiData['token_direct'] = 'https://www.paypal.com/webscr?cmd=_express-checkout&useraction=commit&token={$token}';
        }
        $this->merchant['api_account'] = $apiAccount;
        $this->merchant['api_data'] = $apiData;
    }

    /**
     * 提交支付信息
     * @param Order
     * @param OrderProduct
     * @return Redirect
     */
    public function purchase(Order $Order): Redirect
    {
        $token = $this->checkoutToken($Order);
        $url = $this->getTokenUrl($token);
        if (empty($url)) {
            $exception = $this->getException();
            return $this->errorRedirect($exception['code'], $exception['msg']);
        }
        return $this->toRedirect($url);
    }
    /**
     * 用户确认后,最终提交完成
     * 通过 RETURNURL 返回路径
     * @return Redirect
     */
    public function doPurchase()
    {
        $data = Utils::mapData(
            [
                'sn' => '',
                'PayerID' => '',
                'token' => '',
            ],
            Rt::receive(),
            [
                'sn' => ['sn', '_sn']
            ]
        );
        $sn = $data['sn'];
        $this->merchantCallUrl(new Order([
            'sn' => $data['sn']
        ], 2));
        $merchant = $this->merchant;
        $callUrl = $merchant['call_url'];
        $status = 0;
        if (!empty($data['sn']) && !empty($data['token'])) {
            $status = 1;
            $details = $this->tokenCheckoutDetails($data['token']);
            if (!empty($details)) {
                try {
                    $fields   = array(
                        'PAYERID'         => $data['PayerID'],
                        'AMT'             => $details['AMT'],
                        'ITEMAMT'         => $details['AMT'],
                        'CURRENCYCODE'    => $details['CURRENCYCODE'],
                        'RETURNFMFDETAILS' => 1,
                        'TOKEN'          => $data['token'],
                        'PAYMENTACTION'  => 'Sale', //Sale或者...
                        'NOTIFYURL'      => $callUrl['notify_url'],
                        'INVNUM'         => $details['INVNUM'],
                        'CUSTOM'         => '',
                        //'SHIPPINGAMT'=>'', //总运费
                        //'INSURANCEAMT' =>'', //货物保险费用
                    );
                    if (!empty($details['SHIPTOCOUNTRYCODE'])) {
                        $shipping = Utils::mapData([
                            'first_name' => "",
                            'last_name'  => "",
                            'country_code' => "",
                            'city' => "",
                            'street_address' => "",
                            'postal_code' => "",
                        ], $details, [
                            'first_name' => "FIRSTNAME",
                            'last_name'  => "LASTNAME",
                            'country_code' => "COUNTRYCODE",
                            'city' => "SHIPTOCITY",
                            'street_address' => "SHIPTOSTREET",
                            'postal_code' => "SHIPTOZIP",
                        ]);
                    }
                    $rs = $this->request('DoExpressCheckoutPayment', $fields);
                    if (!$rs) {
                        $status = -1;
                    }
                } catch (\Exception $e) {
                    $status = -1;
                }
            }
        }
        return $this->toRedirect($callUrl['success_url'], [
            'status' => $status, //跳转状态
            'sn' => $sn
        ]);
    }

    /**
     * 授权支付信息,并获取返回的地址
     */
    public function authoirzation(Order $Order): Redirect
    {
        $token = $this->checkoutToken($Order, 'Authorization');
        $url = $this->getTokenUrl($token);
        $status = 1;
        if (empty($url)) {
            $status = 0;
        }
        return new Redirect([
            'status' => $status,
            'url' => $url,
            'type' => 'get',
            'params' => [],
            'exception' => []
        ]);
    }

    public function callReturn()
    {
        return $this->doPurchase();
    }

    /**
     *  统一回调通知接口
     * @return OrderPayment
     */
    public function notify(): OrderPayment
    {
        $data = Utils::mapData([
            'sn' => '',
            'amount' => 0,
            'fee' => 0,
            'txn_id' => '',
            'ipn_track_id' => '',
            'currency' => '',
            'status_str' => '',
            'pay_time' => 0,
        ], Rt::receive(), [
            'sn' => "item_number,invoice",
            'amount' => 'payment_gross,mc_gross',
            'fee' => 'payment_fee,mc_fee',
            'currency' => 'mc_currency',
            'status_str' => 'payment_status',
            'pay_time' => 'payment_date'
        ]);
        //ipn verify 
        $listener = new IpnListener();
        $listener->use_sandbox = $this->merchant['is_test'] ? true : false;
        $verified  = 0;
        try {
            $listener->requirePostMethod();
            $verified = $listener->processIpn();
        } catch (\Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
        if ($verified == 0) {
            $this->setError('verified error', 0);
            return false;
        }
        $status = -2;
        $statusStr = strtolower($data['status_str']);
        if (in_array($statusStr, self::$completeArr)) {
            $status = 1;
        } elseif (in_array($statusStr, self::$cancelArr)) {
            $status = 0;
        } elseif (in_array($statusStr, self::$refundArr)) {
            $status = -1;
        }
        return new OrderPayment([
            'sn' => $data['sn'],
            'type' => $this->merchant['type'],
            'merchant' => $this->merchant['merchant'],
            'pay_id' => $data['txn_id'],
            'pay_sn' => $data['ipn_track_id'],
            'currency' => $data['currency'],
            'amount' => $data['amount'],
            'fee' => $data['fee'],
            'status' => $status,
            'status_descrip' => $data['status_str'],
            'pay_time' => $data['pay_time']
        ]);
    }
    /**
     * token获取授权地址
     *
     * @param string $token
     * @return Address
     */
    public function tokenAddress($token)
    {
        $rs = $this->tokenCheckoutDetails($token);
        $data = Utils::mapData([
            'first_name'      => '',
            'last_name'       => '',
            'email'           => '',
            'phone'           => '',
            'country_code'    => '',
            'region'          => '',
            'city'            => '',
            'district'        => '',
            'postal_code'     => '',
            'street_address'  => '',
            'street_address2' => ''
        ], $rs, [
            'first_name'      => 'SHIPTONAME',
            'last_name'       => 'SHIPTONAME',
            'email'           => 'EMAIL',
            'phone'           => '',
            'country_code'    => 'SHIPTOCOUNTRYCODE',
            'region'          => 'SHIPTOSTATE',
            'city'            => 'SHIPTOCITY',
            'district'        => '',
            'postal_code'     => 'SHIPTOZIP',
            'street_address'  => 'SHIPTOSTREET',
            'street_address2' => ''
        ]);
        if (!empty($data['first_name'])) {
            $shipNameArr = explode(' ', $data['first_name']);
            $nameData = Utils::mapData([
                'first_name' => '',
                'last_name' => '',
            ], $shipNameArr, [
                'first_name' => [0],
                'last_name' => [1]
            ]);
            $data = array_merge($data, $nameData);
        }
        return new Address($data);
    }
    /**
     * 根据token获取订单详情
     */
    public function tokenCheckoutDetails($token)
    {
        if (empty($token)) {
            return false;
        }
        return $this->request('GetExpressCheckoutDetails', [
            'TOKEN' => $token
        ]);
    }

    /**
     * 获取跳转支付token
     *
     * @param Order $order
     * @return void
     */
    protected function checkoutToken(Order $Order, $type = '')
    {
        $this->merchantCallUrl($Order);
        $merchant = $this->merchant;
        $apiData = $merchant['api_data'];
        $urlData = $merchant['call_url'];
        $amount = Utils::caldiv($Order['purchase_amount'], 100);
        $maxAmt = $amount + 1;
        $action = 'Sale';
        $noShipping = 2; //物流信息必需 0:在paypal上显示收货地址 1: 不显示并从产易中删除送货信息 2: 如果没有传会从资料中获取
        $cancelUrl = $urlData['cancel_url'];
        $returnUrl = $urlData['return_url'];
        $notifyUrl = $urlData['notify_url'];
        if (!empty($type) && in_array($type, ['Sale', 'Authorization', 'order'])) {
            $action = $type;
        }
        if ($action == 'Authorization') {
            //$noShipping = 0; //在 PayPal 页面上显示送货地址。
            $cancelUrl = $urlData['authorise_cancel_url'];
            $returnUrl = $urlData['authorise_renturn_url'];
        }
        $address = $Order['address'];
        $shippingFields = [];
        if (!empty($address['shipping']) && !empty($apiData['show_shipping'])) {
            $noShipping = 2;
            $shipping = $address['shipping'];
            $shippingFields = [
                'SHIPTONAME' => $shipping['first_name'].' '.$shipping['last_name'], //自版本63: PAYMENTREQUEST_n_SHIPTONAME
                'SHIPTOCOUNTRY' => $shipping['country_code'], //PAYMENTREQUEST_n_SHIPTOCOUNTRYCODE
                'SHIPTOSTATE' => $shipping['region'], //PAYMENTREQUEST_n_SHIPTOSTATE
                'SHIPTOCITY' => $shipping['city'], //PAYMENTREQUEST_n_SHIPTOCITY
                'SHIPTOSTREET' => $shipping['street_address'], //PAYMENTREQUEST_n_SHIPTOSTREET
                'SHIPTOZIP' => $shipping['postal_code'] //PAYMENTREQUEST_n_SHIPTOZIP
            ];
            if (!empty($shipping['phone'])) {
                $shippingFields['SHIPTOPHONENUM'] = $shipping['phone']; //PAYMENTREQUEST_n_SHIPTOPHONENUM
            }
            if (!empty($shipping['street_address1'])) {
                $shippingFields['SHIPTOSTREET2'] = $shipping['street_address1']; //PAYMENTREQUEST_n_SHIPTOSTREET2
            }
        }
        $fields   = [
            'CANCELURL' => $cancelUrl,  //支付取消返回
            'RETURNURL' => $returnUrl, //支付成功返回
            'NOTIFYURL' => $notifyUrl,
            'AMT'       => $amount,
            'ITEMAMT'   => $amount,
            'CURRENCYCODE' => $Order['currency'],
            'MAXAMT'    =>  $maxAmt, //最高可能总额(最大运费,汇率差等)
            'CUSTOM'    => '',
            'INVNUM'    => $Order['sn'], //唯一标识值，一般可为订单号
            'DESC'      => '', //描述
            'PAYMENTACTION' => $action, //支付动作 Sale,Authorization,Order 此付款是一项基本授权，需通过 PayPal Authorization and Capture 进行结算。
            //other fields
            'SHIPPINGAMT' => 0, //物流费用，如果有物流
            'NOSHIPPING'  => $noShipping, //一定要有物流信息
        ];
        $localCode = $this->getLocalCode();
        if ($localCode) {
            //可能出现中文,传递浏览器语言
            $fields['LOCALECODE'] = $localCode;
        }
        if (!empty($apiData['brand_name'])) {
            $fields['BRANDNAME'] = $apiData['brand_name']; //显示品牌为站点名字
        }
        if (!empty($shippingFields)) {
            $fields['ADDROVERRIDE'] = 1;
            $fields = Utils::arrayMerge($fields, $shippingFields);
        }
        $ret = $this->request('SetExpressCheckout', $fields);
        return isset($ret['TOKEN']) ? $ret['TOKEN'] : false;
    }
    protected function getTokenUrl($token)
    {
        if (empty($token)) {
            return '';
        }
        $token = $token ? urlencode($token) : '';
        return  str_replace('{$token}', $token, $this->merchant['api_data']['token_direct']);
    }
    /**
     * 解析浏览器带的，得到localcode
     * https://developer.paypal.com/api/rest/reference/locale-codes/
     * paypal使用_下划线解析
     * @return bool|mixed|string
     */
    protected function getLocalCode()
    {
        $ret = '';
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && $_SERVER['HTTP_ACCEPT_LANGUAGE']) {
            $tmp = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
            if (stripos($tmp, '-') == 2) {
                $ret = substr($tmp, 0, 5);
                $ret = str_replace('-', '_', $ret);
            }
        }
        return $ret;
    }

    protected function request($method, array $fields)
    {
        $merchant = $this->merchant;
        $apiAccount = $merchant['api_account'];
        $apiData = $merchant['api_data'];
        $data = array(
            'METHOD'    => $method,
            'VERSION'   => $apiAccount['version'],
            'USER'      => $apiAccount['user'],
            'PWD'       => $apiAccount['password'],
            'SIGNATURE' => $apiAccount['signature'],
        );
        $data = array_merge($data, $fields);
        $url = $apiData['endpoint'];
        $res = Request::create(new RequestCreate([
            'type' => 'post',
            'url' => $url,
            'data' => $data,
            'log' => $this->log,
            'title' => get_class($this)
        ]));
        $code = $res['code'];
        $body = $res['body'];
        if ($code >= 200 && $code < 300) {
            parse_str($body, $rs);
            $ack = isset($rs['ACK']) ? $rs['ACK'] : '';
            if (is_array($rs) && $ack == 'Success') {
                return $rs;
            }
            //$responseStr = !empty($rs) ? json_encode($rs) : '';
            if (empty($ack)) {
                // 错误号 & 错误码
                $errorCode = isset($rs['L_ERRORCODE0']) ? $rs['L_ERRORCODE0'] : 10000;
                $errorMsg  = isset($rs['L_LONGMESSAGE0']) ? $rs['L_LONGMESSAGE0'] : 'unknown';
                $this->setException($errorCode, $errorMsg);
            } else {
                $this->setException(0, $ack, $code);
            }
            //业务错误
            return false;
        } else {
            $this->setException(0, "request error", $code);
            //请求错误
            return false;
        }
    }
}
