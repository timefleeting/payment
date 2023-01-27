<?php

namespace Netflying\Payment\lib;

use Netflying\Payment\common\Utils;
use Netflying\Payment\common\Request as Rt;
use Netflying\Payment\data\Merchant;
use Netflying\Payment\data\Order;
use Netflying\Payment\data\Redirect;

abstract class PayAbstract implements PayInterface
{
    //商户数据模型
    protected $merchant = null;
    //日志对象
    protected $log = '';
    //缓存对象
    protected $cache = '';

    protected $requestException = [
        'code' => 0,
        'msg' => '',
        'httpcode' => 0
    ];

    protected $error = [
        'msg' => '',
        'code' => 0
    ];

    public function __construct($Merchant, $Log = '', $Cache = '')
    {
        if (is_array($Merchant)) {
            $Merchant = new Merchant($Merchant);
        }
        $this->merchant($Merchant);
        $this->log($Log);
        $this->cache($Cache);
    }

    public function setError($msg, $code = 0)
    {
        $this->error = [
            'msg' => $msg,
            'code' => $code
        ];
        return $this;
    }
    public function getError()
    {
        return $this->error;
    }

    /**
     * 初始化商户及相应call url
     * @param Merchant $Merchant
     * @return self
     */
    public function merchant(Merchant $Merchant)
    {
        $callRoute = $Merchant['call_route'];
        $callUrl = $Merchant['call_url'];
        $id = $Merchant['id'];
        //自动构建call url
        $urls = Utils::modeData($callUrl, [
            'return_url'  => Rt::buildUri($callRoute, ['_call' => 'return', '_sn' => '{{sn}}', '_id' => $id]),
            'success_url' => Rt::buildUri($callRoute, ['_call' => 'success', '_sn' => '{{sn}}', '_id' => $id]),
            'failure_url' => Rt::buildUri($callRoute, ['_call' => 'failure', '_sn' => '{{sn}}', '_id' => $id]),
            'pending_url' => Rt::buildUri($callRoute, ['_call' => 'pending', '_sn' => '{{sn}}', '_id' => $id]),
            'cancel_url'  => Rt::buildUri($callRoute, ['_call' => 'cancel', '_sn' => '{{sn}}', '_id' => $id]),
            'notify_url'  => Rt::buildUri($callRoute, ['_call' => 'notify', '_sn' => '{{sn}}', '_id' => $id]),
            'authorise_renturn_url' => Rt::buildUri($callRoute, ['_call' => 'authorise_renturn', '_sn' => '{{sn}}', '_id' => $id]),
            'authorise_cancel_url' => Rt::buildUri($callRoute, ['_call' => 'authorise_cancel', '_sn' => '{{sn}}', '_id' => $id]),
            'threeds_url' => Rt::buildUri($callRoute, ['_call' => 'threeds', '_sn' => '{{sn}}', '_id' => $id])
        ]);
        if (empty($Merchant['type_class'])) {
            $Merchant->setTypeClass = $Merchant['type'];
        }
        $Merchant->setCallUrl($urls);
        $this->merchant = $Merchant;
        return $this;
    }
    public function getMerchant()
    {
        return $this->merchant;
    }
    /**
     * 日志对象
     */
    public function log($Log = '')
    {
        if ($Log instanceof LogInterface) {
            $this->log = $Log;
        }
        return $this;
    }
    public function getLog()
    {
        return $this->log;
    }
    /**
     * 缓存类对象
     */
    public function cache($Cache = '') 
    {
        if ($Cache instanceof CacheInterface) {
            $this->cache = $Cache;
        }
        return $this;
    }
    public function getCache()
    {
        return $this->cache;
    }
    /**
     * 通用sdk信息布署,初始化商户信息的sdk内容
     *
     * @return string
     */
    public function jsSdk()
    {
        if (empty($this->jsSdk)) {
            return '';
        }
        $url = $this->jsSdk;
        if (!is_file($url)) {
            return '';
        }
        $js = file_get_contents($url);
        $config = $this->jsSdkConfig();
        $js = str_replace(['"{{CONFIG}}"', "'{{CONFIG}}"], json_encode($config), $js);
        return $js;
    }
    /**
     * render渲染需要的信息
     *
     * @param [type] $order
     * @return void
     */
    public function jsRender(Order $order)
    {
        return [];
    }

