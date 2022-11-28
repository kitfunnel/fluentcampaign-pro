<?php

namespace FluentCampaign\App\Services\DynamicSegments;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\Framework\Support\Arr;

class WooCustomerSegment extends BaseSegment
{
    private $model = null;

    public $slug = 'wc_customers';

    public function getInfo()
    {
        return [
            'id'          => 0,
            'slug'        => $this->slug,
            'is_system'   => true,
            'title'       => __('WooCommerce Customers', 'fluentcampaign-pro'),
            'subtitle'    => __('WooCommerce customers who are also in the contact list as subscribed', 'fluentcampaign-pro'),
            'description' => __('This segment contains all your Subscribed contacts which are also your WooCommerce Customers', 'fluentcampaign-pro'),
            'settings'    => []
        ];
    }


    public function getCount()
    {
        return $this->getModel()->count();
    }

    public function getModel($segment = [])
    {
        if ($this->model) {
            return $this->model;
        }

        $query = Subscriber::where('status', 'subscribed');

        $subQuery = fluentCrmDb()
            ->table('wc_customer_lookup')
            ->join('fc_subscribers', 'fc_subscribers.email', '=', 'wc_customer_lookup.email')
            ->select('wc_customer_lookup.email');

        $this->model = $query->whereIn('email', $subQuery);


        return $this->model;
    }

    public function getSegmentDetails($segment, $id, $config)
    {
        $segment = $this->getInfo();

        if (Arr::get($config, 'model')) {
            $segment['model'] = $this->getModel($segment);
        }

        if (Arr::get($config, 'subscribers')) {
            $segment['subscribers'] = $this->getSubscribers($config);
        }

        if (Arr::get($config, 'contact_count')) {
            $segment['contact_count'] = $this->getCount();
        }

        return $segment;
    }
}
