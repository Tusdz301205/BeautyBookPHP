<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchImage extends Model
{
    protected $fillable = ['branch_id', 'image_path', 'sort_order'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/' . $this->image_path);
    }
}
