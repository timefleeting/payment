<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-24 11:05:25 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-06-23 16:42:50
 */

namespace Netflying\Payment\data;


/**
 *  重定向数据模型
 */

class Redirect extends Model
{
    protected $fields = [
        //1正常跳转,0异常报错不跳转
        'status' => 'int',
        'url' => 'string',
        //type: get,post
        'type' => 'string',
        'params' => 'array',
        'exception' => 'array'
    ];
    protected $fieldsNull = [
        'status' => null,
        'url' => null,
        'type' => null,
        'params' => [],
        'exception' => []
    ];

    protected $params = [];

    protected $exception = [
        'code' => 'int', //异常码 默认:0未知或正常。 其它:返回结果响应码,有可能是字符串
        'msg' => 'string', //异常信息。正常默认为空  响应回来的信息
        'httpcode' => 'int', //http响应码。 默认0正常或200系列正常响应
    ];

    protected $exceptionNull = [
        'code' => 0,
        'msg' => '',
        'httpcode' => 0
    ];

}
