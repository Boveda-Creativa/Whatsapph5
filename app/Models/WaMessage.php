<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WaMessage extends Model
{
    use HasFactory;

    protected $table = 'wa_messages';

    protected $fillable = [
        'conversation_id',
        'direction',     // in|out
        'body',
        'raw',
        'wa_message_id',
    ];

    protected $casts = [
        'raw' => 'array',
    ];

    public function conversation()
    {
        return $this->belongsTo(WaConversation::class, 'conversation_id');
    }
}