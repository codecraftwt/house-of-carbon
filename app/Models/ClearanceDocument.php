<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClearanceDocument extends Model
{
    protected $fillable = [
        'clearance_id','doc_key','doc_type','uploaded','file_path','original_name',
        'mime_type','file_size','uploaded_by','uploaded_at'
    ];

    protected $casts = [
        'uploaded' => 'boolean',
        'uploaded_at' => 'date',
    ];

    public function clearance()
    {
        return $this->belongsTo(Clearance::class, 'clearance_id', 'id');
    }
}