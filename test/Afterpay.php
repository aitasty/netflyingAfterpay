<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-31 13:55:07 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-06-06 22:45:47
 */

namespace Netflying\AfterpayTest;

use Netflying\Payment\common\Utils;
use Netflying\PaymentTest\Data;

use Netflying\Afterpay\data\Merchant;

class Afterpay
{

    protected $url = '';

    public $type = 'Afterpay';

    protected $merchant = [];


    /**
     * @param $url 回调通知等相对路径
     *
     * @param string $url 站点回调通知相对路径
     */
    public function __construct($url = '')
    {
        $this->url = $url;
    }

    /**
     * 商家数据结构
     *
     * @return this
     */
    public function setMerchant(array $realMerchant = [])
    {
        $url = $this->url . '?type=' . $this->type;
        $returnUrl = $url . '&act=return_url&async=0&sn={$sn}';
        $cancelUrl = $url . '&act=cancel_url&async=0&sn={$sn}';
        /**
         * test: https://api.us-sandbox.afterpay.com   https://global-api-sandbox.afterpay.com
         * live: https://api.us.afterpay.com   https://global-api.afterpay.com
         */
        $merchant = [
            'type' => $this->type,
            'is_test' => 1,
            'merchant' => '****',
            'api_account' => [
                'merchant_id' => '*****',
                'secret_key' => '*****',
            ],
            'api_data' => [
                'endpoint_domain' => 'https://api.us-sandbox.afterpay.com',
                'endpoint'   => '/v2/checkouts',
                'capture_url' => '/v2/payments/capture',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ]
        ];
        $merchant = Utils::arrayMerge($merchant, $realMerchant);
        $this->merchant = $merchant;
        return $this;
    }

    /**
     * 提交支付
     *
     * @return Redirect
     */
    public function pay()
    {
        $Data = new Data;
        $Order = $Data->order();
        $Log = new Log;
        $Merchant = new Merchant($this->merchant);
        $class = "Netflying\\" . $this->type . "\\lib\\" . $this->type;
        $Payment = new $class($Merchant);
        $redirect = $Payment->log($Log)->purchase($Order);
        return $redirect;
    }
}
