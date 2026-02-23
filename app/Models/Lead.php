<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company',
        'contact',
        'email',
        'phone',
        'value',
        'added_date',
        'last_contact',
        'status',
    ];

    protected $casts = [
        'added_date' => 'date',
        'last_contact' => 'date',
        'value' => 'decimal:2',
    ];
}