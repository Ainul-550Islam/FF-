<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class UserIdentity extends Model
{
    protected $fillable = ['user_id','provider','provider_user_id','email','name','avatar','payload','last_used_at'];
    protected $casts = ['payload'=>'array','last_used_at'=>'datetime'];
    public function user(){return $this->belongsTo(User::class);}
}
