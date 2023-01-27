<?php

namespace Netflying\Klarna\lib;

use Exception;

use Netflying\Payment\common\Utils;
use Netflying\Payment\common\Request as Rt;
use Netflying\Payment\lib\PayAbstract;
use Netflying\Payment\lib\Request;

use Netflying\Payment\data\Order;
use Netflying\Payment\data\Redirect;
use Netflying\Payment\data\OrderPayment;
use Netflying\Payment\data\RequestCreate;
use Netflying\Klarna\data\Merchant as KlarnaMerchant;

class Klarna extends PayAbstract
{
    protected $jsSdk = __DIR__ . '/../js/klarna.js';

    protected $freightTitle = 'freight';

    //创建是否自动捕获
    protected $autoCaptrue = false;
    //有效订单状态
    protected static $validArr = [
        'accepted',
        'authorized',
        'captured',
        'part_captured'
    ];

    public function getFreightTitle()
    {
        return $this->freightTitle;
    }
    public function setFreightTitle(string $title)
    {
        $this->freightTitle = $title;
        return $this;
    }

    public function __construct($Merchant, $Log = '', $Cache = '')
    {
        if (is_array($Merchant)) {
            $Merchant = new KlarnaMerchant($Merchant);
        }
        $this->merchant($Merchant);
        $this->envEndpoint();
        $this->log($Log);
        $this->cache($Cache);
    }
    public function getAutoCapture()
    {
        return $this->autoCaptrue;
    }
    public function setAutoCaption(bool $auto)
    {
        $this->autoCaptrue = $auto;
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
            $endpointDomain = "https://api.playground.klarna.com";
        } else {
            $endpointDomain = "https://api.klarna.com";
        }
        $endpoint = '/payments/v1/authorizations/{$token}/order';
        $clientSessionUrl = '/payments/v1/sessions';
        $captureUrl = '/ordermanagement/v1/orders/{$id}/captures';
        $ordersUrl = '/ordermanagement/v1/orders/{$id}';
        $apiData['endpoint_domain'] = $endpointDomain;
        $apiData['endpoint'] = $endpoint;
        $apiData['client_session_url'] = $clientSessionUrl;
        $apiData['capture_url'] = $captureUrl;
        $apiData['orders_url'] = $ordersUrl;
        $this->merchant['api_data'] = $apiData;
    }
    protected function jsSdkConfig(array $params = [])
    {
        $data = parent::jsSdkConfig($params);
        $arr = [
            'initJs' => 'https://x.klarnacdn.net/kp/lib/v1/api.js'
        ];
        $data = array_merge($data, $arr);
        return $data;
    }

    public function jsRender(Order $Order)
    {
        $extends = $Order->getExtends();
        if (!empty($extends['session_id'])) {
            return $this->updateSession($Order);
        } else {
            return $this->clientTokenSession($Order);
        }
    }

    public function purchase(Order $Order): Redirect
    {
        $this->merchantCallUrl($Order);
        $apiData = $this->merchant['api_data'];
        $callUrl = $this->merchant['call_url'];
        $Device = $Order['device_data'];
        $token = $Device['authorized_token'];
        $url = str_replace('{$token}', $token, $apiData['endpoint_domain'] . $apiData['endpoint']);
        $orderData = $this->orderData($Order);
        $orderData['merchant_urls'] = Utils::mapData([
            'confirmation' => '',
            'notification' => '',
            'push' => '',
        ], $callUrl, [
            'confirmation' => 'return_url',
            'notification' => 'notify_url',
            'push' => 'notify_url'
        ]);
        $orderData['billing_address'] = $this->billingData($Order);
        $orderData['shipping_address'] = $this->shippingData($Order);
        $res = $this->request($url, $orderData);
        //Could not resolve host: api.playground.klarna.com
        if ($res['errno'] == 6 && stripos($res['errmsg'], 'could not resolve host') !== false) {
            $res = $this->request($url, $orderData);
        }
        $body = is_string($res['body']) ? json_decode($res['body'], true) : $res['body'];
        $rs = Utils::mapData([
            'url' => '',
            'order_id' => '',
            'fraud_status' => '',
        ], $body, [
            'url' => 'redirect_url'
        ]);
        $orderId = $rs['order_id'];
        if (empty($rs['url'])) {
            return $this->errorRedirect($res['code']);
        }
        return $this->toRedirect($rs['url'], ['order_id' => $orderId, 'status' => $rs['fraud_status']]);
    }
    
    /**
     * 获取客户端会话ID与初始化client_token
     *
     * @return string
     */
    public function clientTokenSession(Order $Order)
    {
        $apiData = $this->merchant['api_data'];
        $url = $apiData['endpoint_domain'] . $apiData['client_session_url'];
        $orderData = $this->orderData($Order);
        $res = $this->request($url, $orderData);
        if ($res['errno'] == 6 && stripos($res['errmsg'], 'could not resolve host') !== false) {
            $res = $this->request($url, $orderData);
        }
        $resArr = json_decode($res['body'], true);
        return Utils::mapData(['client_token' => '','session_id' => ''], (is_array($resArr) ? $resArr: []));
    }
    /**
     * https://docs.klarna.com/klarna-payments/api/#tag/sessions/operation/updateCreditSession
     *
     * @param Order $Order
     * @return array
     */
    public function updateSession(Order $Order)
    {
        $apiData = $this->merchant['api_data'];
        $url = $apiData['endpoint_domain'] . $apiData['client_session_url'] . '/' . $Order['extends']['session_id'];
        $orderData = $this->orderData($Order);
        //更新session也要带上billing,shipping
        $orderData['billing_address'] = $this->billingData($Order);
        $orderData['shipping_address'] = $this->shippingData($Order);
        $res = $this->request($url, $orderData);
        if ($res['errno'] == 6 && stripos($res['errmsg'], 'could not resolve host') !== false) {
            $res = $this->request($url, $orderData);
        }
        if ($res['code']>=200&&$res['code']<300) {
            return $Order['extends'];
        }
        $bodyArr = @json_decode($res['body'], true);
        $errMsg = !empty($bodyArr['error_messages'][0]) ? $bodyArr['error_messages'][0] : '';
        return ['error' => $errMsg];
    }

    /**
     * 异步通知回调
     */
    public function notify(): OrderPayment
    {
        $data = Utils::mapData([
            'sn' => '',
            'order_id' => '', //通道订单id
        ], Rt::receive());
        $server = $_SERVER;
        $key = isset($server['HTTP_KLARNA_IDEMPOTENCY_KEY']) ? $server['HTTP_KLARNA_IDEMPOTENCY_KEY'] : '';
        $orders = $this->orders($data['order_id'], $key);
        $payment = Utils::mapData([
            'sn' => $data['sn'],
            'status_descrip' => '',
            'currency' => '',
            'amount' => '',
            'refunded_amount' => '',
            'captured_amount' => '',
            'pay_id' => $data['order_id'],
            'pay_sn' => $data['order_id'],
        ], $orders, [
            'status_descrip' => 'status', //"AUTHORIZED","CAPTURED","PART_CAPTURED"
            'currency' => 'purchase_currency',
            'amount' => 'order_amount', //order_amount, captured_amount, refunded_amount
        ]);
        $status = 0;
        if (in_array(strtolower($payment['status_descrip']), self::$validArr)) {
            $status = 1;
        }
        if (!empty($payment['refunded_amount'])) {
            $status = -1;
        }
        $payment['status'] = $status;
        $payment['merchant'] = $this->merchant['merchant'];
        $payment['type'] = $this->merchant['type'];
        return new OrderPayment($payment);
    }

    /**
     * 捕获订单
     *
     * @param string $id 提交成功返回的order_id
     * @param string $amount 金额
     * @param string $idempotencyKey
     * @return void
     */
    public function capture($id, $amount, $idempotencyKey = '')
    {
        $amount = (int)$amount;
        if (empty($amount)) {
            return '';
        }
        $apiData = $this->merchant['api_data'];
        $url = str_replace('{$id}', $id, $apiData['capture_url']);
        $url = $apiData['endpoint_domain'] . $url;
        $header = [];
        if (!empty($idempotencyKey)) {
            $header['Klarna-Idempotency-Key'] = $idempotencyKey;
        }
        $res = $this->request($url, ['captured_amount' => $amount], $header);
        $arr = explode("\r\n", $res);
        $location = "";
        if (!empty($arr)) {
            foreach ($arr as $v) {
                $kVal = explode(': ', trim($v));
                $key = isset($kVal[0]) ? $kVal[0] : '';
                if ($key == 'location') {
                    $location = isset($kVal[1]) ? $kVal[1] : '';
                }
            }
        }
        return $location;
    }
    /**
     * 获取订单详情
     *
     * @param string $id 提交时成功返回的order_id
     * @param string $idempotencyKey 头部 HTTP_KLARNA_IDEMPOTENCY_KEY
     * @return array
     */
    public function orders($id, $idempotencyKey)
    {
        $apiData = $this->merchant['api_data'];
        $url = str_replace('{$id}', $id, $apiData['orders_url']);
        $url = $apiData['endpoint_domain'] . $url;
        $header = [];
        if (!empty($idempotencyKey)) {
            $header['Klarna-Idempotency-Key'] = $idempotencyKey;
        }
        $res = $this->request($url, "", $header, 'get');
        if ($res['code'] != '200') {
            return [];
        }
        return !empty($res['body']) ? json_decode($res['body'], true) : [];
    }

    /**
     * 货币支持国家及条件
     */
    public static function currencySupportCountry($currency)
    {
        if (empty($currency)) {
            return [];
        }
        $list = self::supportCountry();
        $data = [];
        foreach ($list as $v) {
            if (strtolower($v['currency']) == strtolower($currency)) {
                $data[] = $v;
            }
        }
        return $data;
    }
    /**
     * 国家信息
     */
    public static function countryLocal($countryCode)
    {
        $list = self::supportCountry();
        return isset($list[$countryCode]) ? $list[$countryCode] : [];
    }
    /**
     * 支持的国家，及国家所对应的语言货币
     * https://docs.klarna.com/klarna-payments/in-depth-knowledge/customer-data-requirements/
     * @return array
     */
    public static function supportCountry()
    {
        $list = [
            'AU' => [ // Australia
                'country' => 'AU',
                'locale'   => 'en-AU',
                'currency' => 'AUD',
                'location' => 'oc',
                'phone' => 1,
            ],
            'AT' => [ // Austria
                'country' => 'AT',
                'locale'   => 'en-AT', // de-AT, en-AT
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 0,
            ],
            'BE' => [ // Belgium
                'country' => 'BE',
                'locale'   => 'nl-BE', //nl-BE, fr-BE
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 0,
            ],
            'CA' => [ // Canada
                'country' => 'CA',
                'locale'   => 'en-CA', //en-CA, fr-CA
                'currency' => 'CAD',
                'location' => 'na',
                'phone' => 1,
            ],
            'DK' => [ // Denmark
                'country' => 'DK',
                'locale'   => 'da-DK', //da-DK, en-DK
                'currency' => 'DKK',
                'location' => 'eu',
                'phone' => 1,
            ],
            'FI' => [ // Finland
                'country' => 'FI',
                'locale'   => 'fi-FI', //fi-FI, sv-FI, en-FI
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 1,
            ],
            'FR' => [ // France
                'country' => 'FR',
                'locale'   => 'fr-FR', //fr-FR, en-FR
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 1,
            ],
            'DE' => [ // Germany
                'country' => 'DE',
                'locale'   => 'de-DE', //de-DE, en-DE
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 0,
            ],
            'IE' => [ // Ireland (Republic of Ireland)
                'country' => 'IE',
                'locale'   => 'en-IE',
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 1,
            ],
            'IT' => [ // Italy
                'country' => 'IT',
                'locale'   => 'it-IT',
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 1,
            ],
            'NL' => [ // Netherlands
                'country' => 'NL',
                'locale'   => 'nl-NL', //nl-NL, en-NL
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 0,
            ],
            'NO' => [ // Norway
                'country' => 'NO',
                'locale'   => 'nb-NO', //nb-NO, en-NO
                'currency' => 'NOK',
                'location' => 'eu',
                'phone' => 1,
            ],
            'PL' => [ // Poland
                'country' => 'PL',
                'locale'   => 'pl-PL', //pl-PL, en-PL
                'currency' => 'PLN',
                'location' => 'eu',
                'phone' => 1,
            ],
            'PT' => [ // Portugal
                'country' => 'PT',
                'locale'   => 'pt-PT', //pt-PT, en-PT
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 1,
            ],
            'ES' => [ // Spain
                'country' => 'ES',
                'locale'   => 'es-ES',
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 1,
            ],
            'SE' => [ // Sweden
                'country' => 'SE',
                'locale'   => 'sv-SE', //sv-SE, en-SE
                'currency' => 'SEK',
                'location' => 'eu',
                'phone' => 1,
            ],
            'CH' => [ // Switzerland
                'country' => 'CH',
                'locale'   => 'de-CH', //de-CH, fr-CH, it-CH, en-CH
                'currency' => 'CHF',
                'location' => 'eu',
                'phone' => 0,
            ],
            'GB' => [ // United Kingdom	
                'country' => 'GB',
                'locale'   => 'en-GB',
                'currency' => 'GBP',
                'location' => 'eu',
                'phone' => 1,
            ],
            'US' => [ // United States
                'country' => 'US',
                'locale'   => 'en-US',
                'currency' => 'USD',
                'location' => 'na',
                'phone' => 1,
            ],
        ];
        return $list;
    }
    /**
     * 订单数据结构
     */
    protected function orderData(Order $Order)
    {
        $lines = $this->orderLinesData($Order);
        $Address = $Order['address'];
        $Shipping = $Address['shipping'];
        $Billing  = $Address['billing'];
        $countryCode = $Shipping['country_code'];
        if (!empty($Billing['country_code'])) {
            $countryCode = $Billing['country_code'];
        }
        $countryLocal = self::countryLocal($countryCode);
        $lang = Rt::getLocalCode();
        $local = Utils::mapData([
            'locale' => $lang,
            'country' => $countryCode,
        ], $countryLocal);
        $data = Utils::mapData([
            'locale'      => $local['locale'],
            'purchase_country'   => $local['country'],
            'purchase_currency'  => $Order['currency'],
            'order_amount'     => $Order['purchase_amount'], //分位
            'order_lines' => $lines,
            'merchant_reference1' => $Order['sn'], //订单号
        ], []);
        return $data;
    }
    protected function orderLinesData(Order $Order)
    {
        $lines = [];
        foreach ($Order['products'] as $k => $v) {
            $line = Utils::mapData([
                'name'      => '',
                'quantity'     => 1,
                'total_amount' => 0,
                'unit_price'   => 0,
                'total_discount_amount' => 0,
                'total_tax_amount'  => 0,
                'tax_rate' => 0,
                'image_url' => null,
                'product_url' => null,
            ], $v->toArray(), [
                'total_amount' => 'total_price',
                'total_discount_amount' => 'total_discount_price',
                'total_tax_amount' => 'total_tax_price',
            ]);
            $totalAmount = (int)Utils::calmul($line['unit_price'], $line['quantity']); //分位
            $line['total_amount'] = (int)($totalAmount - $line['total_discount_amount']);
            $line['tax_rate'] = (float)Utils::caldiv($line['total_tax_amount'], $totalAmount);
            $lines[] = $line;
        }
        //shipping product
        if ($Order['freight'] >= 0) {
            $freightTitle = !empty($Order['freight_title']) ? $Order['freight_title'] : $this->freightTitle;
            $line = Utils::mapData([
                'type' => 'shipping_fee',
                'name'      => $freightTitle,
                'quantity'     => 1,
                'total_amount' => $Order['freight'],
                'unit_price'   => $Order['freight'],
                'total_discount_amount' => 0,
                'total_tax_amount'  => 0,
                'tax_rate' => 0,
            ], []);

            $lines[] = $line;
        }
        return $lines;
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
     * @param Order $Order
     * @param string $type [shipping,billing]
     * @return void
     */
    protected function addressData(Order $Order, $type)
    {
        $Address = $Order['address'][$type];
        $addressArr = !empty($Address) ? $Address->toArray() : [];
        $data = Utils::mapData([
            'given_name'      => '',
            'family_name'     => '',
            'email'           => '',
            'country'         => '',
            'region'          => '',
            'city'            => '',
            'postal_code'     => '',
            'street_address'  => '',
        ], $addressArr, [
            'given_name' => 'first_name',
            'family_name' => 'last_name',
            'country' => 'country_code',
        ]);
        if (!empty($addressArr['phone'])) {
            $data['phone'] = Utils::phoneFormat($addressArr['phone']);
        }
        return $data;
    }

    protected function authorizationBasic()
    {
        $apiAccount = $this->merchant['api_account'];
        return base64_encode($apiAccount['username'] . ':' . $apiAccount['password']);
    }

    protected function request($url, $data = [], array $header = [], $type = 'post')
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . $this->authorizationBasic(),
            //'http_errors' => 'false',
        ];
        $headers = array_merge($headers, ['User-Agent' => strval($userAgent)], $header);
        $post = json_encode($data);
        $res = Request::create(new RequestCreate([
            'type' => $type,
            'url' => $url,
            'headers' => $headers,
            'data' => $post,
            'log' => $this->log,
            'title' => get_class($this)
        ]));
        return $res;
    }
}
