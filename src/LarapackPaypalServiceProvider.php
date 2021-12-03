<?php

namespace Adscom\LarapackPaypal;

use Illuminate\Support\ServiceProvider;

class LarapackPaypalServiceProvider extends ServiceProvider
{
  public function boot(): void
  {
    resolve('PaymentManager')->extend('paypal', function ($app) {
      return new PaypalDriver;
    });
  }
}
