<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class GameSession extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','private_table_id','game_mode','game_variation','bet_amount','result','gold_change','gem_change','trophies_change','duration_seconds','is_team_up','team','started_at','finished_at','metadata'];
    protected $casts = ['bet_amount' => 'integer', 'gold_change' => 'integer', 'gem_change' => 'integer', 'trophies_change' => 'integer', 'duration_seconds' => 'integer', 'is_team_up' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'metadata' => 'array'];
    public function user() { return $this->belongsTo(User::class); }
    public function privateTable() { return $this->belongsTo(PrivateTable::class); }
    public function isWin(): bool { return $this->result === 'win'; }
    public function isLoss(): bool { return $this->result === 'loss'; }
}
