<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WebhookDeadLetter extends Model
{
    use HasFactory;

    protected $table = 'webhook_dead_letters';

    protected $fillable = [
        'provider',
        'event_id',
        'external_ref',
        'payload',
        'raw_body',
        'error',
        'attempts',
        'first_failed_at',
        'last_failed_at',
        'next_retry_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'first_failed_at' => 'datetime',
        'last_failed_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function scopeDueForRetry($query)
    {
        return $query->whereNotNull('next_retry_at')
                      ->where('next_retry_at', '<=', now());
    }

    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
        $this->last_failed_at = now();
        
        // Exponential backoff: 1,2,4,8,16,32,60 min max
        $backoffMinutes = min(60, pow(2, $this->attempts - 1));
        $this->next_retry_at = now()->addMinutes($backoffMinutes);
        $this->save();
    }

    public function isDueForRetry(): bool
    {
        return $this->next_retry_at && $this->next_retry_at->isPast();
    }
}
