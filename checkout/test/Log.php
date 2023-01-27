<?php

namespace Netflying\CheckoutTest;
use Netflying\Payment\lib\LogInterface;

class Log implements LogInterface
{
    public function save($request,$response) 
    {
        $data = [
            'request' => $request,
            'response' => $response
        ];
        @file_put_contents(__DIR__.'/../../../../log/checkout.txt',json_encode($data));
    }
}