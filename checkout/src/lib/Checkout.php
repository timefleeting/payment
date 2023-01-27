<?php

namespace Netflying\Checkout\lib;

use Exception;

use Netflying\Payment\common\Utils;
use Netflying\Payment\common\Request as Rt;
use Netflying\Payment\lib\PayAbstract;
use Netflying\Payment\lib\Request;
use Netflying\Payment\data\Address as AddressData;

use Netflying\Payment\data\Order;
use Netflying\Payment\data\Redirect;
use Netflying\Payment\data\OrderPayment;
use Netflying\Payment\data\RequestCreate;
use Netflying\Checkout\data\Merchant as CheckoutMerchant;

use Checkout\CheckoutApiException;
use Checkout\CheckoutAuthorizationException;
use Checkout\CheckoutSdk;
use Checkout\Common\Address;
use Checkout\Common\Country;
use Checkout\Common\Currency;
use Checkout\Common\CustomerRequest;
use Checkout\Common\Phone;
use Checkout\Environment;
use Checkout\OAuthScope;
use Checkout\Payments\Request\PaymentRequest;
use Checkout\Payments\Request\Source\RequestCardSource;
use Checkout\Payments\Sender\Identification;
use Checkout\Payments\Sender\IdentificationType;
use Checkout\Payments\Sender\PaymentIndividualSender;

use Checkout\Payments\ThreeDsRequest;
use Checkout\Payments\RiskRequest;
use Checkout\HttpMetadata;
use Checkout\CheckoutException;

class Checkout extends PayAbstract
{
    protected $jsSdk = __DIR__ . '/../js/checkout.js';

    protected $verifySignError = "";  //错误验签结果

    //有效且完成状态
    protected static $completeArr = array(
        'authorized', 'captured', 'paid'
    );
    //失败状态
    protected static $failureArr = [
        'declined', 'voided', 'expired'
    ];
    //有效状态
    protected static $validArr = [
        'authorized', 'pending', 'card verified', 'partially captured', 'captured', 'paid'
    ];
    //取消状态
    protected static $cancelArr = [
        'canceled',
    ];
    //退款状态
    protected static $refundArr = [
        "partially refunded", "refunded"
    ];

    public function __construct($Merchant, $Log = '', $Cache = '')
    {
        if (is_array($Merchant)) {
            $Merchant = new CheckoutMerchant($Merchant);
        }
        $this->merchant($Merchant);
        $this->log($Log);
        $this->cache($Cache);
    }

    public function apiKeys()
    {
        $Merchant = $this->merchant;
        $apiAccount = $Merchant['api_account'];
        $api = CheckoutSdk::builder()->staticKeys();
        if ((int)$Merchant['is_test'] == 1) {
            $api->environment(Environment::sandbox());
        } else {
            $api->environment(Environment::production());
        }
        return $api->secretKey($apiAccount['secret_value'])->build();
    }

    /**
     * Create an access token to begin using our APIs.
     * OAuth endpoint to exchange your access key ID and access key secret for an access token.
     * @param OAuthScope $scopes 授权场景 OAuthScope::$params
     * @return $api
     */
    public function oAuth($scopes = "")
    {
        $Merchant = $this->merchant;
        $apiAccount = $Merchant['api_account'];
        $api = CheckoutSdk::builder()->oAuth()
            ->clientCredentials($apiAccount['access_id'], $apiAccount['access_value']);
        if (!empty($scopes)) {
            if (is_array($scopes)) {
                $api->scopes($scopes);
            } else {
                $api->scopes([$scopes]);
            }
        } else {
            $api->scopes([OAuthScope::$Gateway]);
        }
        if ((int)$Merchant['is_test'] == 1) {
            $api->environment(Environment::sandbox());
        } else {
            $api->environment(Environment::production());
        }
        return $api->build();
    }


