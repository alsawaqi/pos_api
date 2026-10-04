<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 8.6 — read model of the shared pos_staff table (owned by
 * pos_admin's schema). pos_api reads it to authenticate a POS staff
 * member by numeric PIN at a paired device.
 *
 * pin_hash is a bcrypt hash (validated with Hash::check); it is
 * `$hidden` so it never serialises into a response — property access
 * for the hash comparison still works. PINs are unique per company
 * (enforced when minted in the merchant portal), so a device can
 * resolve its operator from the PIN alone within its company+branch.
 */
class PosStaff extends Model
{
    use SoftDeletes;

    protected $table = 'pos_staff';

    protected $guarded = [];

    /**
     * LAUNCH-P5 — the offline approval verifier (pin_offline_key = K,
     * salt, iterations) is hidden too: K never leaves the server, and the
     * device-facing salt/iterations/check go out only through
     * ApproverVerifier::material().
     *
     * @var list<string>
     */
    protected $hidden = ['pin_hash', 'pin_offline_key', 'pin_offline_salt', 'pin_offline_iterations'];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_TERMINATED = 'terminated';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hired_at' => 'date',
            'terminated_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
