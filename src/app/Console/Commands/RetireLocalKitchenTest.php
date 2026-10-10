<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Kitchen\Configuration;
use App\Models\KitchenTicket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Owner-attended local maintenance; never claims physical printer evidence. */
final class RetireLocalKitchenTest extends Command
{
    protected $signature = 'kitchen:retire-local-test {ticket} {--company=} {--branch=} {--reason=} {--confirm-local-test}';
    protected $description = 'Retain and retire one old local test ticket with unknown printing outcome';

    public function handle(): int
    {
        if (! app()->environment('local') || ! $this->option('confirm-local-test')
            || strlen(trim((string) $this->option('reason'))) < 12
            || (int) $this->option('company') < 1 || (int) $this->option('branch') < 1) {
            $this->error('Requires local environment, explicit confirmation, scope and operator reason.');
            return self::FAILURE;
        }
        DB::transaction(function (): void {
            $ticket = KitchenTicket::query()->whereKey((int) $this->argument('ticket'))
                ->where('company_id', (int) $this->option('company'))
                ->where('branch_id', (int) $this->option('branch'))->lockForUpdate()->firstOrFail();
            if ($ticket->print_result === KitchenTicket::RESULT_RETIRED_LOCAL_UNKNOWN) {
                return;
            }
            abort_unless($ticket->print_result === null && $ticket->printed_at === null
                && $ticket->created_at->lt(now()->subDay()), 409, 'Only old unresolved local tests can be retired.');
            $before = $ticket->getRawOriginal();
            app(Configuration::class)->audit((int) $ticket->company_id, (int) $ticket->branch_id,
                'local-cli-owner', 'retire_local_test_unknown', [
                    'ticket_id' => (int) $ticket->id, 'before' => $before,
                    'reason' => trim((string) $this->option('reason')),
                    'outcome' => 'unknown', 'resend' => false,
                ]);
            $ticket->update(['print_result' => KitchenTicket::RESULT_RETIRED_LOCAL_UNKNOWN]);
        });
        $this->info('Local test retired; order and original ticket evidence retained. No print was sent.');
        return self::SUCCESS;
    }
}