    public function purchase(Order $Order): Redirect
    {
        $this->merchantCallUrl($Order);
        $CreditCard = $Order['credit_card']->creditCard();
        $Order->setCreditCardData($CreditCard);
        $callUrl = $this->merchant['call_url'];
        $apiAccount = $this->merchant['api_account'];
        $request = new PaymentRequest();
        $request->capture = true;  //auto capture
        $request->reference = $Order['sn'];
        $request->amount = $Order['purchase_amount'];
        $request->currency = strtoupper($Order['currency']);
        $request->source = $this->creditCard($Order);
        $request->customer = $this->customer($Order);
        //$request->sender = $this->sender($Order);
        $request->payment_ip = $Order['client_ip'];
        $request->success_url = $callUrl['success_url'];
        $request->failure_url = $callUrl['failure_url'];
        if (!empty($apiAccount['processing_channel_id'])) {
            $request->processing_channel_id = $apiAccount['processing_channel_id'];
        }
        $is3ds = !empty($apiAccount['is_3ds']) ? true : false;
        $request->three_ds = $this->threeDs($Order, $is3ds);
        $res = $this->request($request);
        $code = $res['code'];
        if (!empty($res['reference']['redirect'])) {
            return $this->toRedirect($res['reference']['redirect'], [], 'get');
        }
        if ($code >= 200 && $code < 300) {
            $status = strtolower($res['reference']['status']);
            //success
            if (in_array($status, self::$validArr)) {
                $url = $callUrl['success_url'];
            } 
            // else if (in_array($status, self::$failureArr)) {
            //     $url = $callUrl['failure_url'];
            //     if (!empty($res['reference']['id'])) {
            //         $url = Rt::buildUri($url, ['cko-session-id' => $res['reference']['id']]);
            //     }
            // } 
            // else if (in_array($status, self::$cancelArr)) {
            //     $url = $callUrl['cancel_url'];
            // } 
            else { //失败/取消/异常状态
                return $this->errorRedirect($res['errno'], $res['errmsg'], $code);
            }
            return $this->toRedirect($url, [], 'get', ['code' => 0, 'msg' => $status, 'httpcode' => $code]);
        } else {
            return $this->errorRedirect(0, $res['errmsg']);
        }
    }


    public function callReturn()
    {
    }

    public function callFailure()
    {
        $mode = Utils::mapData([
            "id" => ''
        ], Rt::receive(), [
            "id" => ['cko-session-id']
        ]);
        $res = $this->paymentDetails($mode['id']);
        $statusMsg = isset($res['reference']['status']) ? $res['reference']['status'] : '';
        $errMsg = isset($res['errmsg']) ? $res['errmsg'] : '';
        $msg = !empty($statusMsg) ? $statusMsg : $errMsg;
        return $this->errorRedirect(0, $msg, $res['code']);
    }

    /**
     * 支付回调
     *
     * @return OrderPayment
     */
    public function notify(): OrderPayment
    {
        $data = Rt::receive();
        $verify = $this->verifySign();
        if (empty($verify)) {
            return new OrderPayment([
                'status_descrip' => $this->verifySignError
            ], 2);
        }
        $payment = Utils::mapData([
            "type" => "",
            "data" => [],
            "created_on" => ""
        ], $data);
        $type = strtolower($payment["type"]);

        $source = Utils::mapData([
            "scheme" => ""
        ], ($payment['data']['source'] ?? []));

        $res = Utils::mapData([
            "sn" => "",
            "status_descrip" => $payment["type"],
            "currency" => "",
            "amount" => 0, //单位分
            "type_method" => $source["scheme"],
            "pay_id" => "",
            "pay_sn" => "",
        ], $payment['data'], [
            "sn" => "reference",
            "pay_id" => "id",
            "pay_sn" => "action_id"
        ]);

        if (empty($res['type_method'])) {
            $details = $this->paymentDetails($res['pay_id']);
            if ($details['code']>=200 && $details['code']<300 && !empty($details['body'])) {
                $body = json_decode($details['body'], true);
                $bodySource = isset($body['source']) ? $body['source'] : [];
                $source = Utils::mapData([
                    "scheme" => ""
                ], $bodySource);
                $res['type_method'] = $source["scheme"];
            }
        }
        
        $res['amount'] = Utils::caldiv($res['amount'], 100);
        $res['type'] = $this->merchant['type_id'];
        $res['type_id'] = $this->merchant['id'];
        $res['merchant'] = $this->merchant['merchant'];
        $res['pay_time'] = !empty($payment['created_on']) ? strtotime($payment['created_on']) : 0;
        $res['fee'] = 0;
        $status = -2;
        $successArr   = ['payment_captured']; //支付成功状态
        $refundArr    = ["payment_refunded"]; //"payment_capture_declined", 
        if (in_array($type, $successArr)) {
            $status = 1;
        } elseif ($type == "payment_declined" || $type == "payment_approved" || $type == "payment_capture_declined" ) {
            $status = 0;
        } elseif (in_array($type, $refundArr)) {
            $status = -1;
            $res['amount'] = -abs($res['amount']);
        }
        $res['status'] = $status;
        return new OrderPayment($res);
    }

