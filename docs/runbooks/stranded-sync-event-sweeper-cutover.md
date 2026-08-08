# Stranded sync-event sweeper cutover

This runbook activates API-002 without redispatching ambiguous historical
`pos_sync_events` rows. The floor is a one-time deployment boundary, not a
tuning knob. The command processes only handled events whose `id` is strictly
greater than `SYNC_STRANDED_SWEEP_AFTER_ID`.

Never derive or change this value automatically during application startup or
deployment. Never use `0` on an existing production ledger.

## Stage 1: deploy fail-closed

1. Leave `SYNC_STRANDED_SWEEP_AFTER_ID` absent or blank in the production
   `src/.env`.
2. Deploy the application and rebuild Laravel's config cache normally.
3. Verify the command refuses to run:

   ```bash
   docker compose -f docker-compose.prod.yml exec -T scheduler \
     php artisan sync:sweep-stranded-events
   ```

   The expected exit code is `2` (`Command::INVALID`) and the output must say
   that `SYNC_STRANDED_SWEEP_AFTER_ID` must be a nonnegative integer. This
   stage performs no dispatches or database writes.
4. Run the following SQL manually against the production PostgreSQL database.
   It is explicitly read-only. Do not replace it with an `UPDATE`, a cleanup,
   or an automatically generated deploy value.

   ```sql
   BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;

   WITH boundary AS (
       SELECT COALESCE(MAX(id), 0)::bigint AS floor
       FROM pos_sync_events
   )
   SELECT
       boundary.floor AS sync_stranded_sweep_after_id,
       COUNT(events.id) AS rows_quarantined,
       COUNT(events.id) FILTER (
           WHERE events.ack_status = 'received'
             AND events.server_received_at <= CURRENT_TIMESTAMP - INTERVAL '10 minutes'
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
             AND events.server_received_at <= CURRENT_TIMESTAMP - INTERVAL '10 minutes'
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
   LEFT JOIN pos_sync_events AS events
       ON events.id <= boundary.floor
   GROUP BY boundary.floor;

   WITH boundary AS (
       SELECT COALESCE(MAX(id), 0)::bigint AS floor
       FROM pos_sync_events
   )
   SELECT
       events.event_type,
       COUNT(*) AS old_received_rows
   FROM pos_sync_events AS events
   CROSS JOIN boundary
   WHERE events.id <= boundary.floor
     AND events.ack_status = 'received'
     AND events.server_received_at <= CURRENT_TIMESTAMP - INTERVAL '10 minutes'
   GROUP BY events.event_type
   ORDER BY events.event_type;

   COMMIT;
   ```

5. Record the first query's `sync_stranded_sweep_after_id` exactly. Rows at or
   below it remain quarantined for manual review. Events inserted after the
   read-only snapshot receive higher ids and are eligible once they become old
   enough.

## Stage 2: activate only post-cutover recovery

1. Set the captured value in production, for example:

   ```dotenv
   SYNC_STRANDED_SWEEP_AFTER_ID=123456
   ```

2. Rebuild the shared Laravel config cache and restart the scheduler. The
   runtime value must be the captured integer, not blank and not `0`:

   ```bash
   bash deploy/deploy.sh
   docker compose -f docker-compose.prod.yml restart scheduler
   docker compose -f docker-compose.prod.yml exec -T scheduler \
     php artisan config:show sync
   ```

3. Run one bounded manual sweep, then inspect its structured log before leaving
   the every-minute schedule active:

   ```bash
   docker compose -f docker-compose.prod.yml exec -T scheduler \
     php artisan sync:sweep-stranded-events --limit=1
   ```

4. Monitor only rows strictly above the captured floor:

   ```sql
   SELECT event_type, COUNT(*) AS currently_stranded
   FROM pos_sync_events
   WHERE id > 123456
     AND ack_status = 'received'
     AND server_received_at <= CURRENT_TIMESTAMP - INTERVAL '10 minutes'
   GROUP BY event_type
   ORDER BY event_type;
   ```

Replace `123456` in the examples with the captured value. Do not raise the
floor to hide a future backlog; investigate and recover those events through
the normal sweeper or an explicit operator review.
