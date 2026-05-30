<?php

declare(strict_types=1);

use App\Broadcasting\BranchChannel;
use App\Broadcasting\CompanyChannel;
use App\Broadcasting\DeviceChannel;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels (§11.5) — device real-time push
|--------------------------------------------------------------------------
| Private channels a paired device subscribes to for live updates. A device
| authenticates the subscription via POST /api/v1/broadcasting/auth carrying
| its Authorization: Bearer <device_token> (the `pos_device` guard). A device
| may ONLY join the channels for its OWN company / branch / device — the
| channel classes are the authorization boundary, mirroring the tenant scoping
| every device endpoint already enforces.
|
| Channel taxonomy:
|   company.{id}  — company-wide events (config.changed, catalogue updates)
|   branch.{id}   — branch operational stream (order.created/paid/voided,
|                   inventory.updated) — the main POS / handheld / KDS feed
|   device.{id}   — point-to-point to one terminal (targeted commands)
|
| The predicates live in App\Broadcasting\*Channel (unit-testable in isolation;
| the test broadcaster is `null`, whose HTTP auth() is a no-op, so the deny
| logic is verified directly against the channel classes, not over HTTP).
*/

Broadcast::channel('company.{companyId}', CompanyChannel::class, ['guards' => ['pos_device']]);
Broadcast::channel('branch.{branchId}', BranchChannel::class, ['guards' => ['pos_device']]);
Broadcast::channel('device.{deviceId}', DeviceChannel::class, ['guards' => ['pos_device']]);
