# Stranded sync-event sweeper cutover

This runbook activates API-002 without redispatching ambiguous historical
pos_sync_events rows. Two independent controls keep the rollout fail-closed:

- SYNC_STRANDED_SWEEP_AFTER_ID is the permanent historical quarantine boundary.
  The command processes only handled events whose id is strictly greater than
  that value.
- SYNC_STRANDED_SWEEP_ENABLED controls only the every-minute schedule. Manual
  invocations remain available for the bounded canary when this flag is false.

Never derive or change the floor automatically during application startup or
deployment. Never use 0 on an existing production ledger. Keep the schedule
disabled until Stage 3.

## Stage 1: deploy fail-closed and capture the sequence boundary

1. Leave SYNC_STRANDED_SWEEP_AFTER_ID absent or blank in the production
   src/.env, and explicitly set:

       SYNC_STRANDED_SWEEP_ENABLED=false

2. Deploy the application and rebuild Laravel's shared config cache:

       bash deploy/deploy.sh

   Do not restart the scheduler. The long-running schedule:work process starts
   a fresh schedule:run child each minute, and that child reads the rebuilt
   shared config cache. Restarting the scheduler can interrupt active work.

3. Verify the cached values and prove that the manual command still refuses to
   run without a valid floor:

       docker compose -f docker-compose.prod.yml exec -T scheduler \
         php artisan config:show sync
       docker compose -f docker-compose.prod.yml exec -T scheduler \
         php artisan sync:sweep-stranded-events

   The enabled value must be false. The command's expected exit code is 2
   (Command::INVALID), and its output must say that
   SYNC_STRANDED_SWEEP_AFTER_ID must be a nonnegative integer. This step
   performs no dispatches or database writes.

4. Run the following SQL manually against the production PostgreSQL database.
   It is explicitly read-only. Do not replace it with an UPDATE, cleanup, or
   automatically generated deploy value.

       BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;

       WITH seq AS (
           SELECT last_value, cache_size
           FROM pg_sequences
           WHERE schemaname = 'public'
             AND sequencename = 'pos_sync_events_id_seq'
       ),
       bounds AS (
           SELECT COALESCE(MAX(id), 0)::bigint AS committed_max
           FROM public.pos_sync_events
       ),
       boundary AS (
           SELECT
               GREATEST(
                   bounds.committed_max,
                   COALESCE(seq.last_value, 0)
               )::bigint AS floor,
               bounds.committed_max,
               seq.last_value AS sequence_high_water,
               seq.cache_size
           FROM bounds
           CROSS JOIN seq
       )
       SELECT
           boundary.floor AS sync_stranded_sweep_after_id,
           boundary.committed_max,
           boundary.sequence_high_water,
           boundary.cache_size,
           COUNT(events.id) AS rows_quarantined,
           COUNT(events.id) FILTER (
               WHERE events.ack_status = 'received'
                 AND events.server_received_at
                     <= CURRENT_TIMESTAMP - INTERVAL '10 minutes'
                 AND events.event_type IN (
                     'order.create',
                     'order.hold',
                     'order.transfer',
                     'order.pay',
                     'order.deliver',
                     'order.void',
                     'shift.open',
                     'shift.close',
                     'expense.log',
                     'restock.request',
                     'donation.record',
                     'stock.count',
                     'product.waste',
                     'slider.display'
                 )
           ) AS old_handled_received_quarantined,
           COUNT(events.id) FILTER (
               WHERE events.ack_status = 'received'
                 AND events.server_received_at
                     <= CURRENT_TIMESTAMP - INTERVAL '10 minutes'
                 AND events.event_type NOT IN (
                     'order.create',
                     'order.hold',
                     'order.transfer',
                     'order.pay',
                     'order.deliver',
                     'order.void',
                     'shift.open',
                     'shift.close',
                     'expense.log',
                     'restock.request',
                     'donation.record',
                     'stock.count',
                     'product.waste',
                     'slider.display'
                 )
           ) AS old_unhandled_received_quarantined,
           MIN(events.server_received_at) FILTER (
               WHERE events.ack_status = 'received'
           ) AS oldest_received_at
       FROM boundary
       LEFT JOIN public.pos_sync_events AS events
           ON events.id <= boundary.floor
       GROUP BY
           boundary.floor,
           boundary.committed_max,
           boundary.sequence_high_water,
           boundary.cache_size;

       COMMIT;

   The query must return exactly one row. Stop the rollout if it returns no row
   or if the sequence name is not the sequence used by
   public.pos_sync_events.id. A NULL sequence_high_water must also stop the
   production cutover: pg_sequences uses NULL both for a never-read sequence
   and when the current database role lacks the required sequence privilege.
   Do not infer an unused sequence from committed_max = 0 or fall back to
   MAX(id). A genuinely empty installation may use a floor of 0 only through a
   separately approved proof that the role can read the sequence and that the
   sequence has never allocated a value.

