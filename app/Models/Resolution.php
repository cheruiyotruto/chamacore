<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class Resolution extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'meeting_id', 'title', 'description', 'status',
        'votes_for', 'votes_against', 'votes_abstain', 'voting_opens_at',
        'voting_closes_at', 'passed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'votes_for' => 'integer',
            'votes_against' => 'integer',
            'votes_abstain' => 'integer',
            'voting_opens_at' => 'datetime',
            'voting_closes_at' => 'datetime',
            'passed_at' => 'datetime',
        ];
    }

    public function votes()
    {
        return $this->hasMany(Vote::class);
    }

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }
}
