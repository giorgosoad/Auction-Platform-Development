<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Bid extends Model
{
    public $timestamps = true;
    
    protected $table = 'auction_bids';

    public function auction()
    {
        return $this->belongsTo('App\Auction', 'auction_id');
    }


    public function user()
    {
        return $this->belongsTo('App\User', 'user_id');
    }


}
