<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WaConversation extends Model
{
    use HasFactory;

    protected $table = 'wa_conversations';

    protected $fillable = [
        'phone',
        'state',
        'mode',
        'data',
        'last_inbound_at',
        'window_open_until',
        'lead_id',
    ];

    protected $casts = [
        'data' => 'array',
        'last_inbound_at' => 'datetime',
        'window_open_until' => 'datetime',
    ];

    public function lead()
    {
        return $this->belongsTo(WaLead::class, 'lead_id');
    }

    public function messages()
    {
        return $this->hasMany(WaMessage::class, 'conversation_id');
    }
}