    public function verifySign()
    {
        $authorization = Rt::header("authorization") ?? "";
        $signature  = Rt::header("cko-signature") ?? "";
        $Merchant = $this->merchant;
        $apiAccount = $Merchant['api_account'];
        if ($authorization != $apiAccount['authorization_key']) {
            $this->verifySignError = "authorization_key: " . $authorization . " != " . $apiAccount['authorization_key'];
            return false;
        }
        $raw = Rt::receive("input") ?? "";
        $rawSign = hash_hmac('sha256', $raw, $apiAccount['signature_key']);
        $rs = $rawSign == $signature;
        if (!$rs) {
            $this->verifySignError = "signature_key: " . $rawSign . " != " . $signature;
        }
        return $rs;
    }

    /**
     * 订单用户帐单基本信息
     *
     * @param Order $Order
     * @return CustomerRequest
     */
    protected function customer(Order $Order)
    {
        $address = $Order['address'];
        $billing = $address['billing']->toArray();
        $customerRequest = new CustomerRequest();
        $customerRequest->email = $billing['email'];
        $customerRequest->name = trim($billing['first_name']) . ' ' . trim($billing['last_name']);
        return $customerRequest;
    }
    /**
     * 订单帐单信息
     *
     * @param Order $Order
     * @return Address
     */
    protected function billing(Order $Order)
    {
        $addressData = $Order['address'];
        $billing = $addressData['billing']->toArray();
        $address = new Address();
        $address->address_line1 = $billing['street_address'];
        $address->address_line2 = $billing['street_address1'];
        $address->city = $billing['city'];
        $address->state = $billing['region'];
        $address->zip = $billing['postal_code'];
        $address->country = strtoupper($billing['country_code']);
        return $address;
    }
    /**
     * 用户信息卡&帐单信息
     *
     * @param Order $Order
     * @return RequestCardSource
     */
    protected function creditCard(Order $Order)
    {
        // 电话号码需要传区号
        // $phone = new Phone();
        // $phone->country_code = "+1";
        // $phone->number = "415 555 2671";
        $card = $Order['credit_card_data'];
        $requestCardSource = new RequestCardSource();
        $requestCardSource->name = $card['holder_name'];
        $requestCardSource->number = $card['card_number'];
        $requestCardSource->expiry_year = (int)$card['expiry_year'];  //4位整型
        $requestCardSource->expiry_month = (int)$card['expiry_month'];   //2位整型
        $requestCardSource->cvv = $card['cvc'];
        $requestCardSource->billing_address = $this->billing($Order);
        //$requestCardSource->phone = $phone;
        return $requestCardSource;
    }
    /**
     * 用户信息
     *
     * @param Order $Order
     * @return void
     */
    protected function sender(Order $Order)
    {
        //type: 护照，驾照，身份证
        // $identification = new Identification();
        // $identification->issuing_country = Country::$GT;
        // $identification->number = "1234";
        // $identification->type = IdentificationType::$drivingLicence;
        $address = $Order['address'];
        $billing = $address['billing']->toArray();
        $paymentIndividualSender = new PaymentIndividualSender();
        $paymentIndividualSender->fist_name = trim($billing['first_name']);
        $paymentIndividualSender->last_name = trim($billing['last_name']);
        $paymentIndividualSender->address = $this->billing($Order);
        //$paymentIndividualSender->identification = $identification;
        return $paymentIndividualSender;
    }