    /**
     * 完善回调地址中的参数变量
     * 
     * @param Order $Order
     * @return self
     */
    protected function merchantCallUrl(Order $Order)
    {
        $Merchant = $this->merchant;
        $callUrl = $Merchant['call_url'];
        $sn = $Order['sn'];
        $urlReplace = function ($val) use ($sn) {
            $val = urldecode($val);
            return str_replace(['{{sn}}', '{$sn}'], $sn, $val);
        };
        $urlData = Utils::modeData($callUrl, $callUrl, [
            'return_url' => $urlReplace,
            'success_url' => $urlReplace,
            'failure_url' => $urlReplace,
            'pending_url' => $urlReplace,
            'cancel_url' => $urlReplace,
            'notify_url' => $urlReplace,
            'authorise_renturn_url' => $urlReplace,
            'authorise_cancel_url' => $urlReplace,
            'threeds_url' => $urlReplace,
        ]);
        $callUrl = array_merge($callUrl, $urlData);
        $Merchant->setCallUrl($callUrl);
        $this->merchant = $Merchant;
        return $this;
    }

    /**
     * js sdk需要的商户信息
     *
     * @return void
     */
    protected function jsSdkConfig(array $params = [])
    {
        $merchant = $this->merchant;
        $data = Utils::mapData([
            "id" => 0,
            "type" => '',
            "type_id" => "",
            "type_class" => "",
            "is_billing" => 0,
            "is_credit" => 0,
            "is_phone" => 0,
            "is_default" => 0,
            "is_hide" => 0,
            // "country_amount" => [],
            // "currency_amount" => [],
            "type_info" => $merchant->getTypeInfo(),
            "i18n" => $merchant->getI18n(),
            "throw_ids" => "",
        ], $merchant->toArray());
        if (!empty($params)) {
            $data = Utils::mapData($data, $params);
        }
        return $data;
    }

    /**
     * 回调通知地址处理
     * @return Redirect
     */
    public function callReturn()
    {
    }
    public function callSuccess()
    {
        return $this->callRedirect(1);
    }
    public function callCancel()
    {
        return $this->callRedirect(-1);
    }
    public function callFailure()
    {
        return $this->callRedirect(0);
    }
    public function callPending()
    {
        return $this->callRedirect(2);
    }
    public function callNotify()
    {
    }
    public function callAuthoriseRenturn()
    {
    }
    public function callAuthoriseCancel()
    {
    }
    public function callThreeds()
    {
    }
    /**
     * 业务跳转
     *
     * @param integer $status
     * @return Redirect
     */
    protected function callRedirect($status = 0)
    {
        $data = Utils::mapData(
            [
                'sn' => '',
            ],
            Rt::receive(),
            [
                'sn' => ['sn', '_sn']
            ]
        );
        return new Redirect([
            'status' => 1, //跳转
            'url' => '',  //业务端定义或相应类包定义
            'type' => 'post', //业务端决定
            'params' => [
                'sn' => $data['sn'],
                'status' => $status, //业务状态
            ]
        ], 2);
    }
    protected function setException($code, $msg, $httpcode = 0)
    {
        $this->requestException = [
            'code' => $code,
            'msg'  => $msg,
            'httpcode' => $httpcode
        ];
        return $this;
    }
    protected function getException()
    {
        return $this->requestException;
    }

    /**
     * 错误请求结果
     * 
     * @return Redirect
     */
    protected function errorRedirect($code = 0, $msg = '', $httpcode = 0)
    {
        $exception = [
            'code' => $code
        ];
        if (!empty($msg)) {
            $exception['msg'] = $msg;
        }
        if (!empty($httpcode)) {
            $exception['httpcode'] = $httpcode;
        }
        $getException = $this->getException();
        $exceptionRs = Utils::mapData([
            'code' => $getException['code'],//异常码 默认:0未知或正常。 其它:返回结果响应码,有可能是字符串
            'msg' => $getException['msg'],//异常信息。正常默认为空  响应回来的信息
            'httpcode' => $getException['httpcode'] //http响应码。 默认0正常或200系列正常响应
        ], $exception);

        return new Redirect([
            'status' => 0, //请求异常，非跳转
            'url' => '',
            'type' => 'get',
            'params' => [],
            'exception' => $exceptionRs
        ]);
    }
    /**
     * 跳转
     * @param string $url 跳转链接，为空跳转到失败页
     * @param array $data
     * @param string $type
     * @return void
     */
    protected function toRedirect($url, $data = [], $type = 'get', $exception = [])
    {
        $getException = $this->getException();
        $exceptionRs = Utils::mapData([
            'code' => $getException['code'],
            'msg' => $getException['msg'],
            'httpcode' => $getException['httpcode']
        ], $exception);
        return new Redirect([
            'status' => 1,  //不论是否有url,必跳
            'url' => $url,
            'type' => $type,
            'params' => $data,
            'exception' => $exceptionRs
        ]);
    }
}
