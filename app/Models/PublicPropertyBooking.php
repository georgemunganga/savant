<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicPropertyBooking extends Model
{
    use HasFactory;

    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_CONFIRMED,
        self::STATUS_CHECKED_IN,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'owner_user_id',
        'property_id',
        'option_id',
        'property_unit_id',
        'tenant_id',
        'user_id',
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
        'payment_plan',
        'status',
        'source',
        'account_created',
        'setup_email_sent',
        'has_assignment',
        'assignment_created',
        'confirmed_at',
    ];

    protected $casts = [
        'owner_user_id' => 'integer',
        'property_id' => 'integer',
        'option_id' => 'integer',
        'property_unit_id' => 'integer',
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'guests' => 'integer',
        'date_of_birth' => 'date',
        'is_student' => 'boolean',
        'account_created' => 'boolean',
        'setup_email_sent' => 'boolean',
        'has_assignment' => 'boolean',
        'assignment_created' => 'boolean',
        'confirmed_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(PublicPropertyOption::class, 'option_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(PropertyUnit::class, 'property_unit_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
