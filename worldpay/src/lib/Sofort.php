<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-31 16:21:26 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-06-28 19:25:01
 */

namespace Netflying\Worldpay\lib;

use Netflying\Payment\common\Request;
use Netflying\Payment\data\Order;
use Netflying\Payment\data\Redirect;

/**
 * SOFORT Banking 是一种实时银行转账支付方式，奥地利、比利时、德国和瑞士的购物者可以使用这种方式将资金从他们的银行账户直接转账给商家。
 * http://support.worldpay.com/support/kb/gg/alternativepayments/content/sofortbanking.htm
 * 开发文档: https://developer.worldpay.com/docs/wpg/apms/sofortbanking
 */
class Sofort extends Worldpay
{
  protected $jsSdk = __DIR__ . '/../js/sofort.js';

  public function purchase(Order $Order): Redirect
  {
    $xml = $this->renderXml($Order);
    $apiData = $this->merchant->getApiData();
    $url = $apiData['endpoint'];
    $rs = $this->request($url, $xml);
    $result = $rs['body'];
    if ($rs['code'] != '200') {
      return $this->errorRedirect($rs['code'], 'request error');
    }
    $response = new Xml($result);
    //xml错误
    if ($errCode = $response->getErrorCode()) {
      $errMsg  = $response->getErrorDescription();
      return $this->errorRedirect($errCode, $errMsg);
    }
    $url = $response->getRedirectUrl();
    if (!$url) {
      return $this->errorRedirect(0, "Unable to get the redirecting url.");
    }
    return $this->toRedirect($url);
  }

  /**
   * xml主体报文
   * @param Order $Order
   * @return string
   */
  protected function renderXml(Order $Order)
  {
    $this->merchantCallUrl($Order);
    $merchant = $this->merchant;
    $callUrl = $merchant['call_url'];
    $merchantCode = $merchant['merchant'];
    $sn = $Order['sn'];
    $orderDescript = $Order['descript'];
    $orderDescript = !empty($orderDescript) ? $orderDescript : Request::domain();
    $desc = Xml::xmlStr($orderDescript); //订单描述,不可为空,可使用站点域名
    $currency = $Order['currency'];
    $amount = $Order['purchase_amount'];
    $orderContent = '';
    $address = $Order['address'];
    $shipping = $address['shipping'];
    $email = $shipping['email'];
    $countryCode = $shipping['country_code'];
    $statementNarrative = ''; //动态描述符, 可以使用站点名称等
    $successUrl = Xml::xmlStr($callUrl['success_url']);
    $cancelUrl = Xml::xmlStr($callUrl['cancel_url']);
    $pendingUrl = Xml::xmlStr($callUrl['pending_url']);
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE paymentService PUBLIC "-//WorldPay//DTD WorldPay PaymentService v1//EN" "http://dtd.worldpay.com/paymentService_v1.dtd">
<paymentService merchantCode="{$merchantCode}" version="1.4">
  <submit>
    <order orderCode="{$sn}">
      <description>{$desc}</description>
      <amount exponent="2" currencyCode="{$currency}" value="{$amount}" />
      <orderContent>
        <![CDATA[{$orderContent}]]>
      </orderContent>
      <paymentDetails>
        <SOFORT-SSL shopperCountryCode="{$countryCode}">
            <successURL>{$successUrl}</successURL>
            <cancelURL>{$cancelUrl}</cancelURL>
            <pendingURL>{$pendingUrl}</pendingURL>
        </SOFORT-SSL>
      </paymentDetails>
      <shopper>
        <shopperEmailAddress>{$email}</shopperEmailAddress>
      </shopper>
      <statementNarrative>{$statementNarrative}</statementNarrative>
    </order>
  </submit>
</paymentService>
XML;
    return $xml;
  }


}
