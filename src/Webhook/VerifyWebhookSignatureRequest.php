<?php

namespace Adscom\LarapackPaypal\Webhook;

use PayPalHttp\HttpRequest;

class VerifyWebhookSignatureRequest extends HttpRequest
{
  public function __construct(string $webhookId, array $data, array $headers)
  {
    parent::__construct("/v1/notifications/verify-webhook-signature", "POST");

    $this->headers["Content-Type"] = "application/json";
    $this->setupBody($webhookId, $data, $headers);
  }

  protected function setupBody(string $webhookId, array $webhookEvent, array $headers): void
  {
    $this->body = [
      'transmission_id' => $headers['paypal-transmission-id'],
      'transmission_time' => $headers['paypal-transmission-time'],
      'cert_url' => $headers['paypal-cert-url'],
      'auth_algo' => $headers['paypal-auth-algo'],
      'transmission_sig' => $headers['paypal-transmission-sig'],
      'webhook_id' => $webhookId,
      'webhook_event' => $webhookEvent,
    ];
  }
}
