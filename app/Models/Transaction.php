<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'sender_id', 'receiver_id', 'amount', 'tax_deducted', 'type', 
        'description', 'sender_balance_after', 'receiver_balance_after'
    ];
}
