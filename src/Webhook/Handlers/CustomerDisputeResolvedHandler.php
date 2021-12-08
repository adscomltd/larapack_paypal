<?php

namespace Adscom\LarapackPaypal\Webhook\Handlers;

use Adscom\LarapackPaymentManager\Drivers\PaymentDriver;
use Arr;
use Adscom\LarapackPaymentManager\PaymentResponse;

class CustomerDisputeResolvedHandler extends AbstractPaypalWebhookEventHandler
{

  protected function getPaymentResponse(): ?PaymentResponse
  {
    $disputeAmount = Arr::get($this->resource, 'dispute_amount.value');

    // TODO: add correct check for seller win situation for paypal
    if (Arr::get($this->resource, 'dispute_outcome.outcome_code') !== 'RESOLVED_BUYER_FAVOUR') {
      return null;
    }

    $this->paymentResponse->setPaidAmount($disputeAmount);
    $this->paymentResponse->setStatus(PaymentDriver::getPaymentContractClass()::getChargebackStatus());

    return $this->paymentResponse;
  }

  protected function getProcessorTransactionId(): string
  {
    return $this->resource['dispute_id'];
  }
}
