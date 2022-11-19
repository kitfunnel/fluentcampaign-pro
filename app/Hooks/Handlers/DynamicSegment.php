<?php

namespace FluentCampaign\App\Hooks\Handlers;

use FluentCampaign\App\Services\DynamicSegments\AffiliateWPSegment;
use FluentCampaign\App\Services\DynamicSegments\CustomSegment;
use FluentCampaign\App\Services\DynamicSegments\EddActiveCustomerSegment;
use FluentCampaign\App\Services\DynamicSegments\PMProMembersSegment;
use FluentCampaign\App\Services\DynamicSegments\WooCustomerSegment;
use FluentCampaign\App\Services\DynamicSegments\WpUserSegment;
use FluentCrm\Framework\Support\Arr;

class DynamicSegment
{
    public function init()
    {
        new WpUserSegment();

        if (class_exists('\Affiliate_WP')) {
            new AffiliateWPSegment();
        }

        if (class_exists('\Easy_Digital_Downloads')) {
            new EddActiveCustomerSegment();
        }

        if (defined('WC_PLUGIN_FILE')) {
            new WooCustomerSegment();
        }

        if (defined('PMPRO_VERSION')) {
            new PMProMembersSegment();
        }

        new CustomSegment();

        add_filter('fluentcrm_segment_paginate_contact_ids', array($this, 'getContactIds'), 10, 2);
    }

    public function getContactIds($data, $settings)
    {
        $data = [
            'subscriber_ids' => [],
            'total_count'    => 0
        ];

        $slug = Arr::get($settings, 'slug');
        $segmentId = Arr::get($settings, 'id');

        $segment = apply_filters('fluentcrm_dynamic_segment_' . $slug, [], $segmentId, [
            'paginate'    => false,
            'subscribers' => false,
            'model'       => true,
            'contact_count' => true
        ]);

        if (!$segment) {
            return $data;
        }

        $data['total_count'] = $segment['contact_count'];

        $model = $segment['model'];

        if ($limit = Arr::get($settings, 'limit')) {
            $model->limit($limit);
            $model->offset(Arr::get($settings, 'offset'));
        }

        $subscriberIds = $model->get()->pluck('id')->toArray();

        $data['subscriber_ids'] = $subscriberIds;

        return $data;
    }
}
