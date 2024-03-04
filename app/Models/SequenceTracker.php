<?php

namespace FluentCampaign\App\Models;

use FluentCrm\App\Models\Model;

class SequenceTracker extends Model
{
    protected $table = 'fc_sequence_tracker';

    protected $guarded = ['id'];

    protected static $type = 'sequence_tracker';

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->status = $model->status ?: 'active';
            $model->type = self::$type;
        });
        static::addGlobalScope('type', function ($builder) {
            $builder->where('type', '=', self::$type);
        });
    }

    public function scopeOfType($query, $status)
    {
        return $query->where('status', $status);
    }


    /**
     * One2One: sequence_tracker belongs to one Sequence
     * @return \WPManageNinja\WPOrm\Relation\BelongsTo
     */
    public function sequence()
    {
        return $this->belongsTo(
            __NAMESPACE__ . '\Sequence', 'campaign_id', 'id'
        );
    }


    /**
     * One2One: sequence_tracker belongs to one Subscriber
     * @return \WPManageNinja\WPOrm\Relation\BelongsTo
     */
    public function subscriber()
    {
        return $this->belongsTo(
            '\FluentCrm\App\Models\Subscriber', 'subscriber_id', 'id'
        );
    }

    public function last_sequence()
    {
        return $this->belongsTo(
            __NAMESPACE__ . '\SequenceMail', 'last_sequence_id', 'id'
        );
    }

    public function next_sequence()
    {
        return $this->belongsTo(
            __NAMESPACE__ . '\SequenceMail', 'next_sequence_id', 'id'
        );
    }

    public function scopeOfNextTrackers($query)
    {
        return $query->where('status', 'active')
                     ->where('next_execution_time', '<=', current_time('mysql'));
    }

    public function setNotesAttribute($notes)
    {
        $this->attributes['notes'] = \maybe_serialize($notes);
    }

    public function getNotesAttribute($notes)
    {
        return \maybe_unserialize($notes);
    }

}
