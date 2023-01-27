<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-24 11:05:25 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2023-01-11 20:30:30
 */

namespace Netflying\Payment\data;

use Netflying\Payment\common\Openssl;
/**
 *  信用卡数据
 *
 */

class CreditCardSsl extends Model
{
    protected $fields = [
        'encrypt' => 'string'
    ];
    protected $fieldsNull = [
        'encrypt'    => null,
    ];

    /**
     * CreditCardSsl 转 CreditCard
     * @return CreditCard
     */
    public function creditCard()
    {
        $encrypt = $this->getEncrypt();
        $encryptObj = Openssl::decrypt($encrypt);
        if (!empty($encryptObj)) {
            return new CreditCard($encryptObj);
        }
        return new CreditCard([], 2);
    }

}
