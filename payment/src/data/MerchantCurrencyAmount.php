<?php

namespace Netflying\Payment\data;


/**
 *  订单商品数据结构
 */

class MerchantCurrencyAmount extends Model
{
    protected $fields = [
        //3位货币码
        'currency_code' => 'string',
        'min_amount' => 'int',
        'max_amount' => 'int'
    ];
    protected $fieldsNull = [
        'currency_code' => null,
        'min_amount' => null,
        'max_amount' => null
    ];
}
