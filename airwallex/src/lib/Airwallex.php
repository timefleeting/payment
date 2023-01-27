<?php

namespace Netflying\Airwallex\lib;

use Exception;

use Netflying\Payment\common\Utils;
use Netflying\Payment\common\Request as Rt;
use Netflying\Payment\lib\PayAbstract;
use Netflying\Payment\lib\Request;
use Netflying\Payment\data\Address;

use Netflying\Payment\data\Order;
use Netflying\Payment\data\Redirect;
use Netflying\Payment\data\OrderPayment;
use Netflying\Payment\data\RequestCreate;
use Netflying\Airwallex\data\Merchant as AirwallexMerchant;

class Airwallex extends PayAbstract
{
    protected $jsSdk = __DIR__ . '/../js/airwallex.js';
    //强制开启3ds,测试环境需要
    protected $force3ds = false;
    //获取请求token,有时效(30 min)
    protected $token = '';
    //token缓存key
    protected $tokenCacheName = 'airwallex.token';
    //token缓存时间
    protected $tokenExpire = 1200;
    //通过payment_intent_id获取订单sn
    protected $snByIntentId = null;

    public function __construct($Merchant, $Log = '', $Cache = '')
    {
        if (is_array($Merchant)) {
            $Merchant = new AirwallexMerchant($Merchant);
        }
        $this->merchant($Merchant);
        $this->envEndpoint();
        $this->log($Log);
        $this->cache($Cache);
        //需要明确测试环境
        if ($Merchant['is_test'] == 1) {
            $this->setForce3ds(true);
        }
    }

    public function getForce3ds()
    {
        return $this->force3ds;
    }
    public function setForce3ds($is3ds)
    {
        $this->force3ds = (bool)$is3ds;
        return $this;
    }
    public function getToken()
    {
        if (empty($this->token) && !empty($this->cache)) {
            $token = $this->cache->get($this->tokenCacheName);
            $this->token = !empty($token) ? $token : '';
        }
        return $this->token;
    }
    public function setToken($token)
    {
        $this->token = $token;
        if (!empty($this->cache)) {
            $this->cache->set($this->tokenCacheName, $token, $this->tokenExpire);
        }
        return $this;
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
        $apiData = $Merchant['api_data'];
        if ($isTest) {
            $endpointDomain = "https://pci-api-demo.airwallex.com";
        } else {
            $endpointDomain = "https://pci-api.airwallex.com";
        }
        $endpoint = '/api/v1/pa/payment_intents/create';
        $endpointConfirm = '/api/v1/pa/payment_intents/{$id}/confirm';
        $tokenUrl = '/api/v1/authentication/login';
        $apiData['endpoint_domain'] = $endpointDomain;
        $apiData['endpoint'] = $endpoint;
        $apiData['endpoint_confirm'] = $endpointConfirm;
        $apiData['token_url'] = $tokenUrl;
        $this->merchant['api_data'] = $apiData;
    }

    /**
     * 用户端设备指纹标识
     * @return [array]
     * [
     *      'device_id' => '随机字符串，需保证每次支付页面刷新加载执行该脚本的时候都不一样，保证唯一性',
     *      'jscript' => '设备指纹的获取需要2-4秒钟，只要消费者在页面上停留超过这个时间就能抓取,装载进<head></head>,如需要还可加装iframe(<noscript><iframe></iframe></noscript>)',  
     * ]
     */
    public function deviceToken()
    {
        $device_id = join("-", str_split(substr(md5(uniqid(mt_rand(), 1)),   8,   16), 4));
        $orgId = $this->merchant['api_data']['org_id'];
        //因固定的,可直接写在代码里
        $jscript = "https://h.online-metrix.net/fp/tags.js?org_id=" . $orgId . "&session_id=" . $device_id;
        return [
            'device_id' => $device_id,
            'jscript' => $jscript
        ];
    }

