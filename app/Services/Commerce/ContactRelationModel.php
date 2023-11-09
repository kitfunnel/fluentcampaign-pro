<?php

namespace FluentCampaign\App\Services\Commerce;

use FluentCrm\App\Models\Model;

class ContactRelationModel extends Model
{
    protected $table = 'fc_contact_relations';

    protected $guarded = ['id'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'subscriber_id',
        'provider',
        'provider_id',
        'first_order_date',
        'last_order_date',
        'total_order_count',
        'total_order_value',
        'status',
        'commerce_taxonomies',
        'commerce_coupons',
        'meta_col_1',
        'meta_col_2',
        'created_at'
    ];

    public function scopeProvider($query, $provider)
    {
        return $query->where('provider', $provider);
    }

    public function items()
    {
        return $this->hasMany(
            __NAMESPACE__ . '\ContactRelationItemsModel', 'relation_id', 'id'
        );
    }

    public function syncItems($items, $hasSubId = false, $hasOrigin = false)
    {
        $ids = [];
        foreach ($items as $item) {
            $item['provider'] = $this->provider;
            $item['relation_id'] = $this->id;
            $item['subscriber_id'] = $this->subscriber_id;

            $checkFields = [
                'relation_id'   => $item['relation_id'],
                'subscriber_id' => $item['subscriber_id'],
                'item_id'       => $item['item_id']
            ];

            if ($hasSubId) {
                $checkFields['item_sub_id'] = $item['item_sub_id'];
            }

            if ($hasOrigin) {
                $checkFields['origin_id'] = $item['origin_id'];
            }

            if(!empty($item['quantity']) && $item['quantity'] > 1) {
                $item['item_value'] = number_format($item['quantity'] * $item['item_value'], 2, '.', '');
            }

            $inserted = ContactRelationItemsModel::updateOrCreate($checkFields, $item);
            $ids[] = $inserted->id;
        }

        // delete unused items
        ContactRelationItemsModel::whereNotIn('id', $ids)
            ->where('provider', $this->provider)
            ->where('subscriber_id', $this->subscriber_id)
            ->delete();

        return $this;
    }

    public function addItem($item, $hasSubId = false, $hasOrigin = false)
    {
        $item['provider'] = $this->provider;
        $item['relation_id'] = $this->id;
        $item['subscriber_id'] = $this->subscriber_id;

        $checkFields = [
            'relation_id'   => $item['relation_id'],
            'subscriber_id' => $item['subscriber_id'],
            'item_id'       => $item['item_id']
        ];

        if ($hasSubId) {
            $checkFields['item_sub_id'] = $item['item_sub_id'];
        }

        if ($hasOrigin) {
            $checkFields['origin_id'] = $item['origin_id'];
        }

        return ContactRelationItemsModel::updateOrCreate($checkFields, $item);
    }

    public function recalculate($columns = [], $itemType = false, $extraValues = [])
    {
        if (!$columns) {
            $columns = ['first_order_date', 'last_order_date', 'total_order_count', 'total_order_value'];
        }
        
        $items = $this->items;

        $totalOrderCount = 0;
        $totalOrderValue = 0;
        $firstOrderDate = $this->first_order_date;
        $lastOrderDate = $this->last_order_date;

        foreach ($items as $item) {
            if ($itemType && $item->item_type != $itemType) {
                continue;
            }

            $totalOrderCount += 1;
            $totalOrderValue += $item->item_value;

            if (strtotime($item->created_at) < strtotime($firstOrderDate)) {
                $firstOrderDate = $item->created_at;
            }

            if (strtotime($item->created_at) > strtotime($lastOrderDate)) {
                $lastOrderDate = $item->created_at;
            }
        }

        if (in_array('first_order_date', $columns)) {
            $this->first_order_date = $firstOrderDate;
        }

        if (in_array('last_order_date', $columns)) {
            $this->last_order_date = $lastOrderDate;
        }

        if (in_array('total_order_count', $columns)) {
            $this->total_order_count = $totalOrderCount;
        }

        if (in_array('total_order_value', $columns)) {
            $this->total_order_value = $totalOrderCount;
        }

        if ($extraValues) {
            $this->fill($extraValues);
        }

        $this->save();

        return $this;
    }


    public function taxonomies()
    {
        return $this->hasManyThrough(
            '\FluentCampaign\App\Services\Commerce\ItemTaxonomyRelation',
            '\FluentCampaign\App\Services\Commerce\ContactRelationItemsModel',
            'relation_id',
            'object_id',  // term_relationships
            'id',
            'item_id'
        );
    }

}