    /**
     * Information required for 3D Secure authentication payments
     * One of:Integrated authentication.  Standalone authentication.  Third party authentication
     * @param Order $Order
     * @return ThreeDsRequest
     */
    protected function threeDs(Order $Order, $is3ds = false)
    {
        $is3ds = (bool)$is3ds;
        $theeDsRequest = new ThreeDsRequest();
        $theeDsRequest->enabled = $is3ds; //Default: false; Whether to process this payment as a 3D Secure payment.
        $theeDsRequest->attempt_n3d = true; //Default: false; Determines whether to attempt a 3D Secure payment as non-3D Secure should the card issuer not be enrolled.
        $theeDsRequest->challenge_indicator = $this->getChallengePreference(1); //Default: "no_preference"
        return $theeDsRequest;
    }
    /**
     * Configures the risk assessment performed during the processing of the payment
     * default: true
     * @return RiskRequest
     */
    protected function risk()
    {
        $risk = new RiskRequest();
        $risk->enabled = false;
        return $risk;
    }

    /**
     * Indicates the preference for whether or not a 3DS challenge should be performed. The customer’s bank has the final say on whether or not the customer receives the challenge.
     * Default: "no_preference"  Enum: "no_preference" "no_challenge_requested" "challenge_requested" "challenge_requested_mandate"
     * @param integer $idx
     * @return void
     */
    protected function getChallengePreference($idx = 0)
    {
        $list = [
            'no_preference',
            'no_challenge_requested',
            'challenge_requested',
            'challenge_requested_mandate'
        ];
        return isset($list[$idx]) ? $list[$idx] : $list[0];
    }

    protected function request(PaymentRequest $request)
    {
        $self = $this;
        return Request::create(new RequestCreate([
            'type' => 'api',
            'url' => function () use ($self, $request) {
                $httpcode = 0;
                $header = [];
                $errno  = 0;
                $errmsg = "";
                $body = "";
                $response = [];
                $status = "";
                $redirect = "";
                $id = "";
                try {
                    $api = $self->oAuth(OAuthScope::$GatewayPayment);
                    $response = $api->getPaymentsClient()->requestPayment($request);
                    if (!empty($response['http_metadata'])) {
                        $httpcode = $response['http_metadata']->getStatusCode();
                        $header = $response['http_metadata']->getHeaders();
                    }
                    if (!empty($response['3ds'])) {
                        $redirect = isset($response['_links']["redirect"]["href"]) ? $response['_links']["redirect"]["href"] : "";
                    }
                    $body = json_encode($response);
                    $resData = Utils::mapData([
                        'code' => $errno,
                        'msg'  => $errmsg,
                        'status' => '',
                    ], $response, [
                        'code' => 'response_code',
                        'msg'  => 'response_summary'
                    ]);
                    $errno = $resData['code'];
                    $errmsg = $resData['msg'];
                    $status = $resData['status']; //Enum: "Authorized" "Pending" "Card Verified" "Declined"
                    $id = isset($response['id']) ? $response['id'] : '';
                } catch (CheckoutApiException $e) {
                    // API error
                    $error_details = $e->error_details;
                    $http_status_code = isset($e->http_metadata) ? $e->http_metadata->getStatusCode() : null;
                    $httpcode = $http_status_code;
                    $errmsg = isset($error_details['error_codes']) ? $error_details['error_codes'] : '';
                    $errmsg = is_array($errmsg) ? implode(',', $errmsg) : $errmsg;
                } catch (CheckoutAuthorizationException $e) {
                    // Bad Invalid authorization
                    $errmsg = "Bad Invalid authorization";
                    preg_match('/.*response\:(.*)/is', $e->getMessage(), $arr);
                    if (!empty($arr[1])) {
                        $errmsg = $arr[1];
                    }
                } catch (CheckoutException $e) {
                    $errmsg = "Authorization failure";
                    preg_match('/.*response\:(.*)/is', $e->getMessage(), $arr);
                    if (!empty($arr[1])) {
                        $errmsg = $arr[1];
                    }
                } catch (Exception $e) {
                    $errmsg = $e->getMessage();
                }
                return [
                    'code' => $httpcode,
                    'errno' => $errno,
                    'errmsg' => $errmsg,
                    'header' => $header,
                    'body' => $body,
                    'reference' => [
                        "id" => $id,
                        "status" => $status,
                        "redirect" => $redirect //3ds跳转
                    ],
                ];
            },
            'data' => json_encode($request), //记录传递参数
            'log' => $this->log,
            'title' => get_class($this)
        ]));
    }