    protected function jsSdkConfig(array $params = [])
    {
        $data = parent::jsSdkConfig($params);
        $deviceToken = $this->deviceToken();
        $data = array_merge($data, $deviceToken);
        return $data;
    }

    /**
     * 获取接口请求token
     *
     * @return void
     */
    public function requestToken()
    {
        $token = $this->getToken();
        if (!empty($token)) {
            return $token;
        }
        $merchant = $this->merchant;
        $apiData = $merchant['api_data'];
        $apiAccount = $merchant['api_account'];
        $headers = [
            'x-api-key' => $apiAccount['api_key'],
            'x-client-id' => $apiAccount['client_id']
        ];
        $url = $apiData['endpoint_domain'] . $apiData['token_url'];
        $headers['Content-Type'] = "application/json; charset=utf-8";
        $res = Request::create(new RequestCreate([
            'type' => 'post',
            'url' => $url,
            'headers' => $headers,
            'data' => '',
            'log' => $this->log,
            'title' => get_class($this)
        ]));
        $result = !empty($res['body']) ? json_decode($res['body'], true) : '';
        $token = isset($result['token']) ? $result['token'] : '';
        $this->setToken($token);
        return $token;
    }

    public function purchase(Order $Order): Redirect
    {
        $this->merchantCallUrl($Order);
        $CreditCard = $Order['credit_card']->creditCard();
        $Order->setCreditCardData($CreditCard);
        $apiData = $this->merchant['api_data'];
        $callUrl = $this->merchant['call_url'];
        $orderData = $this->orderData($Order);
        $createPayUrl = $apiData['endpoint_domain'] . $apiData['endpoint'];
        $createRs = $this->requestApi($createPayUrl, $orderData);
        $code = $createRs['code'];
        $body = $createRs['body'] ? json_decode($createRs['body'], true) : [];
        $msg = isset($body['message']) ? $body['message'] : 'create error';
        $rsCode = isset($body['code']) ? $body['code'] : 0;
        if (!empty($body['id']) && $body['status'] == 'REQUIRES_PAYMENT_METHOD') {
            //确认提交
            $confirmUrl = str_replace('{$id}', $body['id'], $apiData['endpoint_confirm']);
            $confirmUrl = $apiData['endpoint_domain'] . $confirmUrl;
            $billingData = $this->billingData($Order);
            $creditData = $this->creditData($Order);
            $deviceData = $this->deviceData($Order);
            $creditData['billing'] = $billingData;
            $paymentMethod = [
                'type' => 'card',
                //'billing' => $billing,
                'card' => $creditData
            ];
            $paymentMethodOptions = [
                'card' => [
                    'auto_capture' => true,
                ]
            ];
            $device = [
                'device_id' => $deviceData['device_id']
            ];
            $returnUrl = Rt::buildUri($callUrl['return_url'], ['3ds' => 1, 'paymentIntentId' => $body['id'], 'sn' => $Order['sn'], 'pay_id' => $body['id']]);
            $post = [
                'request_id' => Utils::dayipSn(),
                'payment_method' => $paymentMethod,
                'payment_method_options' => $paymentMethodOptions,
                'device' => $device,
                'device_data' => $deviceData,
                'return_url' => $returnUrl, //注意:第一次需要把orderId带上,否则后续返回将无orderId
            ];
            //var_dump($post);die;
            return $this->requestRedirect($confirmUrl, $post, $Order);
        }
        return $this->errorRedirect($rsCode, $msg, $code);
    }

    /**
     * 注入根据payment_intent_id获取订单sn方法
     *
     * @param \Closure $fn
     * @return this
     */
    public function snByIntentId(\Closure $fn)
    {
        $this->snByIntentId = $fn;
        return $this;
    }

