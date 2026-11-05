<?php

declare(strict_types=1);

$values = ['ok', '0', 0, '', null, false];

$kept = array_values(array_filter(
    $values,
    static fn ($value) => $value !== null && $value !== ''
));

echo json_encode($kept, JSON_THROW_ON_ERROR), PHP_EOL;

$qty = '0';
echo ($qty ?? 'absent'), PHP_EOL;
