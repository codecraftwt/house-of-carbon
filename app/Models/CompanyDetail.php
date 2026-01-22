<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompanyDetail extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'user_id',
        'company_name',
        'company_email',
        'company_phone',
        'company_address',
        'city',
        'state',
        'country',
        'zip_code',
        'website',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