    /**
     * 异步通知回调
     */
    public function notify(): OrderPayment
    {
        $apiAccount = $this->merchant['api_account'];
        $json = file_get_contents('php://input');
        //验证有效信息
        $header = $_SERVER;
        $timestamp = isset($header['HTTP_X_TIMESTAMP']) ? $header['HTTP_X_TIMESTAMP'] : time();
        $signature = isset($header['HTTP_X_SIGNATURE']) ? $header['HTTP_X_SIGNATURE'] : '';
        if (empty($signature)) {
            throw new Exception('signature error');
        }
        if (hash_hmac('sha256', $timestamp . $json, $apiAccount['webhook_secret']) != $signature) {
            throw new Exception('signature invalid');
        }
        //状态处理
        $data = json_decode($json, true);
        if (empty($data)) {
            throw new Exception('data error');
        }
        $name = $data['name'];
        $response = Utils::mapData([
            'sn' => '',
            'status_descrip' => '',
            'currency' => '',
            'amount' => 0,
            'pay_id' => '',
            'pay_sn' => '',
            'payment_intent_id', //如果sn为空,需要通过该参数获取
            'latest_payment_attempt' => [],
            'response_status' => '',
        ], $data['data']['object'], [
            'sn' => 'merchant_order_id',
            'pay_id' => 'id',    //payment intent id & 退款id
            'pay_sn' => 'request_id', //交易流水订单号
            'response_status' => 'status'
        ]);
        //
        if (empty($response['sn']) && $this->snByIntentId instanceof \Closure) {
            $response['sn'] = call_user_func_array($this->snByIntentId, [$response['payment_intent_id']]);
        }
        $merchant = $this->merchant;
        $payment['type'] = $merchant['type'];
        $payment['merchant'] = $merchant['merchant'];
        $payment['fee'] = 0;
        $payment['pay_time'] = !empty($data['createAt']) ? strtotime($data['createAt']) : 0;
        $payment['status_descrip'] = isset($response['latest_payment_attempt']['status']) ? $response['latest_payment_attempt']['status'] : $response['response_status'];
        $status = 0;
        //交易状态
        switch ($name) {
            case 'payment_intent.created': //创建订单
                break;
            case 'payment_intent.cancelled':
                break;
            case 'payment_intent.succeeded': //支付处理成功
                $status = 1;
                break;
            case 'refund.received':
                break;
            case 'refund.processing':   //退款中
                break;
            case 'refund.succeeded':   //退款完成
                $status = -1;
                break;
        }
        $payment['status'] = $status;
        //return billing address
        $pb = isset($response['latest_payment_attempt']['payment_method']['billing']) ? $response['latest_payment_attempt']['payment_method']['billing'] : [];
        if (!empty($pb)) {
            $address = $pb['address'];
            $billing1 = Utils::mapData([
                'first_name'      => '',
                'last_name'       => '',
                'phone' => '',
                'email' => ''
            ], $pb, [
                'phone' => 'phone_number'
            ]);
            $billing2 = Utils::mapData([
                'country_code' => '',
                'region' => '',
                'city' => '',
                'district' => '',
                'postal_code' => '',
                'street_address' => ''
            ], $address, [
                'region' => 'state',
                'street_address' => 'street'
            ]);
            $Billing = new Address(array_merge($billing1, $billing2));
            $payment['address']['billing'] = $Billing;
        }
        return new OrderPayment($payment);
    }

    public function callReturn()
    {
        $mode = Utils::mapData([
            '_sn'   => '',
            'status' => '',
            '3ds' => '', //提交后跳转3ds验证
            'paymentIntentId' => '', //
            'Response' => '',
            'transactionId' => '',   //
            'threeDSMethodData' => '', //*post
            'cres' => '',
        ], Rt::receive(), [
            'transactionId' => 'transactionId,TransactionId',
            'Response' => 'Response,response',
            'paymentIntentId' => 'paymentIntentId,PaymentIntentId',
            'threeDSMethodData' => 'threeDSMethodData,ThreeDSMethodData', //*post
            'cres' => 'cres,Cres',
        ]);
        $this->merchantCallUrl(new Order([
            'sn' => $mode['_sn']
        ], 2));
        return $this->confirmContinue3ds($mode);
    }

