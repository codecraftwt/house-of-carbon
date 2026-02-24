<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Clearance extends Model
{
    protected $fillable = [
        'clearance_no','shipment_id','cha_id','arrival_port','arrival_date',
        'duty_amount','currency','status','clearance_date','released_date'
    ];

    protected $casts = [
        'arrival_date' => 'date',
        'clearance_date' => 'date',
        'released_date' => 'date',
        'duty_amount' => 'decimal:2',
    ];

    public function documents()
    {
        return $this->hasMany(ClearanceDocument::class, 'clearance_id', 'id');
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class, 'shipment_id', 'id');
    }
    
}