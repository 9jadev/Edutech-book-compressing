<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Parcel extends Model
{
    protected $fillable = [
        'district_id', 'grid_x', 'grid_y', 'owner_id', 'price', 
        'status', 'deed_number', 'signed_by_director_id', 'c_of_o_signed_at', 
        'facility_name', 'facility_type', 'visitor_fee', 'for_sale'
    ];
}
