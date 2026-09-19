<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Payment extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','wallet_id','provider','external_id','provider_reference','amount_minor','currency','status','idempotency_key','idempotency_fingerprint','metadata','authorized_at','succeeded_at','failed_at'];
    protected $casts = ['amount_minor'=>'integer','metadata'=>'array','authorized_at'=>'datetime','succeeded_at'=>'datetime','failed_at'=>'datetime'];
    public const STATUS_CREATED='created'; public const STATUS_PENDING='pending'; public const STATUS_PROCESSING='processing'; public const STATUS_AUTHORIZED='authorized'; public const STATUS_SUCCEEDED='succeeded'; public const STATUS_FAILED='failed'; public const STATUS_EXPIRED='expired'; public const STATUS_CANCELLED='cancelled'; public const STATUS_REFUNDING='refunding'; public const STATUS_REFUNDED='refunded';
}