    /**
     * 支付后详情信息
     *
     * Returns the details of the payment with the specified identifier string
     * If the payment method requires a redirection to a third party (e.g., 3D Secure), 
     * the redirect URL back to your site will include a cko-session-id query parameter containing a payment session ID that can be used to obtain the details of the payment, 
     * for example: http://example.com/success?cko-session-id=sid_ubfj2q76miwundwlk72vxt2i7q.
     * 
     * @param string $id  ^(pay|sid)_(\w{26})$  The payment or payment session identifier
     * @return Response
     */
    public function paymentDetails($id)
    {
        $self = $this;
        return Request::create(new RequestCreate([
            'type' => 'api',
            'url' => function () use ($self, $id) {
                $httpcode = 0;
                $header = [];
                $errno = "";
                $errmsg = "";
                $body = "";
                $response = [];
                $status = "";
                try {
                    if (empty($id)) {
                        throw new \Exception("Bad Invalid authorization");
                    }
                    $api = $self->oAuth(OAuthScope::$GatewayPaymentDetails);
                    $response = $api->getPaymentsClient()->getPaymentDetails($id);
                    if (!empty($response['http_metadata'])) {
                        $httpcode = $response['http_metadata']->getStatusCode();
                        $header = $response['http_metadata']->getHeaders();
                    }
                    $body = json_encode($response);
                    $resData = Utils::mapData([
                        'code' => $errno,
                        'msg'  => $errmsg,
                        'status' => '',
                    ], $response, [
                        'code' => 'response_code',
                        'msg'  => 'response_summary'
                    ]);
                    $errno = $resData['code'];
                    $errmsg = $resData['msg'];
                    $status = $resData['status']; //Enum: "Authorized" "Pending" "Card Verified" "Declined"
                } catch (CheckoutApiException $e) {
                    // API error
                    $error_details = $e->error_details;
                    $http_status_code = isset($e->http_metadata) ? $e->http_metadata->getStatusCode() : null;
                    $httpcode = $http_status_code;
                    $errmsg = isset($error_details['error_codes']) ? $error_details['error_codes'] : $e->getMessage();
                } catch (CheckoutAuthorizationException $e) {
                    // Bad Invalid authorization
                    $errmsg = "Bad Invalid authorization";
                    preg_match('/.*response\:(.*)/is', $e->getMessage(), $arr);
                    if (!empty($arr[1])) {
                        $errmsg = $arr[1];
                    }
                } catch (CheckoutException $e) {
                    $errmsg = "Authorization failure";
                    preg_match('/.*response\:(.*)/is', $e->getMessage(), $arr);
                    if (!empty($arr[1])) {
                        $errmsg = $arr[1];
                    }
                } catch (Exception $e) {
                    $errmsg = $e->getMessage();
                }
                return [
                    'code' => $httpcode,
                    'errno' => $errno,
                    'errmsg' => $errmsg,
                    'header' => $header,
                    'body' => $body,
                    'reference' => [
                        'status' => $status,
                    ],
                ];
            },
            'data' => $id, //记录传递参数
            'log' => $this->log,
            'title' => get_class($this)
        ]));
    }
}
