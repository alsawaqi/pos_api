<?php

declare(strict_types=1);

/** Standalone legacy harnesses keep their original guards; P0 gets fresh DBs. */
function p0DisposableDatabase(string $suffix, string $legacy): string
{
    if (getenv('LAUNCH_P0_DISPOSABLE') !== '1') {
        return $legacy;
    }
    $prefix = getenv('P0_DATABASE_PREFIX') ?: '';
    if (! preg_match('/^launch_p0_fix1_[a-f0-9]{12}$/D', $prefix)
        || ! in_array($suffix, ['core', 'staff', 'merge', 'plate'], true)) {
        throw new RuntimeException('Unique P0 disposable database prefix required.');
    }

    return $prefix.'_'.$suffix;
}
