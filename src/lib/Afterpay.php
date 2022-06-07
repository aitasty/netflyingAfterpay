<?php

namespace Netflying\Klarna\lib;

use Exception;

use Netflying\Payment\common\Utils;
use Netflying\Payment\common\Request as Rt;
use Netflying\Payment\lib\PayInterface;
use Netflying\Payment\lib\Request;

use Netflying\Payment\data\Merchant;
use Netflying\Payment\data\Order;
use Netflying\Payment\data\Redirect;
use Netflying\Payment\data\OrderPayment;
use Netflying\Payment\data\RequestCreate;


class Klarna extends PayInterface
{
    protected $merchant = null;
    //日志对象
    protected $log = '';

    public function __construct(Merchant $Merchant, $log = '')
    {
        $this->merchant($Merchant);
        $this->log($log);
    }
    /**
     * 初始化商户
     * @param Merchant $Merchant
     * @return self
     */
    public function merchant(Merchant $Merchant)
    {
        $this->merchant = $Merchant;
        return $this;
    }
    public function merchantUrl(Order $order)
    {
        $sn = $order['sn'];
        $merchant = $this->merchant;
        $apiData = $merchant['api_data'];
        $sn = $order['sn'];
        $urlReplace = function ($val) use ($sn) {
            return str_replace('{$sn}', $sn, $val);
        };
        $urlData = Utils::modeData([
            'return_url' => '',
            'cancel_url' => '',
        ], $apiData, [
            'return_url' => $urlReplace,
            'cancel_url' => $urlReplace,
        ]);
        $apiData = array_merge($apiData, $urlData);
        $merchant->setApiData($apiData);
        $this->merchant = $merchant;
        return $this;
    }
    /**
     * 日志对象
     */
    public function log($Log = '')
    {
        $this->log = $Log;
        return $this;
    }

    public function purchase(Order $Order): Redirect
    {
        $this->merchantUrl($Order);
        $apiData = $this->merchant['api_data'];
        $url = $apiData['endpoint_domain'] . $apiData['endpoint'];
        $orderData = $this->orderData($Order);
        $res = $this->request($url, $orderData);
        if ($res['code'] != '200') {
            return $this->errorRedirect();
        }
        $rs = Utils::mapData([
            'url' => '',
        ], (json_decode($res['body'], true)), [
            'url' => 'redirectCheckoutUrl'
        ]);
        return $this->toRedirect($rs['url']);
    }


    /**
     * 捕获订单所有金额.当跳回return_url时
     *
     * @param string $token 请求返回的 orderToken 参数
     * @param string $merchantSn 商户订单号
     * @return void
     */
    public function captureFull($token, $merchantSn)
    {
        $apiData = $this->merchant['api_data'];
        $url = $apiData['endpoint_domain'] . $apiData['capture_url'];
        $res = $this->request($url, [
            'token' => $token,
            'merchantReference' => $merchantSn
        ]);
        if ($res['code'] != '200') {
            throw new \Exception('response error', $res['code']);
        }
        $resData = Utils::mapData([
            'error_code' => 0,
            'error_msg' => '',
            'status_descrip' => '', //状态: DECLINED拒绝, APPROVED通过
            'events' => [],
            'sn' => $merchantSn,
            'type_method' => '',
            'pay_id' => '',
            'pay_sn' => '',
            'originalAmount' => [
                'currency' => '',
                'amount' => 0
            ],
            'paymentState' => ''
        ], (json_decode($res['body'], true)), [
            //异常
            'error_code' => 'errorCode',
            'error_msg' => 'Description',
            'status_descrip' => 'status',
            'pay_id' => 'id',
            'pay_sn' => 'id,'
        ]);
        if (!empty($resData['error_code'])) {
            throw new \Exception($resData['error_code'] . ':' . $resData['error_msg']);
        }
        $status = 0;
        $lastEvent = end($resData['events']);
        if ($resData['status_descrip'] == 'APPROVED' && $lastEvent['type'] == 'CAPTURED') {
            $status = 1;
        }
        $resData['currency'] = $resData['originalAmount']['currency'];
        $resData['amount'] = $resData['originalAmount']['amount'];
        if (!empty($resData['paymentState'])) {
            $resData['status_descrip'] = $resData['paymentState'];
        }
        $resData['merchant'] = $this->merchant['merchant'];
        $resData['type'] = $this->merchant['type'];
        $resData['status'] = $status;
        return new OrderPayment($resData);
    }
    /**
     * 获取订单详情
     *
     * @param string $id 提交时成功返回的order_id
     * @param string $idempotencyKey 头部 HTTP_KLARNA_IDEMPOTENCY_KEY
     * @return void
     */
    public function orders($id, $idempotencyKey)
    {
        $apiData = $this->merchant['api_data'];
        $url = str_replace('{$id}', $id, $apiData['orders_url']);
        $url = $apiData['endpoint_domain'] . $url;
        $header = [];
        if (!empty($idempotencyKey)) {
            $header['Klarna-Idempotency-Key'] = $idempotencyKey;
        }
        $res = $this->request($url, "", $header);
        if ($res['code'] != '200') {
            return [];
        }
        return !empty($res['body']) ? json_decode($res['body'], true) : [];
    }