    /**
     * 3DS验证(有感验证,会跳转到短信通知页面)
     * https://www.airwallex.com/docs/online-payments__api-integration__native-api__payment-with-3d-secure
     * @param [type] $data
     * @return void
     */
    protected function confirmContinue3ds($data)
    {
        //注意: key首字母小写
        if (!empty($data)) {
            foreach ($data as $k => $v) {
                $k = lcfirst($k);
                $data[$k] = $v;
            }
        }
        $apiData = $this->merchant['api_data'];
        $callUrl = $this->merchant['call_url'];
        $domain = $apiData['endpoint_domain'];
        $confirmUrl = $domain . '/api/v1/pa/payment_intents/' . $data['paymentIntentId'] . '/confirm_continue';
        $post = [
            'request_id' => Utils::dayipSn(),
            //'type' => '3dsValidate', //conditional: 3ds_check_enrollment,3ds_validate
        ];
        //three_ds
        $post['three_ds'] = [];
        $type = '3dsCheckEnrollment';
        if (!empty($data['transactionId'])) {
            $post['three_ds']['ds_transaction_id'] = $data['transactionId']; //type:3dsValidate
            $type = '3dsValidate';
        }
        if (!empty($data['threeDSMethodData'])) {
            $post['three_ds']['acs_response'] = 'threeDSMethodData=' . $data['threeDSMethodData']; //type:3dsCheckEnrollment
            $type = '3dsCheckEnrollment';
        }
        if (!empty($data['cres'])) {
            $post['three_ds']['acs_response'] = 'cres=' . $data['cres']; //新文档中未出现，可能是旧的或是过渡参数. type: 3dsValidate
            $type = '3dsValidate';
        }
        if (!empty($data['response'])) {
            $post['three_ds']['device_data_collection_res'] = $data['response']; //type: 3dsCheckEnrollment
            $type = '3dsCheckEnrollment';
        }
        $post['type'] = $type; //3ds验证不提供会报错: type must be provided
        $res = $this->requestRedirect($confirmUrl, $post, new Order([
            'sn' => $data['_sn'],
        ], 2));
        if ($res['status'] != 1) {
            $msg = $res['exception']['msg'];
            $url = Rt::buildUri($callUrl['failure_url'], ['status' => -1, 'exception' => $msg]);
            $res = $this->toRedirect($url);
        }
        //执行form提交
        $res['url'] = Rt::buildUri($res['url'],['_pay_id' => $data['paymentIntentId']]);
        echo Utils::formSubmit($res['url'], $res['params']);
        die;
    }

