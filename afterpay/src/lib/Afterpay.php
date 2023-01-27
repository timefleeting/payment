<?php

namespace Netflying\Afterpay\lib;

use Exception;

use Netflying\Payment\common\Utils;
use Netflying\Payment\common\Request as Rt;
use Netflying\Payment\lib\PayAbstract;
use Netflying\Payment\lib\Request;

use Netflying\Payment\data\Order;
use Netflying\Payment\data\Redirect;
use Netflying\Payment\data\OrderPayment;
use Netflying\Payment\data\RequestCreate;
use Netflying\Afterpay\data\Merchant as AfterpayMerchant;

class Afterpay extends PayAbstract
{

    protected $jsSdk = __DIR__ . '/../js/afterpay.js';

    public function __construct($Merchant, $Log = '', $Cache = '')
    {
        if (is_array($Merchant)) {
            $Merchant = new AfterpayMerchant($Merchant);
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
        $apiData = $Merchant['api_data'];
        if ($isTest) {
            $endpointDomain = "https://global-api-sandbox.afterpay.com";
        } else {
            $endpointDomain = "https://global-api.afterpay.com";
        }
        $endpoint = '/v2/checkouts';
        $captureUrl = '/v2/payments/capture';
        $apiData['endpoint_domain'] = $endpointDomain;
        $apiData['endpoint'] = $endpoint;
        $apiData['capture_url'] = $captureUrl;
        $this->merchant['api_data'] = $apiData;
    }

    public function purchase(Order $Order): Redirect
    {
        $apiData = $this->merchant['api_data'];
        $url = $apiData['endpoint_domain'] . $apiData['endpoint'];
        $orderData = $this->orderData($Order);
        $res = $this->request($url, $orderData);
        $msgArr = !empty($res['body']) ? json_decode($res['body'], true) : [];
        $rs = Utils::mapData([
            'url' => '',
        ], $msgArr, [
            'url' => 'redirectCheckoutUrl'
        ]);
        if (empty($rs['url'])) {
            $msg = isset($msgArr['message']) ? $msgArr['message'] : '';
            return $this->errorRedirect($res['code'], $msg);
        }
        return $this->toRedirect($rs['url']);
    }

    public function notify(): OrderPayment
    {
        return new OrderPayment([]);
    }
    /**
     * 获取订单信息
     */
    public function getOrder($token)
    {
        $apiData = $this->merchant['api_data'];
        $url = $apiData['endpoint_domain'] . '/v2/checkouts/' . $token;
        $res = $this->request($url, [], [], 'get');
        return $res;
    }
    /**
     * 捕获订单所有金额.当跳回return_url时
     *
     * @param string $token 请求返回的 orderToken 参数
     * @param string $merchantSn 商户订单号
     * @return void
     */
    public function captureFull($token, $merchantSn)
    {
        $apiData = $this->merchant['api_data'];
        $url = $apiData['endpoint_domain'] . $apiData['capture_url'];
        $res = $this->request($url, [
            'token' => $token,
            'merchantReference' => $merchantSn
        ]);
        if (empty($res['body'])) {
            throw new \Exception('response error', $res['code']);
        }
        $resData = Utils::mapData([
            'error_code' => 0,
            'error_msg' => '',
            'status_descrip' => '', //状态: DECLINED拒绝, APPROVED通过
            'events' => [],
            'sn' => $merchantSn,
            'type_method' => '',
            'pay_id' => '',
            'pay_sn' => '',
            'originalAmount' => [
                'currency' => '',
                'amount' => 0
            ],
            'paymentState' => ''
        ], (json_decode($res['body'], true)), [
            //异常
            'error_code' => 'errorCode',
            'error_msg' => 'Description,message',
            'status_descrip' => 'status',
            'pay_id' => 'id',
            'pay_sn' => 'id'
        ]);
        if (!empty($resData['error_code'])) {
            throw new \Exception($resData['error_msg'], (int)$resData['error_code']);
        }
        $status = 0;
        $lastEvent = end($resData['events']);
        if ($resData['status_descrip'] == 'APPROVED') {
            if ($lastEvent['type'] == 'CAPTURED') {
                $status = 1;
            }
            $resData['status_descrip'] = $lastEvent['type'];
        }
        $resData['currency'] = $resData['originalAmount']['currency'];
        $resData['amount'] = $resData['originalAmount']['amount'];

        if (!empty($resData['paymentState'])) {
            $resData['status_descrip'] = $resData['paymentState'];
        }
        $resData['merchant'] = $this->merchant['merchant'];
        $resData['type'] = $this->merchant['type'];
        $resData['status'] = $status;
        return new OrderPayment($resData);
    }
    /**
     * 获取订单详情
     *
     * @param string $id 提交时成功返回的order_id
     * @param string $idempotencyKey 头部 HTTP_KLARNA_IDEMPOTENCY_KEY
     * @return void
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
        $res = $this->request($url, "", $header);
        if ($res['code'] != '200') {
            return [];
        }
        return !empty($res['body']) ? json_decode($res['body'], true) : [];
    }

    /**
     * 订单数据结构
     */
    protected function orderData(Order $Order)
    {
        $this->merchantCallUrl($Order);
        $callUrl = $this->merchant['call_url'];
        $data = Utils::mapData([
            'amount'   => [
                'amount' => Utils::caldiv($Order['purchase_amount'], 100),
                'currency' => $Order['currency']
            ],
            'consumer' => $this->consumerData($Order),
            'billing'  => $this->billingData($Order),
            'shipping' => $this->shippingData($Order),
            'merchant' => [
                'redirectConfirmUrl' => $callUrl['return_url'],
                'redirectCancelUrl' => $callUrl['cancel_url'],
            ],
            'description' => !empty($Order['descript']) ? $Order['descript'] : $Order['sn'],
            'items' => $this->orderItemsData($Order),
            'shippingAmount' => [
                'amount' => Utils::caldiv($Order['freight'], 100),
                'currency' => $Order['currency']
            ],
            'merchantReference' => $Order['sn']
        ], []);
        return $data;
    }
    /**
     * 订单商品数据
     *
     * @param Order $Order
     * @return array
     */
    protected function orderItemsData(Order $Order)
    {
        $items = [];
        foreach ($Order['products'] as $k => $v) {
            $line = Utils::mapData([
                'name'      => '',
                'quantity'     => 1,
                'price' => [
                    'amount' => Utils::caldiv($v['unit_price'], 100),
                    'currency' => $Order['currency']
                ]
            ], $v->toArray());
            $items[] = $line;
        }
        return $items;
    }
    /**
     * 用户信息
     *
     * @param Order $Order
     * @return array
     */
    protected function consumerData(Order $Order)
    {
        $billingData = $this->billingData($Order);
        return [
            'givenNames' => $billingData['name'],
            'surname' => $billingData['surname'],
            'email' => $billingData['email']
        ];
    }
    /**
     * 快递地址信息
     *
     * @param Order $Order
     * @return array
     */
    protected function shippingData(Order $Order)
    {
        return $this->addressData($Order, 'shipping');
    }
    /**
     * 帐单地址信息
     *
     * @param Order $Order
     * @return array
     */
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
            'name'      => '',
            'surname'     => '',
            'email'           => '',
            'countryCode'         => '',
            'region'          => '',
            'area1'            => '',
            'postcode'     => '',
            'line1'  => '',
        ], $addressArr, [
            'name' => 'first_name',
            'surname' => 'last_name',
            'countryCode' => 'country_code',
            'area1' => 'city',
            'postcode' => 'postal_code',
            'line1' => 'street_address',
        ]);
        if ($type == 'shipping') {
            $data['name'] = $data['name'] . ' ' . $data['surname'];
        }
        return $data;
    }

    protected function authorizationBasic()
    {
        $apiAccount = $this->merchant['api_account'];
        return base64_encode($apiAccount['merchant_id'] . ':' . $apiAccount['secret_key']);
    }

    protected function request($url, $data = [], array $header = [], $type = 'post')
    {
        //指定userAgent
        $apiAccount = $this->merchant['api_account'];
        $domain = Rt::domain();
        $phpVersion = defined('PHP_VERSION') ? PHP_VERSION : phpversion();
        $userAgent = 'AfterPayModule/1.0.0 (callie/1.0.0; PHP/' . $phpVersion . '; Merchant/' . $apiAccount['merchant_id'] . ' ' . $domain . ')';
        //请求headers
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . $this->authorizationBasic(),
            'region' => 'string',
            'Accept' => 'application/json',
        ];
        $headers = array_merge($headers, ['User-Agent' => strval($userAgent)], $header);
        if (!empty($data)) {
            $post = json_encode($data, true);
        } else {
            $post = '';
        }
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