    /**
     * 订单数据结构
     */
    protected function orderData(Order $Order)
    {
        $apiData = $this->merchant['api_data'];
        $data = Utils::mapData([
            'amount'   => [
                'amount' => Utils::caldiv($Order['purchase_amount'], 100),
                'currency' => $Order['currency']
            ],
            'consumer' => $this->consumerData($Order),
            'billing'  => $this->billingData($Order),
            'shipping' => $this->shippingData($Order),
            'merchant' => [
                'redirectConfirmUrl' => $apiData['return_url'],
                'redirectCancelUrl' => $apiData['cancel_url'],
            ],
            'description' => !empty($Order['descript']) ? $Order['descript'] : $Order['sn'],
            'items' => $this->orderItemsData($Order),
            'shippingAmount' => [
                'amount' => Utils::caldiv($Order['freight'], 100),
                'currency' => $Order['currency']
            ],
            'merchantReference' => $Order['sn']
        ], []);
        return $data;
    }
    /**
     * 订单商品数据
     *
     * @param Order $Order
     * @return array
     */
    protected function orderItemsData(Order $Order)
    {
        $items = [];
        foreach ($Order['products'] as $k => $v) {
            $line = Utils::mapData([
                'name'      => '',
                'quantity'     => 1,
                'price' => [
                    'amount' => $v['total_amount'],
                    'currency' => $Order['currency']
                ]
            ], $v);
            $items[] = $line;
        }
        return $items;
    }
    /**
     * 用户信息
     *
     * @param Order $Order
     * @return array
     */
    protected function consumerData(Order $Order)
    {
        $billingData = $this->billingData($Order);
        return [
            'giverNames' => $billingData['name'],
            'surname' => $billingData['surname'],
            'email' => $billingData['email']
        ];
    }
    /**
     * 快递地址信息
     *
     * @param Order $Order
     * @return array
     */
    protected function shippingData(Order $Order)
    {
        return $this->addressData($Order, 'shipping');
    }
    /**
     * 帐单地址信息
     *
     * @param Order $Order
     * @return array
     */
    protected function billingData(Order $Order)
    {
        return $this->addressData($Order, 'billing');
    }
    /**
     * 地址数据模型
     *
     * @param Order $Order
     * @param string $type [shipping,billing]
     * @return void
     */
    protected function addressData(Order $Order, $type)
    {
        $address = $Order['address'][$type];
        $data = Utils::mapData([
            'name'      => '',
            'surname'     => '',
            'email'           => '',
            'countryCode'         => '',
            'region'          => '',
            'area1'            => '',
            'postcode'     => '',
            'line1'  => '',
        ], $address, [
            'name' => 'first_name',
            'surname' => 'last_name',
            'countryCode' => 'country_code',
            'area1' => 'city',
            'postcode' => 'postal_code',
            'line1' => 'street_address',
        ]);
        return $data;
    }

    /**
     * 错误请求结果
     *
     * @return Redirect
     */
    protected function errorRedirect()
    {
        return new Redirect([
            'status' => 0,
            'url' => '',
            'type' => 'get',
            'params' => [],
            'exception' => []
        ]);
    }
    protected function toRedirect($url, $data = [], $type = 'get')
    {
        return new Redirect([
            'status' => 1,
            'url' => $url,
            'type' => $type,
            'params' => $data,
            'exception' => []
        ]);
    }

    protected function authorizationBasic()
    {
        $apiAccount = $this->merchant['api_account'];
        return base64_encode($apiAccount['merchant_id'] . ':' . $apiAccount['secret_key']);
    }

    protected function request($url, $data = [], array $header = [])
    {
        //指定userAgent
        $apiAccount = $this->merchant['api_account'];
        $domain = Rt::domain();
        $phpVersion = defined('PHP_VERSION') ? PHP_VERSION : phpversion();
        $userAgent = 'AfterPayModule/1.0.0 (callie/1.0.0; PHP/' . $phpVersion . '; Merchant/' . $apiAccount['merchant_id'] . ' ' . $domain . ')';
        //请求headers
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . $this->authorizationBasic(),
            'region' => 'string',
            'Accept' => 'application/json',
        ];
        $headers = array_merge($headers, ['User-Agent' => strval($userAgent)], $header);
        $post = json_encode($data);
        $res = Request::create(new RequestCreate([
            'type' => 'post',
            'url' => $url,
            'headers' => $headers,
            'data' => $post,
            'log' => $this->log
        ]));
        return $res;
    }
}
