<?php

namespace Netflying\Payment\lib;

use Netflying\Payment\common\Utils;

use Netflying\Payment\data\MerchantCountryAmount;
use Netflying\Payment\data\MerchantCurrencyAmount;
use Netflying\Payment\common\Request as Rt;
use Netflying\Payment\common\Openssl;

use Netflying\Payment\data\Order;
use Netflying\Payment\data\OrderProduct;
use Netflying\Payment\data\Address;
use Netflying\Payment\data\CreditCardSsl;
use Netflying\Payment\data\OrderDevice;
use Netflying\Payment\data\Redirect;

/**
 * 支付类包调度运用及说明
 * 
 * 一、注册
 * 1. 组装支付商家相对应数据模型; 基类数据模型: Netflying\Payment\data\Merchant
 * 2. 创建支付商品类实例; merchantPayObj()
 * 3. 注册实例，供调度; register()
 * 
 * 二、约定开放http接口
 *  self::jsSdkInit(); 客户端实例包，公共调度类包。
 *  self::jsSdks();  客户端js sdk脚本,所有已注册的包脚本
 *  self::jsRender();  客户端render渲染时需要的交互数据信息
 *  self::returnBack(); 各种回调地址调度ww
 */
class Payment
{
    //订单号前缀
    public static $preSn = '';
    //开启标识支付id后缀,根据后缀区分不同回调
    public static $doSufSn = '';

    private static $sdkjs = 'payment.js';

    protected static $cacheMaxAge = 0;

    protected static $payObjs = [];
    /**
     * 注册商户支付渠道信息
     *
     * @param array $merchant 商户配置数据结构
     * @param string $Log 日志对象 Netflying\Payment\lib\LogInterface;
     * @return mixed
     */
    public static function register(array $merchant, $Log = '', $Cache = '')
    {
        $merchantPayObj = static::merchantPayObj($merchant, $Log, $Cache);
        return static::registerPayObj($merchantPayObj);
    }

    /**
     * 根据merchant信息自动生成PayInterface实例
     */
    public static function merchantPayObj(array $merchant, $Log = '', $Cache = '')
    {
        $type = !empty($merchant['type']) ? ucwords($merchant['type']) : 0;
        if (empty($type)) {
            return false;
        }
        $typeClass = !empty($merchant['type_class']) ? ucwords($merchant['type_class']) : $type;
        if (!empty($merchant['country_amount'])) {
            foreach ($merchant['country_amount'] as $k => $v) {
                if (empty($v)) {
                    continue;
                }
                $merchant['country_amount'][$k] = new MerchantCountryAmount($v);
            }
        }
        if (!empty($merchant['currency_amount'])) {
            foreach ($merchant['currency_amount'] as $k => $v) {
                if (empty($v)) {
                    continue;
                }
                $merchant['currency_amount'][$k] = new MerchantCurrencyAmount($v);
            }
        }
        $class = "\\Netflying\\" . $type . "\\lib\\" . $typeClass;
        return new $class($merchant, $Log, $Cache);
    }

    /**
     * 注册支付接口
     * @param PayInterface $PayObj
     * @return static
     */
    public static function registerPayObj(PayInterface $PayObj)
    {
        $Merchant = $PayObj->getMerchant();
        $id = $Merchant->getId();
        self::$payObjs[$id] = $PayObj;
        return static::class;
    }

    /**
     * 获取已注册支付对象
     *
     * @param string $id 唯一id
     * @return PayInterface
     */
    public static function getRegisterPayObj($id)
    {
        return isset(self::$payObjs[$id]) ? self::$payObjs[$id] : false;
    }

    /**
     * *开放引用接口
     * 装载初始公共sdk
     * @return void
     */
    public static function jsSdkInit(array $customConfig = [])
    {
        self::cacheControl();
        echo self::jsSdk($customConfig);
        die;
    }
    /**
     * jsSdk前后端通信运用, 返回数据结构各类包自定义
     *
     * @param $fn 回调方法, 当存在order_sn订单号时,order参数信息需要从数据库中获取。
     * @return array
     */
    public static function jsRender($fn = '')
    {
        $post = Rt::receive('post');
        $data = Utils::mapData([
            'id' => '',
            'order' => [],
            'render_data' => []
        ], $post);
        $order = $data['order'];
        if ($fn instanceof \Closure) {
            $fnOrder = $fn($data['render_data'], $order);
            $order = !empty($fnOrder) ? array_merge($order, $fnOrder) : $order;
        }
        $OrderObj = static::orderData($order, $data['render_data']);
        $OrderObj['sn'] = static::$preSn . $OrderObj['sn'];
        if (!empty(static::$doSufSn)) {
            $OrderObj['sn'] .= static::$doSufSn . $data['id'];
        }
        $PayObj = self::getRegisterPayObj($data['id']);
        if (!empty($PayObj)) {
            return $PayObj->jsRender($OrderObj);
        } else {
            return [];
        }
    }

