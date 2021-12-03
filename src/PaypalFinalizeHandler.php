<?php

namespace Adscom\LarapackPaypal;

use App\Models\Payment;
use Adscom\LarapackPaymentManager\Interfaces\IFinalizeHandler;
use Adscom\LarapackPaymentManager\PaymentResponse;
use Illuminate\Support\Str;
use JsonException;
use PayPalCheckoutSdk\Orders\OrdersAuthorizeRequest;
use PayPalCheckoutSdk\Payments\AuthorizationsCaptureRequest;
use PayPalHttp\HttpException;
use PayPalHttp\HttpResponse;

class PaypalFinalizeHandler implements IFinalizeHandler
{
  /**
   * Finalize supported statuses
   */
  public const STATUS_CANCELLED = 'canceled';
  public const STATUS_SUCCEEDED = 'succeeded';
  public const STATUS_APPROVED = 'approved';

  public const SUPPORTED_STATUSES = [
    self::STATUS_CANCELLED,
    self::STATUS_SUCCEEDED,
    self::STATUS_APPROVED
  ];

  public function __construct(protected PaypalDriver $driver)
  {
  }

  public function process(array $data): array
  {
    $status = $data['status'];

    if (!in_array($status, self::SUPPORTED_STATUSES, true)) {
      return [];
    }

    return $this->processStatus($status, $data);
  }

  protected function processStatus(string $status, array $data): array
  {
    $funcName = 'handle'.Str::of($status)->replace('.', '_')->studly().'Status';

    if (!method_exists($this, $funcName)) {
      return [];
    }

    return $this->$funcName($data);
  }

  protected function handleSuccessStatus(array $data): array
  {
    return [];
  }

  /**
   * @throws JsonException
   */
  protected function handleApprovedStatus(array $data): array
  {
    try {
      $authId = $this->authorizeOrder($this->driver->payment->processor_transaction_id);
      $paymentResponse = $this->captureAuthorization($authId);
    } catch (HttpException $e) {
      $paymentResponse = $this->getPaymentResponseFromHttpException($e);
      $this->driver->createPaymentFromResponse($paymentResponse);
    }

    return $paymentResponse->getFrontendData();
  }

  /**
   * @throws JsonException
   * @throws HttpException
   */
  protected function authorizeOrder(string $orderId): string
  {
    $request = new OrdersAuthorizeRequest($orderId);
    $request->prefer('return=representation');
    $response = $this->driver->client->execute($request);

    $paymentResponse = $this->getPaymentResponse($response);
    $paymentResponse->setPaidAmount($response->result->purchase_units[0]->amount->value);
    $paymentResponse->setProcessorTransactionId($response->result->purchase_units[0]->payments->authorizations[0]->id);

    $status = $response->result->status === 'COMPLETED' ? Payment::STATUS_INITIATED : Payment::STATUS_ERROR;
    $paymentResponse->setStatus($status);

    $this->driver->createPaymentFromResponse($paymentResponse);

    return $response->result->purchase_units[0]->payments->authorizations[0]->id;
  }

  /**
   * @throws JsonException
   * @throws HttpException
   */
  protected function captureAuthorization(string $authId): PaymentResponse
  {
    $request = new AuthorizationsCaptureRequest($authId);
//    $request->headers['PayPal-Mock-Response'] = '{"mock_application_codes": "PAYER_CANNOT_PAY"}';
    $request->prefer('return=representation');
    $response = $this->driver->client->execute($request);

    $paymentResponse = $this->getPaymentResponse($response);
    $paymentResponse->setPaidAmount($response->result->amount->value);

    $status = $response->result->status === 'COMPLETED' ? Payment::STATUS_PAID : Payment::STATUS_ERROR;
    $paymentResponse->setStatus($status);

    $this->driver->createPaymentFromResponse($paymentResponse);

    return $paymentResponse;
  }

  /**
   * @throws JsonException
   */
  public function getPaymentResponse(HttpResponse $paypalResponse): PaymentResponse
  {
    $paymentResponse = new PaymentResponse();

    $paymentResponse->setUuid(Str::uuid()->toString());
    $paymentResponse->setResponse(
      json_decode(json_encode($paypalResponse->result, JSON_THROW_ON_ERROR),
        true, 512, JSON_THROW_ON_ERROR)
    );
    $paymentResponse->setProcessorCurrency($this->driver->order->processor_currency);
    $paymentResponse->setProcessorStatus($paypalResponse->result->status);
    $paymentResponse->setProcessorTransactionId($paypalResponse->result->id);
    $paymentResponse->setPaymentTokenId(null);

    return $paymentResponse;
  }
}
