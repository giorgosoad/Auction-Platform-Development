<?php

namespace App;

use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;

class Auction extends Model
{
    public $timestamps = true;
    
    protected $table = 'auctions';

    public function product()
    {
        return $this->belongsTo('App\Product', 'product_id');
    }


    public function user()
    {
        return $this->belongsTo('App\User', 'winner_user_id');
    }


    public function bid()
    {
        return $this->hasMany('App\Bid', 'auction_id');
    }


    public function max_bid()
    {
        return $this->hasMany('App\Bid', 'auction_id')->orderBy('price', 'DESC');
    }


    public function latest_bid()
    {
        return $this->hasMany('App\Bid', 'auction_id')->orderBy('created_at', 'DESC');
    }


    public function my_bid()
    {
        return $this->hasMany('App\Bid', 'auction_id')->where('user_id', Auth::user()->id)->orderBy('price', 'DESC');
    }


}
