<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    public $timestamps = true;
    
    protected $table = 'products';

    public function category()
    {
        return $this->belongsTo('App\Category', 'category_id');
    }

    public function auction()
    {
        return $this->hasOne('App\Auction', 'product_id');
    }

}
