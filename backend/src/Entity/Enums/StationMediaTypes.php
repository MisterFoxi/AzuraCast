<?php

declare(strict_types=1);

namespace App\Entity\Enums;

/** Station media classifications used by AutoDJ, reports and library filtering. */
final class StationMediaTypes
{
    public const string MUSIC = 'music';
    public const string TALK = 'talk';
    public const string ID = 'id';
    public const string PROMO = 'promo';
    public const string AD = 'ad';

    /** @deprecated Merged into {@see ID} — still read from DB for legacy files. */
    public const string LEGACY_LEGAL_ID = 'legal_id';

    /**
     * @return non-empty-list<string>
     */
    public static function values(): array
    {
        return [self::MUSIC, self::TALK, self::LEGACY_LEGAL_ID, self::ID, self::PROMO, self::AD];
    }

    /**
     * @return non-empty-list<string>
     */
    public static function stationIdTypeValues(): array
    {
        return [self::ID, self::LEGACY_LEGAL_ID];
    }

    public static function isStationId(?string $type): bool
    {
        return in_array($type, self::stationIdTypeValues(), true);
    }
}
