<?php

namespace Adscom\LarapackPaypal\Webhook\Handlers;


use Adscom\LarapackPaypal\PaypalDriver;
use Adscom\LarapackPaymentManager\PaymentResponse;
use Str;

abstract class AbstractPaypalWebhookEventHandler
{
  protected array $resource;
  protected PaymentResponse $paymentResponse;

  protected function __construct(protected PaypalDriver $driver, protected array $data)
  {
    $this->resource = $data['resource'];
    $this->paymentResponse = $driver->paymentResponse->clone();
  }

  public static function handle(PaypalDriver $driver, array $data): void
  {
    $eventType = $data['event_type'];
    $className = __NAMESPACE__.'\\'.Str::of($eventType)->lower()->replace('.', '_')->studly().'Handler';

    if (!class_exists($className)) {
      return;
    }

    $instance = new $className($driver, $data);
    $instance->handleResponse();
    $paymentResponse = $instance->getPaymentResponse();

    if ($paymentResponse) {
      $instance->driver->createPaymentFromResponse($paymentResponse);
    }
  }

  abstract protected function getPaymentResponse(): ?PaymentResponse;

  abstract protected function getProcessorTransactionId(): string;

  public function handleResponse(): void
  {
    $this->paymentResponse->setIsWebHookPayment(true);
    $this->paymentResponse->setResponse($this->data);
    $this->paymentResponse->setProcessorCurrency($this->driver->payment->processor_currency);
    $this->paymentResponse->setProcessorStatus($this->resource['status']);
    $this->paymentResponse->setProcessorTransactionId($this->getProcessorTransactionId());
    $this->paymentResponse->setPaymentTokenId(null);
  }
}
