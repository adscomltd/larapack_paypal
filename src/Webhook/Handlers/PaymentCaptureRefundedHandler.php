<?php

namespace Adscom\LarapackPaypal\Webhook\Handlers;

use App\Models\Payment;
use Adscom\LarapackPaymentManager\PaymentResponse;
use Illuminate\Support\Arr;

class PaymentCaptureRefundedHandler extends AbstractPaypalWebhookEventHandler
{

  protected function getPaymentResponse(): ?PaymentResponse
  {
    $refundedAmount = Arr::get($this->resource, 'amount.value');

    $this->paymentResponse->setPaidAmount($refundedAmount);

    $status = ($refundedAmount === $this->driver->payment->order->due_amount)
      ? Payment::STATUS_REFUND
      : Payment::STATUS_PARTIAL_REFUND;
    $this->paymentResponse->setStatus($status);

    return $this->paymentResponse;
  }

  protected function getProcessorTransactionId(): string
  {
    return $this->resource['id'];
  }
}
