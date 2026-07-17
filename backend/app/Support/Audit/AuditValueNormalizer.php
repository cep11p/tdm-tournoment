<?php

namespace App\Support\Audit;

use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;

final class AuditValueNormalizer
{
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value;
    }
}
