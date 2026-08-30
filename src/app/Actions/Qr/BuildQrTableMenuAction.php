<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Floor;
use App\Models\Table;

/** Resolve a durable table token to a branch menu without touching a session. */
final class BuildQrTableMenuAction
{
    public function __construct(
        private readonly BuildQrBranchMenuAction $menu,
    ) {}

    /** @return array<string, mixed> */
    public function handle(string $tableToken): array
    {
        $table = Table::query()
            ->where('qr_token', trim($tableToken))
            ->where('status', 'active')
            ->first();

        if ($table === null) {
            throw $this->notFound();
        }

        $floor = Floor::query()
            ->whereKey((int) $table->floor_id)
            ->where('company_id', (int) $table->company_id)
            ->where('status', 'active')
            ->first();

        if ($floor === null) {
            throw $this->notFound();
        }

        $menu = $this->menu->handle(
            (int) $table->company_id,
            (int) $floor->branch_id,
        );

        return [
            'table' => [
                'uuid' => (string) $table->uuid,
                'label' => (string) $table->label,
            ],
        ] + $menu;
    }

    private function notFound(): QrDineInException
    {
        return new QrDineInException(
            'qr_table_not_found',
            404,
            'The table was not found.',
        );
    }
}
