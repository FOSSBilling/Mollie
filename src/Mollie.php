<?php
/**
 * Copyright 2023 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

require_once __DIR__ . "/vendor/autoload.php";

use \Mollie\Api\MollieApiClient;

class Payment_Adapter_Mollie extends Payment_AdapterAbstract implements \Box\InjectionAwareInterface
{
    protected array $config = [];
    protected MollieApiClient $mollie;
    protected $di;

    public function setDi($di)
    {
        $this->di = $di;
    }

    public function getDi()
    {
        return $this->di;
    }

    public function __construct(array $config)
    {
        $this->config = $config;
        $requiredParameters = ['api_key', 'partner_id', 'profile_id'];

        foreach ($requiredParameters as $requiredParameter) {
            if (empty($this->config[$requiredParameter])) {
                throw new Payment_Exception('Payment gateway "Mollie" is not configured properly. Please update the configuration parameter :param at "Configuration -> Payments".', [':param' => $requiredParameter]);
            }
        }

        $api_key = $this->config['test_mode'] ? $this->get_test_api_key() : $this->config['api_key'];

        $this->mollie = new MollieApiClient();
        $this->mollie->setApiKey($api_key);
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Process payments via Mollie',
            'logo' => [
                'logo' => '/Mollie/Mollie.png',
                'height' => '50px',
                'width' => '50px',
            ],
            'form' => [
                'test_api_key' => [
                    'text',
                    [
                        'label' => 'Test API key',
                        'validators' => ['text'],
                    ],
                ],
                'api_key' => [
                    'text',
                    [
                        'label' => 'API key',
                        'validators' => ['text'],
                    ],
                ],
                'partner_id' => [
                    'text',
                    [
                        'label' => 'Partner ID',
                        'validators' => ['text'],
                    ],
                ],
                'profile_id' => [
                    'text',
                    [
                        'label' => 'Profile ID',
                        'validators' => ['text'],
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoice = $this->di['db']->load('Invoice', $invoice_id);

        return $this->_generateForm($invoice);
    }

    protected function _generateForm(Model_Invoice $invoice): string
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $payGateway = $this->di['db']->findOne('PayGateway', 'gateway = "Mollie"');

        $uniqid = bin2hex(random_bytes(10));
        $payment = $this->mollie->payments->create([
            "amount" => [
                "currency" => $invoice->currency,
                "value" => number_format($invoiceService->getTotalWithTax($invoice), 2)
            ],
            "description" => $this->getInvoiceTitle($invoice),
            "redirectUrl" => $this->config['thankyou_url'],
            "webhookUrl" => $this->config['notify_url'] . '&transid=' . $uniqid
        ]);

        $service = $this->di['mod_service']('invoice', 'transaction');

        // create a new transaction so we can reuse the payment ID in the next step
        $output = $service->create(array('txn_id' => $payment->id, 'bb_invoice_id' => $invoice->id, 'bb_gateway_id' => $payGateway->id));

        // We still need to update the unique ID
        $tx = $this->di['db']->getExistingModelById('Transaction', $output);
        $tx->txn_status = 'pending';
        $tx->amount = $invoiceService->getTotalWithTax($invoice);
        $tx->currency = $invoice->currency;
        $tx->s_id = $uniqid;
        $this->di['db']->store($tx);

        return '<script type="text/javascript">window.location = "'. $payment->getCheckoutUrl() . '";</script>';
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $transid = $this->di['db']->getCell('SELECT id from transaction WHERE s_id = :s_id', array(':s_id' => $data['get']['transid']));
        $tx = $this->di['db']->getExistingModelById('Transaction', $transid);
        $invoice = $this->di['db']->getExistingModelById('Invoice', $tx->invoice_id);
        $invoiceService = $this->di['mod_service']('Invoice');

        $payment = $this->mollie->payments->get($tx->txn_id);

        if ($tx->status == 'processed') {
            throw new Payment_Exception("Transaction is already processed");
        }

        if ($payment->isPaid()) {
            $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
            $clientService = $this->di['mod_service']('client');
            
            $tx->txn_status = 'approved';
            $tx->status = 'processed';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            
            $clientService->addFunds($client, $tx->amount, 'Payment with Mollie', array('status' => 'approved', 'invoice' => $tx->invoice_id));
            
            if ($tx->invoice_id) {
                $invoiceService->payInvoiceWithCredits($invoice);
            }

            $invoiceService->doBatchPayWithCredits(array('client_id' => $client->id));
        } else {
            echo "Unable to process transaction";
        }
    }

    public function getInvoiceTitle(\Model_Invoice $invoice): string
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

    public function get_test_api_key(): string
    {
        if (!isset($this->config['test_api_key'])) {
            throw new Payment_Exception('Payment gateway "Mollie" is not configured properly. Please update configuration parameter "test_api_key" at "Configuration -> Payments".');
        }
        return $this->config['test_api_key'];
    }
}