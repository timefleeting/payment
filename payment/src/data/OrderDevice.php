<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-24 11:05:25 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-06-13 00:35:25
 */

namespace Netflying\Payment\data;


/**
 *  订单第三方支付记录数据(第三方同步或异常信息)
 */

class OrderDevice extends Model
{
    protected $fields = [
        'language' => 'string',
        'screen_color_depth' => 'string',
        'screen_height' => 'string',
        'screen_width' => 'string',
        'timezone' => 'string',
        'java_enabled' => 'bool',
        'encrypt_data' => 'string',
        //3ds
        'threeds_id' => 'string',
        //authorized
        'authorized_token' => 'string',
    ];
    protected $fieldsNull = [
        'language' => '',
        'screen_color_depth' => '',
        'screen_height' => '',
        'screen_width' => '',
        'timezone' => '',
        'java_enabled' => true,
        'encrypt_data' => '',
        'threeds_id' => '',
        'authorized_token' => '',
    ];

}
