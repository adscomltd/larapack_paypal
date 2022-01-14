<?php

namespace Adscom\LarapackPaypal;

use Adscom\LarapackPaymentManager\Contracts\OrderItem;
use Adscom\LarapackPaymentManager\Contracts\PaymentAccount;
use Arr;
use Adscom\LarapackPaymentManager\Drivers\PaymentDriver;
use Adscom\LarapackPaypal\Webhook\PaypalWebhookHandler;
use Adscom\LarapackPaymentManager\PaymentResponse;
use Exception;
use JsonException;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalHttp\HttpException;
use PayPalHttp\HttpResponse;
use Str;

class PaypalDriver extends PaymentDriver
{
  /**
   * API statuses on create payment intent
   */
  public const STATUS_CREATED = 'CREATED';

  protected PaypalWebhookHandler $webhookHandler;
  protected PaypalFinalizeHandler $finalizeHandler;

  public PayPalHttpClient $client;

  public function __construct()
  {
    parent::__construct();
    $this->webhookHandler = new PaypalWebhookHandler($this);
    $this->finalizeHandler = new PayPalFinalizeHandler($this);
  }

  public function setup(PaymentAccount $paymentAccount): void
  {
    parent::setup($paymentAccount);

    $clientId = $this->config['client_id'];
    $clientSecret = $this->config['secret'];

    if (app()->isLocal()) {
      $environment = new SandboxEnvironment($clientId, $clientSecret);
    } else {
      $environment = new ProductionEnvironment($clientId, $clientSecret);
    }

    $this->client = new PayPalHttpClient($environment);
  }

  public function processPayment(array $data = []): void
  {
    $request = new OrdersCreateRequest();
    $request->prefer('return=representation');
//    $request->headers['PayPal-Mock-Response'] = '{"mock_application_codes": "PAYEE_ACCOUNT_INVALID"}';
    $request->body = $this->prepareData($data);

    $response = $this->client->execute($request);

    $this->handleResponse($response);
    $status = $this->getPaymentStatus($response->result->status);
    $this->paymentResponse->setPaidAmount($response->result->purchase_units[0]->amount->value);
    $this->paymentResponse->setStatus($status);
  }

  protected function getPaymentStatus(string $status): int
  {
    // TODO: add other cases
    return match ($status) {
      self::STATUS_CREATED => self::getPaymentContractClass()::getCreatedStatus(),
      default => self::getPaymentContractClass()::getErrorStatus(),
    };
  }

  protected function prepareData(array $data): array
  {
    $preparedData = [
      'intent' => 'AUTHORIZE',
      'application_context' =>
        [
          'cancel_url' => $this->getFinalizeUrl(['status' => PaypalFinalizeHandler::STATUS_CANCELLED]),
          'return_url' => $this->getFinalizeUrl(['status' => PaypalFinalizeHandler::STATUS_SUCCEEDED]),
          'shipping_preference' => 'SET_PROVIDED_ADDRESS',
        ],
      'purchase_units' =>
        [
          [
            'reference_id' => $this->order->getUuid(),
            //TODO: change description
            'description' => 'M4trix Market',
            'custom_id' => $this->paymentResponse->getUuid(),
            'amount' =>
              [
                'currency_code' => $this->order->getProcessorCurrency(),
                'value' => $this->order->getDueAmount(),
                'breakdown' =>
                  [
                    'item_total' =>
                      [
                        'currency_code' => $this->order->getProcessorCurrency(),
                        'value' => $this->order->getDueAmountWithoutShipping(),
                      ],
                  ],
              ],
            'items' => $this->order->getLineItems()->map(
              fn(OrderItem $item) => [
                'name' => $item->getName(),
                'description' => $item->getName(),
                'sku' => $item->getSKU(),
                'unit_amount' =>
                  [
                    'currency_code' => $this->order->getProcessorCurrency(),
                    'value' => $item->getPrice(),
                  ],
                'quantity' => $item->getQuantity(),
                'category' => 'PHYSICAL_GOODS',
              ])->toArray(),
            'shipping' =>
              [
                'address' =>
                  [
                    'address_line_1' => $this->order->getAddress()->getAddressLine1(),
                    'address_line_2' => $this->order->getAddress()->getAddressLine2(),
                    'admin_area_2' => $this->order->getAddress()->getCity(),
                    'admin_area_1' => $this->order->getAddress()->getState(),
                    'postal_code' => $this->order->getAddress()->getZipCode(),
                    'country_code' => $this->order->getAddress()->getCountryISO(),
                  ],
              ],
          ],
        ],
    ];

    if ($this->order->hasShippingData()) {
      Arr::set($preparedData, 'purchase_units.0.shipping.method', $this->order->getShippingName());
      Arr::set($preparedData, 'purchase_units.0.amount.breakdown.shipping', [
        'currency_code' => $this->order->getProcessorCurrency(),
        'value' => $this->order->getShippingCost(),
      ]);
    }

    return $preparedData;
  }

  /**
   * @throws JsonException
   */
  public function handleResponse($response): PaymentResponse
  {
    /** @var HttpResponse $paypalResponse */
    $paypalResponse = $response;

    $this->paymentResponse->setResponse(
      json_decode(json_encode($paypalResponse->result, JSON_THROW_ON_ERROR),
        true, 512, JSON_THROW_ON_ERROR)
    );
    $this->paymentResponse->setProcessorCurrency($paypalResponse->result->purchase_units[0]->amount->currency_code);
    $this->paymentResponse->setProcessorStatus($paypalResponse->result->status);
    $this->paymentResponse->setProcessorTransactionId($paypalResponse->result->id);
    $this->paymentResponse->setPaymentTokenId(null);

    return $this->paymentResponse;
  }

  /**
   * @throws JsonException
   */
  public function handleException(Exception $e): void
  {
    if ($e instanceof HttpException) {
      $oldUuid = $this->paymentResponse->getUuid();
      $this->paymentResponse = $this->getPaymentResponseFromHttpException($e);
      $this->paymentResponse->setUuid($oldUuid);
    } else {
      parent::handleException($e);
    }
  }

  /**
   * @throws JsonException
   */
  public function getPaymentResponseFromHttpException(HttpException $e): PaymentResponse
  {
    $paymentResponse = new PaymentResponse();
    $body = json_decode($e->getMessage(), true, 512, JSON_THROW_ON_ERROR);

    $paymentResponse->setUuid(Str::uuid()->toString());
    $paymentResponse->setResponse($body);
    $paymentResponse->setProcessorCurrency($this->order->getProcessorCurrency());
    $paymentResponse->setProcessorStatus($body['name']);
    $paymentResponse->setReason(Arr::get($body, 'details.0.issue'));
    $paymentResponse->setNotes($body['details']);
    $paymentResponse->setProcessorTransactionId(null);
    $paymentResponse->setPaymentTokenId(null);
    $paymentResponse->setStatus(self::getPaymentContractClass()::getErrorStatus());

    return $paymentResponse;
  }
}
