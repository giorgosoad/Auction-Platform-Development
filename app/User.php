<?php
 
namespace App;
 
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
 
class User extends Authenticatable
{
    use Notifiable;
    
    public function getAuthPassword() {
        return $this->password;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'username', 'password', 'role',
    ];

    protected $dates = [
        'created_at', 'updated_at',
    ];
    

    protected $hidden = [
        'password',
    ];
}