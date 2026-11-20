<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An enquiry sent from an agent's landing page.
 */
class AgentLead extends Model
{
    use HasUlids;

    protected $fillable = ['agent_id', 'name', 'phone', 'email', 'package', 'message', 'source_host', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
