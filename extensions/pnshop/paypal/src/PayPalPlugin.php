<?php

namespace PnShop\Plugins\PayPal;

use PnShop\Extension\Plugin;
use PnShop\Payment\PaymentGatewayManager;

class PayPalPlugin extends Plugin
{
    protected function bootPlugin(): void
    {
        $this->app->make(PaymentGatewayManager::class)->register(PayPalGateway::class);
    }
}
