<?php

namespace Adscom\LarapackPaypal\Webhook\Handlers;

use Adscom\LarapackPaymentManager\Drivers\PaymentDriver;
use Adscom\LarapackPaymentManager\PaymentResponse;
use Illuminate\Support\Arr;

class PaymentCaptureRefundedHandler extends AbstractPaypalWebhookEventHandler
{

  protected function getPaymentResponse(): ?PaymentResponse
  {
    $refundedAmount = Arr::get($this->resource, 'amount.value');

    $this->paymentResponse->setPaidAmount($refundedAmount);

    $status = ($refundedAmount === $this->driver->payment->getOrder()->getDueAmount())
      ? PaymentDriver::getPaymentContractClass()::getRefundStatus()
      : PaymentDriver::getPaymentContractClass()::getPartialRefundStatus();
    $this->paymentResponse->setStatus($status);

    return $this->paymentResponse;
  }

  protected function getProcessorTransactionId(): string
  {
    return $this->resource['id'];
  }
}
