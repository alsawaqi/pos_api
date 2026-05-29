<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 8.8 — restock request header (shared pos_restock_requests, §5.6.5).
 *
 * A branch asks to be restocked. A device-originated request (the
 * `restock.request` sync event) lands status=submitted (awaiting the merchant
 * portal's review/allocation). requested_by_user_id stays null — the schema
 * tracks a portal user, not POS staff, so device origin carries no requester.
 */
class RestockRequest extends Model
{
    protected $table = 'pos_restock_requests';

    protected $guarded = [];

    public const STATUS_SUBMITTED = 'submitted';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<RestockRequestLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RestockRequestLine::class);
    }
}