    /**
     *
     * @param string $url
     * @param array $data
     * @param array $param
     * @return Redirect
     */
    protected function requestRedirect($url, $data, Order $Order)
    {
        $mode = Utils::mapData([
            'pay_id'   => '',
        ], Rt::receive());
        $this->merchantCallUrl($Order);
        $callUrl = $this->merchant['call_url'];
        $res = $this->requestApi($url, $data);
        $json = !empty($res['body']) ? json_decode($res['body'], true) : [];
        $rsStatus = isset($json['status']) ? strtoupper($json['status']) : '';
        //success or exception 
        $code = isset($json['code']) ? $json['code'] : '';
        $message = isset($json['message']) ? $json['message'] : '';
        $rsCode  = isset($json['code']) ? $json['code'] : 0;
        $rsStatus = stripos($message, 'SUCCEEDED') !== false ? 'SUCCEEDED' : $rsStatus;
        //获取并组装pay_id
        $payId = $mode['pay_id'];
        if (empty($payId) && !empty($data['return_url']) ) {
            $returnUrlParam = Rt::parseQuery($data['return_url']);
            $mode = Utils::mapData([
                'pay_id' => $payId
            ],$returnUrlParam);
            $payId = $mode['pay_id'];
        }
        if ($rsStatus == 'SUCCEEDED') {
            $successUrl = $callUrl['success_url'];
            $successUrl = Rt::buildUri($successUrl, ['pay_id' => $payId]);
            return $this->toRedirect($successUrl);
        } elseif ($rsStatus == 'CANCELLED') { //The PaymentIntent has been cancelled. Uncaptured funds will be returned.
            $cancelUrl = $callUrl['cancel_url'];
            $cancelUrl = Rt::buildUri($cancelUrl, ['pay_id' => $payId]);
            return $this->toRedirect($cancelUrl, ['status' => -1]);
        } elseif ($rsStatus == 'REQUIRES_CUSTOMER_ACTION') {
            //has next action
            $nextAction = isset($json['next_action']) ? $json['next_action'] : [];
            $nextAction = !empty($nextAction) && is_string($nextAction) ? json_decode($nextAction, true) : $nextAction;
            $nextData = Utils::modeData([
                'method' => 'post',
                'url' => '',
                //'jwt' => ''
            ], $nextAction, [
                'method' => function ($v) {
                    return strtolower($v);
                }
            ]);
            $nextData['data'] = isset($nextAction['data']) ? $nextAction['data'] : [];
            if (!empty($nextData['data']['jwt'])) {
                $nextData['data']['JWT'] = $nextData['data']['jwt'];
                if (!empty($Order['credit_card_data']['card_number'])) {
                    $nextData['data']['BIN'] = $Order['credit_card_data']['card_number'];
                }
                $nextData['data']['continue'] = "Continue";
            }
            //模拟form post提交跳转到next_url,跳转到自动定位到->$return_url (旧版:jwt; 新版: threeDSMethodData->creq->..->return_url)
            //The 3D Secure 2 flow will provide a response in the return_url you earlier provided.
            //The encrypted content you have received contains the device details that the issuer requires.
            //$nextData['next_action'] = 1; //标记为继续确认?
            $url = $nextData['url'];
            if (empty($url)) { //异常失败
                $url = Rt::buildUri($callUrl['failure_url'], ['status' => 0, 'msg' => $message]);
                $nextData['url'] = $url;
                $nextData['method'] = 'get';
            }
            return $this->toRedirect($nextData['url'], $nextData['data'], $nextData['method']);
        } else {
            // $status == 'CANCELLED' 
            // The PaymentIntent has been cancelled. Uncaptured funds will be returned.
            // $status == 'REQUIRES_PAYMENT_METHOD'
            //1. Populate payment_method when calling confirm
            //2. This value is returned if payment_method is either null, or the payment_method has failed during confirm,
            //   and a different payment_method should be provide
            // $status == 'REQUIRES_CAPTURE'            
            //See next_action for the details. For example next_action=capture indicates that capture is outstanding.
            return $this->errorRedirect($rsCode, $message, $res['code']);
        }
    }

