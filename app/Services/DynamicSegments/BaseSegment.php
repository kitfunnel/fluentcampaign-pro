<?php

namespace FluentCampaign\App\Services\DynamicSegments;

use FluentCrm\Framework\Support\Arr;

abstract class BaseSegment
{
    public $priority = 10;

    public $slug = '';

    public function __construct()
    {
        $this->register();
    }

    public function register()
    {
        add_filter('fluentcrm_dynamic_segments', function ($segments) {
            if ($segment = $this->getInfo()) {
                $segments[] = $segment;
            }
            return $segments;
        }, $this->priority);

        add_filter('fluentcrm_dynamic_segment_' . $this->slug, array($this, 'getSegmentDetails'), 10, 3);
    }

    abstract public function getInfo();

    public function getSubscribers($config = [], $segment = [])
    {
        $isPaginate = Arr::get($config, 'paginate');
        $segment['config'] = $config;
        if ($isPaginate) {
            return $this->getModel($segment)->paginate();
        }
        return $this->getModel($segment)->get();
    }

    abstract public function getSegmentDetails($segment, $segmentId, $settings);

    abstract public function getCount();

    abstract public function getModel($segment = []);
}
