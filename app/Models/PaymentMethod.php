<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PaymentMethod extends Model
{
    protected $fillable = ['user_id','provider','label','masked_identifier','identifier_hash','is_default','is_verified','verified_at','metadata'];
    protected $casts = ['is_default'=>'boolean','is_verified'=>'boolean','verified_at'=>'datetime','metadata'=>'array'];
    public function user(){return $this->belongsTo(User::class);}
}
