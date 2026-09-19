<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class TeamUpMatch extends Model
{
    use HasFactory;
    protected $fillable = ['private_table_id','team_a_user1_id','team_a_user2_id','team_b_user1_id','team_b_user2_id','status','winning_team','started_at','finished_at'];
    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    public function privateTable() { return $this->belongsTo(PrivateTable::class); }
    public function teamAUser1() { return $this->belongsTo(User::class, 'team_a_user1_id'); }
    public function teamAUser2() { return $this->belongsTo(User::class, 'team_a_user2_id'); }
    public function teamBUser1() { return $this->belongsTo(User::class, 'team_b_user1_id'); }
    public function teamBUser2() { return $this->belongsTo(User::class, 'team_b_user2_id'); }
    public function isFinished(): bool { return $this->status === 'finished'; }
}
