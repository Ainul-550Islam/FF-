<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class FinancialSettlement extends Model
{
    use HasFactory;
    protected $fillable = ['tournament_id','total_amount_minor','currency','status','idempotency_key','metadata','completed_at'];
    protected $casts = ['total_amount_minor'=>'integer','metadata'=>'array','completed_at'=>'datetime'];
    public const STATUS_PENDING='pending'; public const STATUS_PROCESSING='processing'; public const STATUS_COMPLETED='completed'; public const STATUS_FAILED='failed';
    public function tournament(){return $this->belongsTo(Tournament::class);}
}
