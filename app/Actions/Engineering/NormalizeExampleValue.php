<?php

namespace App\Actions\Engineering;

use InvalidArgumentException;

class NormalizeExampleValue
{
    public function execute(string $value): string
    {
        $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $value);

        if ($normalized === null) {
            throw new InvalidArgumentException('The value must contain valid UTF-8 text.');
        }

        return trim($normalized);
    }
}
