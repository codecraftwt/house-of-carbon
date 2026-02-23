<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_no',
        'customer_id',
        'supplier_id',
        'quotation_id',
        'status',
        'status_timeline',
        'origin_country',
        'destination_port',
        'invoice_value',
        'currency',
        'expected_arrival_date',
        'notes',
    ];

    protected $casts = [
        'expected_arrival_date' => 'date',
        'invoice_value' => 'decimal:2',
        'status_timeline' => 'array',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id', 'id');
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id', 'id');
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id', 'id');
    }
}