5. Record sync_stranded_sweep_after_id and cache_size exactly. PostgreSQL
   sequence allocation is nontransactional, so sequence_high_water includes ids
   already allocated to transactions that were invisible to the read-only
   snapshot. If sequence caching places the boundary above committed_max, those
   reserved ids are deliberately quarantined too. Never lower the floor to
   committed_max.

6. Wait at least 310 seconds, then run this visibility audit in a new read-only
   transaction. Replace 123456 with the captured floor; do not recompute or
   raise the floor.

       BEGIN TRANSACTION ISOLATION LEVEL READ COMMITTED READ ONLY;

       SELECT
           event_type,
           COUNT(*) AS old_received_rows,
           MIN(server_received_at) AS oldest_received_at
       FROM public.pos_sync_events
       WHERE id <= 123456
         AND ack_status = 'received'
         AND server_received_at
             <= CURRENT_TIMESTAMP - INTERVAL '10 minutes'
       GROUP BY event_type
       ORDER BY event_type;

       COMMIT;

   The delay makes transactions that were already in flight more likely to be
   visible for the operator audit. It is not a transaction-lifetime guarantee
   and is not the safety boundary. The captured sequence high-water mark is the
   safety boundary: every id at or below it remains quarantined even if its row
   becomes visible later. Record the audit output for separate historical
   review.

## Stage 2: set the floor and run a disabled canary

1. Set the captured value while keeping automatic scheduling disabled:

       SYNC_STRANDED_SWEEP_AFTER_ID=123456
       SYNC_STRANDED_SWEEP_ENABLED=false

2. Rebuild the shared config cache through the normal deploy:

       bash deploy/deploy.sh
       docker compose -f docker-compose.prod.yml exec -T scheduler \
         php artisan config:show sync

   Verify that the floor is the exact captured integer and enabled is false.
   Do not restart the scheduler.

3. Run one bounded manual sweep. The schedule flag intentionally does not block
   a manual invocation with a valid floor:

       docker compose -f docker-compose.prod.yml exec -T scheduler \
         php artisan sync:sweep-stranded-events --limit=1

   Inspect the command result and its structured log. Stop here and investigate
   any unexpected event type, failure, or origin mismatch. While enabled
   remains false, the every-minute scheduler cannot dispatch another batch.

4. Monitor only rows strictly above the captured floor:

       SELECT event_type, COUNT(*) AS currently_stranded
       FROM public.pos_sync_events
       WHERE id > 123456
         AND ack_status = 'received'
         AND server_received_at
             <= CURRENT_TIMESTAMP - INTERVAL '10 minutes'
       GROUP BY event_type
       ORDER BY event_type;

## Stage 3: enable scheduled recovery

1. After the floor, visibility audit, and manual canary are accepted, change
   only the schedule flag:

       SYNC_STRANDED_SWEEP_AFTER_ID=123456
       SYNC_STRANDED_SWEEP_ENABLED=true

2. Rebuild the shared config cache and verify it from the scheduler service:

       bash deploy/deploy.sh
       docker compose -f docker-compose.prod.yml exec -T scheduler \
         php artisan config:show sync

   Confirm the recorded floor is unchanged and enabled is true. Do not restart
   the scheduler; its next schedule:run child reads the new cached value.

3. Monitor the structured sweeper logs and the above-floor query from Stage 2.
   For a planned disable, set SYNC_STRANDED_SWEEP_ENABLED=false and run the
   normal deploy. A schedule child that already started may still run to
   completion. For an immediate emergency disable, perform these steps in
   order:

   1. Stop the scheduler:

          docker compose -f docker-compose.prod.yml stop scheduler

   2. Set SYNC_STRANDED_SWEEP_ENABLED=false in the production src/.env.
   3. Run the normal deploy:

          bash deploy/deploy.sh

   The deploy rebuilds the shared config cache before docker compose up starts
   services, so the scheduler returns with the schedule disabled. Do not
   restart the scheduler manually, and do not change the floor.

Replace 123456 in every example with the one captured sequence boundary. Rows
at or below it require explicit operator review. Do not raise the floor to hide
a future backlog; investigate and recover those events through the normal
sweeper or a separately approved historical-reconciliation procedure.
