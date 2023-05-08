<?php
/**
 * Copyright 2023 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

require_once __DIR__ . "/vendor/autoload.php";

class Payment_Adapter_Mollie extends Payment_AdapterAbstract implements \Box\InjectionAwareInterface
{
    protected $di;

    public function setDi($di)
    {
        $this->di = $di;
    }

    public function getDi()
    {
        return $this->di;
    }

    public function __construct($config)
    {
        $this->config = $config;
        $requiredParameters = ['api_key', 'partner_id', 'profile_id'];

        foreach ($requiredParameters as $requiredParameter) {
            if (empty($this->config[$requiredParameter])) {
                throw new Payment_Exception('Payment gateway "Mollie" is not configured properly. Please update the configuration parameter "' . $requiredParameter . '" at "Configuration -> Payments".');
            }
        }
    }

    public static function getConfig()
    {
        return array(
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Process payments via Mollie',
            'logo' => array(
                'logo' => '/Mollie/Mollie.png',
                'height' => '50px;',
                'width' => '50px',
            ),
            'form' => array(
                'api_key' => array(
                    'text',
                    array(
                        'label' => 'Api key',
                        'validators' => array('text'),
                    ),
                ),
                'partner_id' => array(
                    'text',
                    array(
                        'label' => 'Partner ID',
                        'validators' => array('text'),
                    ),
                ),
                'profile_id' => array(
                    'text',
                    array(
                        'label' => 'Profile ID',
                        'validators' => array('text'),
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

        $uniqid = bin2hex(random_bytes(10));
        $payment = $mollie->payments->create([
            "amount" => [
                "currency" => $invoiceModel->currency,
                "value" => number_format($invoiceService->getTotalWithTax($invoiceModel), 2)
            ],
            "description" => $this->getInvoiceTitle($invoiceModel),
            "redirectUrl" => $this->config['thankyou_url'],
            "webhookUrl" => $this->config['notify_url'] . '&transid=' . $uniqid
        ]);
        $payment_id = $payment->id;

        $service = $this->di['mod_service']('invoice', 'transaction');
        // create a new transaction so we can reuse the payment id in the next step
        $output = $service->create(array('txn_id' => $payment->id, 'bb_invoice_id' => $invoice_id, 'bb_gateway_id' => $payGateway->id));

        // We still need to update the unique id 
        $tx = $this->di['db']->getExistingModelById('Transaction', $output);
        $tx->txn_status = 'pending';
        $tx->amount = $invoiceService->getTotalWithTax($invoiceModel);
        $tx->currency = $invoiceModel->currency;
        $tx->s_id = $uniqid;
        $this->di['db']->store($tx);

        return '<a href="' . $payment->getCheckoutUrl() . '">Pay now!</a>';
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $api_key = $this->config['api_key'];
        $transid = $this->di['db']->getCell('SELECT id from transaction WHERE s_id = :s_id', array(':s_id' => $data['get']['transid']));
        $tx = $this->di['db']->getExistingModelById('Transaction', $transid);
        $invoice = $this->di['db']->getExistingModelById('Invoice', $tx->invoice_id);
        $invoiceService = $this->di['mod_service']('Invoice');

        $mollie = new \Mollie\Api\MollieApiClient();
        $mollie->setApiKey($api_key);
        $payment = $mollie->payments->get($tx->txn_id);

        if ($tx->status == 'processed') {
            throw new \Exception("Transaction is allready processed");
        }
        if ($payment->isPaid()) {
            $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
            $clientService = $this->di['mod_service']('client');
            $tx->txn_status = 'appproved';
            $tx->status = 'processed';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            $clientService->addFunds($client, $tx->amount, 'Payment with Mollie', array('status' => 'appproved', 'invoice' => $tx->invoice_id));
            if ($tx->invoice_id) {
                $invoiceService->payInvoiceWithCredits($invoice);
            }
            $invoiceService->doBatchPayWithCredits(array('client_id' => $client->id));

        } else {
            echo "Unable to process transaction";
        }
    }

    public function getInvoiceTitle(\Model_Invoice $invoice)
    {
        $invoiceItems = $this->di['db']->getAll('SELECT title from invoice_item WHERE invoice_id = :invoice_id', array(':invoice_id' => $invoice->id));

        $params = array(
            ':id' => sprintf('%05s', $invoice->nr),
            ':serie' => $invoice->serie,
            ':title' => $invoiceItems[0]['title']
        );
        $title = __trans('Payment for invoice :serie:id [:title]', $params);
        if (count($invoiceItems) > 1) {
            $title = __trans('Payment for invoice :serie:id', $params);
        }
        return $title;
    }


}