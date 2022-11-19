<?php

namespace FluentCampaign\App\Services\DynamicSegments;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\Framework\Support\Arr;

class WpUserSegment extends BaseSegment
{
    private $model = null;

    public $slug = 'wp_users';

    public function getInfo()
    {
        return [
            'id'          => 0,
            'slug'        => $this->slug,
            'is_system'   => true,
            'title'       => __('WordPress Users', 'fluentcampaign-pro'),
            'subtitle'    => __('WordPress users who are also in the contact list as subscribed', 'fluentcampaign-pro'),
            'description' => __('This segment contains all your Subscribed contacts which are also your WordPress users', 'fluentcampaign-pro'),
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

        $this->model = Subscriber::whereNotNull('user_id')
            ->where('status', 'subscribed');

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
