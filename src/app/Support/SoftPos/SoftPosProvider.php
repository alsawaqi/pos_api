<?php

declare(strict_types=1);

namespace App\Support\SoftPos;

enum SoftPosProvider: string
{
    case Dhofar = 'mosambee_dhofar';
    case Muscat = 'mosambee_muscat';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Dhofar => 'Bank Dhofar SoftPOS',
            self::Muscat => 'Bank Muscat SoftPOS',
            self::None => 'No card terminal app',
        };
    }

    public function package(): ?string
    {
        return match ($this) {
            self::Dhofar => 'com.mosambee.dhofar.softpos',
            self::Muscat => 'com.mosambee.muscat.softpos',
            self::None => null,
        };
    }

    public function refundNeedsTransactionId(): bool
    {
        return $this === self::Dhofar;
    }

    public function voidNeedsSessionId(): bool
    {
        return $this === self::Muscat;
    }
}
