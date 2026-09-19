<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Payout extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','tournament_id','amount_minor','currency','status','external_id','provider','idempotency_key','metadata'];
    protected $casts = ['amount_minor'=>'integer','metadata'=>'array'];
    public function user(){return $this->belongsTo(User::class);}
    public function tournament(){return $this->belongsTo(Tournament::class);}
}
