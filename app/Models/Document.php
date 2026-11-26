<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Document extends Model
{
    use Auditable;
    use BelongsToGroup;
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'group_id', 'member_id', 'documentable_type', 'documentable_id',
        'type', 'title', 'disk', 'path', 'mime_type', 'size', 'status',
        'visibility', 'uploaded_by', 'verified_by', 'verified_at', 'notes', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Document $document) => $document->uuid ??= (string) Str::uuid());
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function documentable()
    {
        return $this->morphTo();
    }

    public function accessLogs()
    {
        return $this->hasMany(DocumentAccessLog::class);
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
