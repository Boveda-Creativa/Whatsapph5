<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WaLead extends Model
{
    use HasFactory;

    protected $table = 'wa_leads';

    protected $fillable = [
        'phone',
        'name',
        'event_type',
        'event_date',
        'people_count',
        'budget_range',
        'package_type',
        'alt_date',
        'customer_type',
        'source',
        'status',
        'score',
    ];

    protected $casts = [
        'event_date' => 'date',
        'alt_date' => 'date',
    ];

    public function conversation()
    {
        return $this->hasOne(WaConversation::class, 'lead_id');
    }
}