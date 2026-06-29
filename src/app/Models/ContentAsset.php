<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of `content_assets` (marketing-api-owned, shared charity_db).
 *
 * Advertiser-uploaded images / videos. The device-config slider slice reads
 * each item's media URL + type + duration here; pos_api NEVER writes this table.
 * marketing-api stores the file on its own `public` disk and leaves the `url`
 * column null, so we rebuild an absolute, device-reachable URL from `path` +
 * config('services.marketing.public_url') — exactly like pos_admin does.
 */
class ContentAsset extends Model
{
    use SoftDeletes;

    protected $table = 'content_assets';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getPublicUrlAttribute(): ?string
    {
        return $this->buildMarketingUrl($this->getRawOriginal('url'), $this->path);
    }

    public function getThumbnailPublicUrlAttribute(): ?string
    {
        return $this->buildMarketingUrl($this->getRawOriginal('thumbnail_url'), $this->thumbnail_path);
    }

    private function buildMarketingUrl(?string $absolute, ?string $path): ?string
    {
        if (! empty($absolute)) {
            return $absolute;
        }
        if (empty($path)) {
            return null;
        }

        $base = rtrim((string) config('services.marketing.public_url'), '/');

        return $base.'/storage/'.ltrim($path, '/');
    }
}
