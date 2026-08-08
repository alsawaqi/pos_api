<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Models\Device;
use App\Models\SyncEvent;

/**
 * Optional second phase for work that must run only after the handler's
 * database effect and the sync event's processed stamp commit together.
 */
interface AfterSyncEventCommitHandler extends SyncEventHandler
{
    /**
     * @param  array<string, mixed>  $result  the committed result_json payload
     */
    public function afterSyncEventCommit(SyncEvent $event, Device $device, array $result): void;
}