    /**
     * 订单数据结构
     *
     * @param Order $Order
     * @return array
     */
    protected function orderData(Order $Order)
    {
        $shipping = $this->shippingData($Order);
        $descript = !empty($Order['descript']) ? $Order['descript'] : $Order['sn'];
        $data = [
            'merchant_order_id' => $Order['sn'], //The order ID created in merchant's order system that corresponds to this PaymentIntent
            'request_id' => Utils::dayipSn(), //Unique request ID specified by the merchant
            'amount' => Utils::caldiv($Order['purchase_amount'], 100),    //Payment amount. This is the order amount you would like to charge your customer.
            'currency' => $Order['currency'], //Payment currency
            'descriptor' => $descript, //Descriptor that will display to the customer. For example, in customer's credit card statement
            'order' => [
                'shipping' => $shipping
            ]
        ];
        ////测试环境需要强制开启3ds
        if ($this->force3ds) {
            $data['payment_method_options']['card']['risk_control']['three_domain_secure_action'] = "FORCE_3DS";
        }
        return $data;
    }
    /**
     * 信用卡bin信息
     * @param Order $order
     * @return array
     */
    protected function creditData(Order $Order)
    {
        $card = $Order['credit_card_data'];
        $credit = [
            'number' => $card['card_number'],  //Card number
            'expiry_month' => (string)$card['expiry_month'], //Two digit number representing the card’s expiration month
            'expiry_year' => (string)$card['expiry_year'], //Four digit number representing the card’s expiration year
            'cvc' => $card['cvc'],  //CVC code of this card [conditional]
            'name' => $card['holder_name']  //Card holder name [conditional]
        ];
        return $credit;
    }
    protected function shippingData(Order $Order)
    {
        return $this->addressData($Order, 'shipping');
    }
    protected function billingData(Order $Order)
    {
        return $this->addressData($Order, 'billing');
    }
    /**
     * 地址数据模型
     *
     * @param Order $order
     * @param string $type [shipping,billing]
     * @return void
     */
    protected function addressData(Order $Order, $type)
    {
        $address = $Order['address'];
        $orderAddress = $address[$type];
        $data  = [
            'city' => $orderAddress['city'],  //required: City of the address,1-50 characters long
            'country_code' => $orderAddress['country_code'], //required: country code (2-letter ISO 3166-2 country code)
            'street' => $orderAddress['street_address'], //required: 1-200 characters long, Should not be a Post Office Box address, please enter a valid address
        ];
        //address optional 
        if (!empty($orderAddress['region'])) { //State or province of the address,1-50 characters long
            $data['state'] = $orderAddress['region'];
        }
        if (!empty($orderAddress['postal_code'])) { //Postcode of the address, 1-50 characters long
            $data['postcode'] = $orderAddress['postal_code'];
        }
        $addressData = [
            'first_name'   => $orderAddress['first_name'],
            'last_name'    => $orderAddress['last_name'],
            'address'      => $data
        ];
        if (!empty($orderAddress['phone'])) { //
            $addressData['phone_number'] = $orderAddress['phone'];
        }
        if (!empty($orderAddress['email'])) { //文档无该参数,加入也无效
            $addressData['email'] = $orderAddress['email'];
        }
        return $addressData;
    }
    /**
     * 客户端设备信息
     */
    protected function deviceData(Order $Order)
    {
        $device = $Order['device_data'];
        $deviceData = [
            'device_id' => $device['threeds_id'],
            'ip_address' => Rt::ip(),
            'language' => $device['language'],
            'screen_color_depth' => $device['screen_color_depth'],
            'screen_height' => $device['screen_height'],
            'screen_width' => $device['screen_width'],
            'timezone' => $device['timezone'],
        ];
        $browser = [
            'java_enabled' => $device['java_enabled'] ? true : false,
            'javascript_enabled' => true,
            'user_agent' => $Order['user_agent']
        ];
        $deviceData['browser'] = $browser;
        return $deviceData;
    }

    protected function requestApi($url, $data = [], $type = 'post')
    {
        $post = (!empty($data) && is_array($data)) ? json_encode($data, JSON_UNESCAPED_UNICODE) : '';
        $rs = $this->request($url, $post, $type);
        $json = !empty($res['body']) ? json_decode($res['body'], true) : [];
        //success or exception 
        $message = isset($json['message']) ? $json['message'] : '';
        $rsCode  = isset($json['code']) ? $json['code'] : 0;
        $this->setException($rsCode, $message, $rs['code']);
        return $rs;
    }

    protected function request($url, $data = [], $type = 'post')
    {
        $headers = [];
        $headers['Content-Type'] = "application/json; charset=utf-8";
        $token = $this->getToken();
        if (empty($token)) {
            $token = $this->requestToken();
        }
        //$headers['region'] = 'string';
        $headers['Authorization'] = 'Bearer ' . $token;
        $res = Request::create(new RequestCreate([
            'type' => $type,
            'url' => $url,
            'headers' => $headers,
            'data' => $data,
            'log' => $this->log,
            'title' => get_class($this)
        ]));
        return $res;
    }
}
