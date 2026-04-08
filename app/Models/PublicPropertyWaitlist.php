<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicPropertyWaitlist extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONTACTED,
        self::STATUS_CONVERTED,
        self::STATUS_CLOSED,
    ];

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
        'date_of_birth',
        'nationality_country_id',
        'id_type',
        'id_number',
        'occupation',
        'is_student',
        'year_of_study',
        'status',
    ];

    protected $casts = [
        'option_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'guests' => 'integer',
        'date_of_birth' => 'date',
        'is_student' => 'boolean',
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
