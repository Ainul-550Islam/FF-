<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class LedgerEntry extends Model
{
    use HasFactory;
    protected $fillable = ['wallet_id','user_id','direction','amount_minor','balance_after_minor','reference_type','reference_id','idempotency_key','metadata'];
    protected $casts = ['amount_minor'=>'integer','balance_after_minor'=>'integer','metadata'=>'array'];
    public function wallet(){return $this->belongsTo(Wallet::class);}
    public function user(){return $this->belongsTo(User::class);}
    // ledger_entries table is source of truth for financial integrity
}
