<?php

namespace Netflying\Payment\data;


/**
 *  订单商品数据结构
 */

class MerchantCountryAmount extends Model
{
    protected $fields = [
        //两位国家码
        'country_code' => 'string',
        'min_amount' => 'int',
        'max_amount' => 'int'
    ];
    protected $fieldsNull = [
        'country_code' => null,
        'min_amount' => null,
        'max_amount' => null
    ];
}
