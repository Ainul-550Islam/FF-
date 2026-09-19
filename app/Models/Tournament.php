<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Tournament extends Model
{
    use HasFactory;
    protected $fillable = ['name','slug','status','entry_fee_minor','prize_pool_minor','max_teams','starts_at','ends_at','metadata'];
    protected $casts = ['entry_fee_minor'=>'integer','prize_pool_minor'=>'integer','max_teams'=>'integer','starts_at'=>'datetime','ends_at'=>'datetime','metadata'=>'array'];
    public function payouts(){return $this->hasMany(Payout::class);}
    public function settlements(){return $this->hasMany(FinancialSettlement::class);}
}
