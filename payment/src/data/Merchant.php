<?php
/*
 * @Author: He bin 
 * @Date: 2022-01-26 15:15:22 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2023-01-12 16:24:26
 */

namespace Netflying\Payment\data;

/**
 * 支付通道基础数据结构
 */
class Merchant extends Model
{
    protected $fields = [
        //支付唯一标识
        'id' => 'int',
        //支付通道类型,类包，标识，名称等,对应通道包类名
        'type' => 'string',
        //支付渠道品牌名,或旗下具体品牌名,唯一标识
        'type_id' => 'string',
        //支付渠道类包类,默认与type一致
        'type_class' => 'string',
        //收款帐号
        'merchant' => 'string',
        //是否测试环境
        'is_test'  => 'bool',
        //是否必须帐单信息
        'is_billing' => 'bool',
        //是否必须信息卡信息
        'is_credit' => 'bool',
        //是否必须电话号码
        'is_phone' => 'bool',
        //是否作为默认选中
        'is_default' => 'bool',
        //是否隐藏
        'is_hide' => 'bool',
        //api帐号信息
        'api_account' => 'array',
        //api接口信息
        'api_data' => 'array',
        //国家及金额限制
        'country_amount' => 'array',
        //货币及金额限制
        'currency_amount' => 'array',
        //名称图标描述等
        'type_info' => 'array',
        //回调通知路由地址, https://xxx.com/pay/xxx
        'call_route' => 'string',
        'call_url' => 'array',
        //语言包配置
        'i18n' => 'array',
        'throw_ids' => 'string',
    ];
    protected $fieldsNull = [
        'id' => 0,
        'is_test' => 0,
        'type' => null,
        'type_id' => null,
        'type_class' => '', //实例后自动填充
        'merchant' => null,
        'is_billing' => 0,
        'is_phone' => 0,
        'is_credit' => 0,
        'is_default' => 0,
        'is_hide' => 0,
        'api_account' => [],
        'api_data' => [],
        'country_amount' => [],
        'currency_amount' => [],
        'type_info' => [],
        'call_route' => null,
        'call_url' => [],
        'i18n' => [],
        'throw_ids' => ''
    ];

    protected $apiAccount = [];

    protected $apiData = [];
    
    protected $apiDataNull = [];

    protected $callUrl = [
        //支付返回地址。一般作为成功返回,失败返回会另带参数字段
        'return_url' => 'string',
        //支付取消返回
        'cancel_url' => 'string',
        //完全成功返回 *
        'success_url' => 'string',
        //完全失败 *
        'failure_url' => 'string',
        //处理中
        'pending_url' => 'string',
        //回调通知地址
        'notify_url' => 'string',
        //授权返回
        'authorise_renturn_url' => 'string',
        //授权取消返回
        'authorise_cancel_url' => 'string',
        //3ds完成后处理地址
        'threeds_url' => 'string'
    ];

    protected $callUrlNull = [
        'return_url' => '',
        'success_url' => '',
        'failure_url' => '',
        'pending_url' => '',
        'cancel_url' => '',
        'notify_url' => '',
        'authorise_renturn_url' => '',
        'authorise_cancel_url' => '',
        'threeds_url' => '',
    ];

    //国家及金额
    protected $countryAmount = [
        '0' => 'object'
    ];
    protected $countryAmountNull = [
        '0' => null
    ];
    //货币及金额
    protected $currencyAmount = [
        '0' => 'object'
    ];
    protected $currencyAmountNull = [
        '0' => null
    ];
    //客户端名称图标描述等
    protected $typeInfo = [
        //名称
        'title' => 'string',
        //小图标
        'icon' => 'string',
        //大图标
        'logo' => 'string',
        //商标
        'trand_mark' => 'string',
        //支付渠道说明描述
        'descript' => 'string',
        //token公钥等
        'type_token' => 'array',
    ];
    protected $typeInfoNull = [
        'title' => null,
        'icon' => '',
        'logo' => '',
        'trand_mark' => '',
        'descript' => '',
        'type_token' => []
    ];
    protected $typeToken = [
        'key' => 'string',
        //3ds 设备请求收集地址,前端sdk需要
        'threeds_url' => 'string',
        //3ds 收集额外参数
        'threeds_params' => 'array',
    ];
    protected $typeTokenNull = [
        'key' => '',
        'threeds_url' => '',
        'threeds_params' => []
    ];

    protected $countryAmount0 = "Netflying\Payment\data\MerchantCountryAmount";
    protected $currencyAmount0 = "Netflying\Payment\data\MerchantCurrencyAmount";

}
