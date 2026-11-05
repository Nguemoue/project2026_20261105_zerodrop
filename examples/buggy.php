<?php

declare(strict_types=1);

/**
 * Quantity 0, coupon code "0", and a false flag all disappear.
 */
function checkout(array $codes, object $orders, mixed $qty): mixed
{
    $kept = array_filter($codes);

    $visible = $orders->filter();

    $explicit = array_filter(
        $codes,
        null
    );

    $still = array_filter($codes, static fn ($code) => $code);

    $fallback = $qty ?: 1;

    if (empty($qty)) {
        $fallback = 1;
    }

    // Reviewed: this list never contains a real zero.
    $ignored = array_filter($codes); // zerodrop:ignore

    return [$kept, $visible, $explicit, $still, $fallback, $ignored];
}
