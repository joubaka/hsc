<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingSeries extends Model
{
    protected $table = 'booking_series';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'days_of_week' => 'array', 'monthly_pattern' => 'array', 'participant_template' => 'array', 'rules_snapshot' => 'array', 'pricing_strategy_snapshot' => 'array', 'cancellation_policy_snapshot' => 'array', 'lighting_requested' => 'boolean', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function occurrences()
    {
        return $this->hasMany(BookingSeriesOccurrence::class);
    }

    public function predecessor()
    {
        return $this->belongsTo(self::class, 'predecessor_series_id');
    }

    public function successor()
    {
        return $this->belongsTo(self::class, 'successor_series_id');
    }

    public function root()
    {
        return $this->belongsTo(self::class, 'root_series_id');
    }

    protected static function booted(): void
    {
        static::deleting(fn (self $series) => ($series->status === 'draft' && ! $series->occurrences()->exists()) ?: throw new \LogicException('Materialized or non-draft series cannot be deleted.'));
    }
}
