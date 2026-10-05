<?php

namespace PnShop\Plugins\PayPal;

use RuntimeException;

/**
 * A PayPal API error. `issue` is PayPal's machine-readable reason (e.g. ORDER_ALREADY_CAPTURED).
 */
class PayPalException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $issue = null, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
