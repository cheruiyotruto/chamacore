<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupSetting extends Model
{
    use BelongsToGroup;

    protected $fillable = ['group_id', 'key', 'value', 'updated_by'];

    /**
     * Settings may hold scalars, arrays or booleans. Values are stored as JSON
     * and returned with their original PHP type.
     */
    protected function value(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }

                return json_decode($value, true);
            },
            set: fn ($value) => $value === null ? null : json_encode($value),
        );
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
