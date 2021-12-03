<?php

namespace Adscom\LarapackPaypal\Webhook;

use App\Models\Payment;
use Arr;
use Adscom\LarapackPaypal\PaypalDriver;
use Adscom\LarapackPaypal\Webhook\Handlers\AbstractPaypalWebhookEventHandler;
use Adscom\LarapackPaymentManager\Interfaces\IWebhookHandler;

class PaypalWebhookHandler implements IWebhookHandler
{
  public const WEBHOOK_VERIFICATION_STATUS_SUCCESS = 'SUCCESS';

  public function __construct(protected PaypalDriver $driver)
  {

  }

  public function getWebhookId(): string
  {
    return $this->driver->config['webhook_id'];
  }

  public function process(array $data = []): void
  {
    AbstractPaypalWebhookEventHandler::handle($this->driver, $data);
  }

  public function getPaymentForWebhook(array $data = []): Payment
  {
    if ($uuid = Arr::get($data, 'resource.custom_id',
      Arr::get($data, 'resource.disputed_transactions.0.custom'))) {
      return Payment::where('uuid', $uuid)
        ->firstOrFail();
    }

    abort(404, "Can't fetch payment from webhook");
  }

  public function isWebhookValid(array $data): bool
  {
    $headers = array_map(static fn($item) => $item[0], request()?->header());
    $request = new VerifyWebhookSignatureRequest($this->getWebhookId(), $data, $headers);

    $response = $this->driver->client->execute($request);

    return $response->result->verification_status === self::WEBHOOK_VERIFICATION_STATUS_SUCCESS;
  }
}
