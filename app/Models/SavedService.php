<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedService extends Model
{
    protected $guarded = ['id'];

    public function offering()
    {
        return $this->belongsTo(BranchServiceOffering::class, 'branch_service_offering_id')->withTrashed();
    }
}
