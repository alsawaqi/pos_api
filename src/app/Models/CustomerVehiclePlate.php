<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8.7 — vehicle plate for a customer (shared
 * pos_customer_vehicle_plates table, owned by pos_admin; §6.1).
 *
 * 1:N to pos_customers; company_id is denormalised and (company_id,
 * plate_number) is unique. The device's drive-thru flow looks a customer
 * up by plate. Plates are stored UPPERCASE (the writer normalises).
 * Writable here — the POS can register a plate when creating a customer.
 */
class CustomerVehiclePlate extends Model
{
    protected $table = 'pos_customer_vehicle_plates';

    protected $guarded = [];
}
