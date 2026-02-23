<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name'];
    protected $appends = ['slug'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function getSlugAttribute(): string
    {
        return strtolower(str_replace(' ', '_', $this->name));
    }
}
