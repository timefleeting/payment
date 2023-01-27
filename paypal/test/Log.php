<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-31 13:55:22 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-06-05 16:18:31
 */
namespace Netflying\PaypalTest;
use Netflying\Payment\lib\LogInterface;

class Log implements LogInterface
{
    public function save($request,$response) 
    {
        $data = [
            'request' => $request,
            'response' => $response
        ];
        @file_put_contents(__DIR__.'/../../../../log/paypal.txt',json_encode($data));
    }
}