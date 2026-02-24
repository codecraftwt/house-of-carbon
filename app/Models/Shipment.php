<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $fillable = [
        'shipment_no',
        'order_id',
        'customer_id',
        'status',
        'origin',
        'destination',
        'carrier_name',
        'tracking_no',
        'eta',
        'notes',
    ];

    protected $casts = [
        'eta' => 'date',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id', 'id');
    }

    public function documents()
    {
        return $this->hasMany(ShipmentDocument::class);
    }
    public function clearance()
    {
        return $this->hasOne(Clearance::class, 'shipment_id', 'id');
    }
}
