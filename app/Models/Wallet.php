<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Wallet extends Model
{
    // Wallet lifecycle states (wallets.status: active|frozen).
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FROZEN = 'frozen';

    use HasFactory;
    protected $fillable = ['user_id','currency','balance_minor','is_locked'];
    protected $casts = ['balance_minor'=>'integer','is_locked'=>'boolean'];
    public function user(){return $this->belongsTo(User::class);}
    public function ledgerEntries(){return $this->hasMany(LedgerEntry::class)->orderBy('created_at');}
}
