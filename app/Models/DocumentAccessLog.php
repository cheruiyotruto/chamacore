<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class DocumentAccessLog extends Model
{
    use BelongsToGroup;

    public const UPDATED_AT = null;

    protected $fillable = ['group_id', 'document_id', 'user_id', 'action', 'ip_address'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
