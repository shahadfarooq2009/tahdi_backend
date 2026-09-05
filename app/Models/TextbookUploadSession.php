<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TextbookUploadSession extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'textbook_upload_sessions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_chunks' => 'array',
            'file_size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @return list<int>
     */
    public function receivedChunkIndices(): array
    {
        $chunks = $this->received_chunks;

        if (! is_array($chunks)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $chunks)));
    }
}
