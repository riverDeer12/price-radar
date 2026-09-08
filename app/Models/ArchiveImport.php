<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArchiveImport extends Model
{
    protected $fillable = [
        'archive_date', 'source_url', 'size_bytes', 'status', 'progress', 'triggered_by',
        'started_at', 'finished_at', 'error_message', 'stats',
    ];

    protected $casts = [
        'archive_date' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'progress' => 'array',
        'stats' => 'array',
    ];

    public function isRunning(): bool
    {
        return in_array($this->status, ['pending', 'downloading', 'importing'], true);
    }
}
