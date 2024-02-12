<?php

namespace FluentCampaign\App\Models;

use FluentCrm\App\Models\Model;

class SmartLink extends Model
{
    protected $table = 'fc_smart_links';

    protected $guarded = ['id', 'short'];

    protected $fillable = ['title', 'actions', 'target_url', 'notes'];

    protected $appends = ['short_url'];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->created_by = $model->created_by ?: get_current_user_id();
            $model->short = self::getNextShortSlug();
        });
    }

    public function setActionsAttribute($actions)
    {
        $this->attributes['actions'] = \maybe_serialize($actions);
    }

    public function getActionsAttribute($actions)
    {
        return \maybe_unserialize($actions);
    }

    /**
     * Accessor to get dynamic photo attribute
     * @return string
     */
    public function getShortUrlAttribute()
    {
        if(!isset($this->attributes['short'])) {
            return '';
        }
        $slug = $this->attributes['short'];

        return add_query_arg([
            'fluentcrm' => 1,
            'route' => 'smart_url',
            'slug' => $slug
        ], site_url('/'));
    }

    public static function getNextShortSlug()
    {
        return \FluentCrm\App\Models\UrlStores::getStringByNumber(time() - 1611224846).mt_rand(1,9);
    }

}
