<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GovernmentOffice extends Model
{
    protected $fillable = ['title', 'current_holder_id', 'signature_fee', 'tenure_expires_at'];
}
