<?php

declare(strict_types=1);

namespace App\Media;

use App\Utilities\Strings;

final class MetadataTextNormalizer
{
    public static function normalize(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = Strings::stringToUtf8($value);
        $value = strtr($value, [
            "\u{2010}" => '-',
            "\u{2011}" => '-',
            "\u{2012}" => '-',
            "\u{2013}" => '-',
            "\u{2014}" => '-',
            "\u{2212}" => '-',
        ]);
        $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $value) ?? '';
        $value = preg_replace('/[\p{Z}\s]+/u', ' ', trim($value)) ?? '';

        return '' === $value ? null : $value;
    }
}