    /**
     * *开放引用接口
     * 需要获取所有支持的已注册接口的支付js sdk
     * @return void
     */
    public static function jsSdks()
    {
        $js = [];
        foreach (self::$payObjs as $id => $PayObj) {
            $js[] = $PayObj->jsSdk();
        }
        self::cacheControl();
        echo implode("\r\n", $js);
        die;
    }
    /**
     * 支付信息
     *
     * @param $fn 回调方法, 当存在order_sn订单号时,order参数信息需要从数据库中获取。
     * @return Redirect
     */
    public static function purchase($fn = '', array $post = [])
    {
        $post = !empty($post) ? $post : Rt::receive('post');
        $data = Utils::mapData([
            'id' => '', //支付id
            'order' => [], //订单详情
            'render_data' => [], //render注册支付对象初始的参数
        ], $post);
        $order = $data['order'];
        if ($fn instanceof \Closure) {
            $fnOrder = $fn($data['render_data'], $order);
            $order = !empty($fnOrder) ? array_merge($order, $fnOrder) : $order;
        }
        $OrderObj = static::orderData($order, $data['render_data']);
        $OrderObj['sn'] = static::$preSn . $OrderObj['sn'];
        if (!empty(static::$doSufSn)) {
            $OrderObj['sn'] .= static::$doSufSn . $data['id'];
        }
        $PayObj = self::getRegisterPayObj($data['id']);
        if (!empty($PayObj)) {
            return $PayObj->purchase($OrderObj);
        } else {
            return new Redirect([], 2);
        }
    }

    /**
     * *开放引用接口
     * 返回地址调度业务逻辑处理
     * @return Redirect
     */
    public static function returnBack()
    {
        $receive = Rt::receive();
        $mode = Utils::mapData([
            '_call' => '',
            '_sn'   => '',
            '_id' => ''
        ], $receive);
        if (!empty($mode['_id']) && !empty($mode['_call'])) {
            $PayObj = self::getRegisterPayObj($mode['_id']);
            if (!empty($PayObj)) {
                $method = Utils::camelcaseName('call', $mode['_call']);
                $rs = $PayObj->$method();
                if ($rs instanceof Redirect) {
                    return $rs;
                }
            }
        }
        return new Redirect([], 2);
    }
    /**
     * 生成订单数据对象
     *
     * @param array $order  payment.js 客户端订单相关数据结构
     * [
     *  'order_sn' => '',
     *  'shipping' => [],
     *  'billing' => [],
     *  'creditcard' => "",
     *  'device' => []
     * ]
     * + 后端留存的订单金额相关信息,金额相关及运算不能通过前端传递，必须后端计算
     * [
     *    "purchase_amount" => 0,
     *    "freight" => 0,
     *     .....
     *    "products" => [
     *     .....
     *    ]
     * ]
     * @param array jssdk渲染三要素数据信息
     * @return Order
     */
    public static function orderData(array $order = [], array $renderData = [])
    {
        $param = Utils::mapData([
            'purchase_amount' => 0,
            'currency' => '',
            'country_code' => '',
            'freight' => 0
        ], $renderData, [
            'purchase_amount' => ['purchase_amount', 'amount']
        ]);
        if (empty($order['order_sn'])) {
            $purchaseAmount = (int)$param['purchase_amount'];
            $freight = (int)$param['freight'];
            $productAmount = $purchaseAmount - $freight;
            return new Order([
                "purchase_amount" => $purchaseAmount,
                'currency' => $param['currency'],
                'country_code' => $param['country_code'],
                "freight" => $freight,
                "address" => [
                    "shipping" => new Address([
                        'country_code' => $param['country_code']
                    ], 2),
                    "billing" => new Address([
                        'country_code' => $param['country_code']
                    ], 2),
                ],
                "products" => [
                    new OrderProduct([
                        'sn' => 0,
                        'name' => "1",
                        'reference_id' => 0,
                        'reference' => 0,
                        'quantity' => 1,
                        'unit_price' => $productAmount,
                        'total_price' => $productAmount,
                        'total_tax_amount' => 0,
                        'tax_rate' => 0,
                        'image_url' => null,
                        'product_url' => null
                    ], 0)
                ],
                "extend" => !empty($order['extends']) ? $order['extends'] : []
            ], 2);
        } else {
            $products = $order['products'];
            $productArr = [];
            if (!empty($products)) {
                foreach ($products as $k => $v) {
                    $productArr[] = new OrderProduct($v);
                }
            }
            $shipping = $order['shipping'];
            if (empty($order['billing'])) {
                //默认帐单地址跟随快递地址
                $billing = $shipping;
            } else {
                $billing = $order['billing'];
            }
            $order['products'] = $productArr;
            if (!empty($shipping)) {
                $shippingObj = new Address($shipping);
                $billingObj  = new Address($billing);
                $order['address'] = [
                    'shipping' => $shippingObj,
                    'billing' => $billingObj
                ];
            } else {
                $order['address'] = [];
            }
            if (!empty($order['creditcard'])) {
                $creditCardObj = new CreditCardSsl([
                    'encrypt' => $order['creditcard']
                ]);
                $order['credit_card'] = $creditCardObj;
            } else {
                $order['credit_card'] = new CreditCardSsl([], 2);
            }
            if (!empty($order['device'])) {
                $order['device_data'] = new OrderDevice($order['device']);
            }
            if (empty($order['address']['shipping'])) {
                $order['address']['shipping'] = new Address([
                    'country_code' => $param['country_code']
                ], 2);
            }
            if (empty($order['address']['billing'])) {
                $order['address']['billing'] = new Address([
                    'country_code' => $param['country_code']
                ], 2);
            }
            return new Order($order);
        }
    }

