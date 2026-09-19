<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class IdempotencyRecord extends Model
{
    use HasFactory;
    public $incrementing=false; protected $primaryKey='key'; protected $keyType='string';
    protected $fillable = ['key','fingerprint','operation','user_id','request_body','response_body','status_code','expires_at'];
    protected $casts = ['request_body'=>'array','response_body'=>'array','expires_at'=>'datetime'];
}
