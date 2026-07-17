<?php

declare(strict_types=1);

namespace App;

final class SourceConfigBuilder
{
    /**
     * @return array<int, string>
     */
    public static function splitCsv(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== ''
        ));
    }
}