    public static function publicPem()
    {
        return Openssl::publicContent();
    }

    /**
     * 支付通道信息及限制信息
     */
    public static function merchantsInfo()
    {
        $data = [];
        foreach (self::$payObjs as $k => $obj) {
            $amountInfo = self::currencyCountryAmount($obj);
            $typeInfo = self::typeInfo($obj);
            $data[] = array_merge($amountInfo, ['type_info' => $typeInfo]);
        }
        return $data;
    }

    public static function currencyCountryAmount(PayInterface $obj)
    {
        $Merchant = $obj->getMerchant();
        $currencyAmount = [];
        foreach ($Merchant['currency_amount'] as $k => $obj) {
            $currencyAmount[$k] = $obj->toArray();
        }
        $countryAmount = [];
        foreach ($Merchant['country_amount'] as $k => $obj) {
            $countryAmount[$k] = $obj->toArray();
        }
        $data = [
            'type' => $Merchant['type'],
            'type_id' => $Merchant['type_id'],
            'currency_amount' => $currencyAmount,
            'country_amount' => $countryAmount,
        ];
        return $data;
    }
    public static function typeInfo(PayInterface $obj)
    {
        $Merchant = $obj->getMerchant();
        return $Merchant['type_info'];
    }

    /**
     * payment sdk引用地址内容接口
     *
     * @return string
     */
    public static function jsSdk(array $customConfig = [])
    {
        $url = __DIR__ . '/../js/' . self::$sdkjs;
        $js = file_get_contents($url);
        //js 初始化参数
        $config = [
            'public_key' => self::publicPem(),
        ];
        if (!empty($customConfig)) {
            $config = array_merge($config, $customConfig);
        }
        $js = str_replace(['"{{CONFIG}}"', "'{{CONFIG}}"], json_encode($config), $js);
        return $js;
    }



    /** 资源缓存头部
     * @return void
     */
    protected static function cacheControl($type = 'js')
    {
        if ($type == 'js') {
            header('Content-type: application/x-javascript');
        }
        if (static::$cacheMaxAge > 0) {
            header('Cache-Control: max-age=' . static::$cacheMaxAge);
        } else {
            header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
            header("Cache-Control: post-check=0, pre-check=0", false);
            header("Pragma: no-cache");
        }
    }
}
