<?php

namespace Peralta\AgentKit\Models;

use Illuminate\Database\Eloquent\Model;

class AgentMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'type',
        'conversation_id',
        'provider',
        'model',
        'duration_ms',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('agent-kit.analytics.table', 'agent_kit_metrics');
    }

    protected static function booted(): void
    {
        static::creating(function (self $metric) {
            $metric->created_at ??= now();
        });
    }
}
