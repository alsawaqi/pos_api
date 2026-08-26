<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use DomainException;

/** A public-safe catalogue failure that controllers can map without parsing text. */
final class QrCatalogueException extends DomainException
{
    public const INVALID_LINE = 'invalid_catalogue_line';

    public const PRODUCT_UNAVAILABLE = 'product_unavailable';

    public const ADDON_UNAVAILABLE = 'addon_unavailable';

    public const ADDON_SELECTION_INVALID = 'addon_selection_invalid';

    public function __construct(
        public readonly string $codeName,
        string $message,
    ) {
        parent::__construct($message);
    }
}
