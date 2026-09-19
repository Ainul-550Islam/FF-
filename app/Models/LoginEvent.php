<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LoginEvent extends Model
{
    protected $fillable = ['user_id','event','ip_address','ip_hash','user_agent','device_hash','device_label','location','successful','metadata'];
    protected $casts = ['successful'=>'boolean','metadata'=>'array'];
    public function user(){return $this->belongsTo(User::class);}
}
