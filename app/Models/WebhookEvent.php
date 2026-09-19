<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class WebhookEvent extends Model
{
    use HasFactory;
    protected $fillable = ['provider','event_type','event_id','payload','signature','state','attempts','last_error','processed_at'];
    protected $casts = ['payload'=>'array','attempts'=>'integer','processed_at'=>'datetime'];
    public const STATE_RECEIVED='received'; public const STATE_VALIDATED='validated'; public const STATE_PROCESSING='processing'; public const STATE_PROCESSED='processed'; public const STATE_FAILED='failed'; public const STATE_DUPLICATE='duplicate';
    public function canRetry(): bool{return $this->attempts<3&&$this->state!==self::STATE_PROCESSED;}
}
