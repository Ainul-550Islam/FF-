<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class UserSession extends Model
{
    protected $fillable = ['user_id','session_id','ip_address','user_agent','device_label','location','last_active_at','expires_at','is_current','is_revoked'];
    protected $casts = ['last_active_at'=>'datetime','expires_at'=>'datetime','is_current'=>'boolean','is_revoked'=>'boolean'];
    public function user(){return $this->belongsTo(User::class);}
}
