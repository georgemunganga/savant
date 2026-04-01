<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicPropertyWaitlist extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'option_id',
        'stay_mode',
        'start_date',
        'end_date',
        'guests',
        'full_name',
        'email',
        'phone',
        'status',
    ];

    protected $casts = [
        'option_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'guests' => 'integer',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(PublicPropertyOption::class, 'option_id');
    }
}
