<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;

class Vote extends Model
{
    use BelongsToGroup;
    use OwnedByMember;

    protected $fillable = ['group_id', 'resolution_id', 'member_id', 'vote', 'voted_at'];

    protected function casts(): array
    {
        return ['voted_at' => 'datetime'];
    }

    public function resolution()
    {
        return $this->belongsTo(Resolution::class);
    }
}
