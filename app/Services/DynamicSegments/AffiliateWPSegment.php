<?php

namespace FluentCampaign\App\Services\DynamicSegments;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\Framework\Support\Arr;

class AffiliateWPSegment extends BaseSegment
{
    private $model = null;

    public $slug = 'affiliate_wp';

    public function getInfo()
    {
        return [
            'id'          => 0,
            'slug'        => $this->slug,
            'is_system'   => true,
            'title'       => __('Active Affiliates (AffiliateWP)', 'fluentcampaign-pro'),
            'subtitle' => __('Active Affiliates who are also in the contact list as subscribed', 'fluentcampaign-pro'),
            'description' => __('This segment contains all your Subscribed contacts which are also your active Affiliates', 'fluentcampaign-pro'),
            'settings'    => []
        ];
    }

    public function getCount()
    {
        return $this->getModel()->count();
    }

    public function getModel($segment = [])
    {
        if($this->model) {
            return $this->model;
        }

        $this->model = Subscriber::where('status', 'subscribed')
            ->has('affiliate_wp');

        return $this->model;
    }

    public function getSegmentDetails($segment, $id, $config)
    {
        $segment = $this->getInfo();
        if(Arr::get($config, 'subscribers')) {
            $segment['subscribers'] = $this->getSubscribers($config);
        }
        if(Arr::get($config, 'model')) {
            $segment['model'] = $this->getModel($segment);
        }

        if(Arr::get($config, 'contact_count')) {
            $segment['contact_count'] = $this->getCount();
        }

        return $segment;
    }
}
