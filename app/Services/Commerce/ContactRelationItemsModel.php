<?php

namespace FluentCampaign\App\Services\Commerce;

use FluentCrm\App\Models\Model;

class ContactRelationItemsModel extends Model
{
    protected $table = 'fc_contact_relation_items';

    protected $guarded = ['id'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'subscriber_id',
        'provider',
        'relation_id',
        'origin_id',
        'item_id',
        'item_sub_id',
        'item_value',
        'status',
        'item_type',
        'meta_col',
        'created_at'
    ];

    public function scopeProvider($query, $provider)
    {
        return $query->where('provider', $provider);
    }

    public function subscriber()
    {
        return $this->belongsTo(
            '\FluentCrm\App\Models\Subscriber', 'subscriber_id', 'id'
        );
    }

    public function taxonomies()
    {
        return $this->hasMany('\FluentCampaign\App\Services\Commerce\ItemTaxonomyRelation', 'object_id', 'item_id');
    }
}
