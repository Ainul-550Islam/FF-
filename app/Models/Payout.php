<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Payout extends Model
{
    use HasFactory;

    // Payout lifecycle: pending -> approved -> processing -> completed
    // (or failed / cancelled at any pre-completion stage).
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const METHOD_WALLET = 'wallet';
    public const METHOD_MANUAL = 'manual';

    protected $fillable = ['user_id','tournament_id','amount_minor','currency','status','external_id','provider','idempotency_key','metadata','distribution_id','rank','recipient_user_id','recipient_team_id','payout_method','approved_by','processed_by','processed_at'];
    protected $casts = ['amount_minor'=>'integer','metadata'=>'array','rank'=>'integer','processed_at'=>'datetime'];
    public function user(){return $this->belongsTo(User::class);}
    public function tournament(){return $this->belongsTo(Tournament::class);}
    public function recipient(){return $this->belongsTo(User::class, 'recipient_user_id');}

    public function amountMinor(): int
    {
        return (int) ($this->amount_minor ?? 0);
    }
}
