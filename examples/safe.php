<?php

declare(strict_types=1);

/**
 * Patterns that keep a real zero, plus lookalikes that are not the bug.
 */
function checkout(array $codes, object $orders, mixed $qty, ?object $user): mixed
{
    $kept = array_filter(
        $codes,
        static fn ($code) => $code !== null && $code !== ''
    );

    $visible = $orders->filter(
        static fn ($code) => $code !== null && $code !== ''
    );

    $lengths = array_filter($codes, 'strlen');

    $fallback = $qty ?? 1;

    if ($qty === null || $qty === '') {
        $fallback = 1;
    }

    $label = $qty === 0 ? 'zero' : 'other';
    $name = $user?->name ?? 'n/a';
    $debug = $qty ? 'set' : 'missing';

    return [$kept, $visible, $lengths, $fallback, $label, $name, $debug];
}
