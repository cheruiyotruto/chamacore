<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class NextOfKin extends Model
{
    use BelongsToGroup;

    protected $table = 'next_of_kin';

    protected $fillable = ['group_id', 'member_id', 'name', 'relationship', 'phone', 'email', 'address', 'id_number'];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
