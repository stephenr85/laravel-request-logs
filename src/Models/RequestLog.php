<?php

namespace Rushing\RequestLogs\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class RequestLog extends Model
{
    use HasUuids, MassPrunable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'request_id',
        'path',
        'method',
        'api_version',
        'request_at',
        'response_at',
        'response_status',
        'is_error',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'request_at' => 'datetime:Y-m-d H:i:s.v',
        'response_at' => 'datetime:Y-m-d H:i:s.v',
        'response_status' => 'integer',
        'is_error' => 'boolean',
    ];

    /**
     * Resolve the connection from config so the host decides whether the table
     * lives on the central connection or per-tenant. A null config value falls
     * back to the model's / application's default connection.
     */
    public function getConnectionName()
    {
        return config('request-logs.database.connection') ?? $this->connection;
    }

    /**
     * Get related models through the morph many relationship.
     */
    public function getRelatedModels(string $modelClass): MorphToMany
    {
        return $this->morphedByMany($modelClass, 'model', 'request_log_models');
    }

    /**
     * Get the duration in milliseconds.
     */
    public function getDurationMsAttribute(): ?int
    {
        if (! $this->request_at || ! $this->response_at) {
            return null;
        }

        return $this->response_at->diff($this->request_at)->milliseconds;
    }

    /**
     * Get the formatted duration.
     */
    public function getDurationFormatted(): ?string
    {
        $duration = $this->duration_ms;

        if ($duration === null) {
            return null;
        }

        if ($duration < 1000) {
            return $duration.'ms';
        }

        return round($duration / 1000, 2).'s';
    }

    /**
     * Get the prunable model query.
     */
    public function prunable()
    {
        $ttlHours = config('request-logs.database.pruning.ttl_hours', 24 * 365); // Default 365 days

        return static::where('created_at', '<=', now()->subHours($ttlHours));
    }
}
