<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-27 11:37:19 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-09-27 10:46:24
 * 
 * 日志支持,支付请求接口类
 */

namespace Netflying\Payment\lib;

use Netflying\Payment\common\Request as Rq;
use Netflying\Payment\data\Merchant;
use Netflying\Payment\data\RequestCreate;
use Netflying\Payment\data\Response;

class Request
{
    /**
     * curl请求接口
     *
     * @param RequestCreateData $params
     * @return Response
     */
    public static function create(RequestCreate $Params)
    {
        $method = "create";
        if ($Params['type']=='api') {
            $method = "apiCreate";
        }
        return Rq::$method($Params, function (RequestCreate $request, Response $response) use ($Params) {
            $logClass = $Params->getLog();
            if (is_object($logClass)) {
                call_user_func_array([$logClass, 'save'], [$request, $response]);
            }
        });
    }

}
