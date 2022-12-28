<?php
/**
 * FOSSBilling
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   Apache-2.0
 *
 * This file may contain code previously used in the BoxBilling project.
 * Copyright BoxBilling, Inc 2011-2021
 *
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE
 */
require_once __DIR__ . "/vendor/autoload.php";

class Payment_Adapter_Mollie_Adapter extends Payment_AdapterAbstract implements \Box\InjectionAwareInterface
{

    /**
 * @var Box_Di
 */
protected $di;

/**
 * @param Box_Di $di
 */
public function setDi($di)
{
    $this->di = $di;
}

/**
 * @return Box_Di
 */
public function getDi()
{
    return $this->di;
}

 public function __construct($config)
{
    $this->config = $config;
    if(!isset($this->config['api_key'])) {
        throw new Payment_Exception('Payment gateway "Mollie" is not configured properly. Please update configuration parameter "api_key" at "Configuration -> Payments".');
    }
    if(!isset($this->config['partner_id'])) {
        throw new Payment_Exception('Payment gateway "Mollie" is not configured properly. Please update configuration parameter "partner_id" at "Configuration -> Payments".');
    }
    if(!isset($this->config['profile_id'])) {
        throw new Payment_Exception('Payment gateway "Mollie" is not configured properly. Please update configuration parameter "profile_id" at "Configuration -> Payments".');
    }
}

public static function getConfig(){
    return array(
        'supports_one_time_payments'   =>  true,
        'supports_subscriptions'     =>  false,
        'description'     =>  'Process payments via Mollie',
        'form'  => array(
            'api_key' => array('text', array(
                        'label' => 'Api key',
                        'validators'=>array('text'),
                ),
            ),
            'partner_id' => array('text', array(
                        'label' => 'Partner ID',
                        'validators'=>array('text'),
                ),
            ),
            'profile_id' => array('text', array(
                        'label' => 'Profile ID',
                        'validators'=>array('text'),
                ),
            ),
        ),
    );
}

public function getHtml($api_admin, $invoice_id, $subscription)
{
    $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);
    $invoiceService = $this->di['mod_service']('Invoice');
    $api_key = $this->config['api_key'];
    $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
    $payGateway = $this->di['db']->findOne('PayGateway', 'gateway = "Mollie"');

    $mollie = new \Mollie\Api\MollieApiClient();
    $mollie->setApiKey($api_key);

    error_log(number_format($invoiceService->getTotalWithTax($invoiceModel),2));
    error_log($invoiceModel->currency);
    error_log( $this->getInvoiceTitle($invoiceModel));
    error_log($api_key);
    $payment = $mollie->payments->create([
        "amount" => [
            "currency" => $invoiceModel->currency,
            "value" => number_format($invoiceService->getTotalWithTax($invoiceModel),2)
        ],
        "description" => $this->getInvoiceTitle($invoiceModel),
        "redirectUrl" => $this->config['thankyou_url'],
        "webhookUrl"  => $this->config['notify_url']
    ]);

    $payment_id = $payment->id;



    return '<a href="'.$payment->getCheckoutUrl().'">Pay now!</a>';


}

public function getInvoiceTitle(\Model_Invoice $invoice)
{
    $invoiceItems = $this->di['db']->getAll('SELECT title from invoice_item WHERE invoice_id = :invoice_id', array(':invoice_id' => $invoice->id));

    $params = array(
        ':id'=>sprintf('%05s', $invoice->nr),
        ':serie'=>$invoice->serie,
        ':title'=>$invoiceItems[0]['title']);
    $title = __trans('Payment for invoice :serie:id [:title]', $params);
    if(count($invoiceItems) > 1) {
        $title = __trans('Payment for invoice :serie:id', $params);
    }
    return $title;
}


}
