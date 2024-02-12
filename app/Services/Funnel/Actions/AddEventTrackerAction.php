<?php

namespace FluentCampaign\App\Services\Funnel\Actions;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\Libs\Parser\Parser;
use FluentCrm\Framework\Support\Arr;

class AddEventTrackerAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'add_contact_event_tracker';
        $this->priority = 30;
        parent::__construct();
    }

    public function getBlock()
    {
        if (!Helper::isExperimentalEnabled('event_tracking')) {
            return false;
        }

        return [
            'category'    => __('CRM', 'fluentcampaign-pro'),
            'title'       => __('Add Event Tracking', 'fluentcampaign-pro'),
            'description' => __('Add Event Tracking for Contact', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-writing',
            'settings'    => [
                'is_unique' => 'yes',
                'event_key' => '',
                'title'     => '',
                'value'     => ''
            ]
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Add Event Tracking to Contact Profile', 'fluentcampaign-pro'),
            'sub_title' => __('Event Tracking for Contact to the Contact Profile', 'fluentcampaign-pro'),
            'fields'    => [
                'event_key' => [
                    'type'        => 'input-text',
                    'placeholder' => 'ex: my_event_name_key',
                    'label'       => __('Event Tracking Key', 'fluentcampaign-pro')
                ],
                'title'     => [
                    'type'          => 'input-text-popper',
                    'smart_codes'   => 'yes',
                    'placeholder'   => 'Human readable event name',
                    'context_codes' => 'yes',
                    'label'         => __('Event Tracking Title', 'fluentcampaign-pro')
                ],
                'value'     => [
                    'label'       => 'Event Value',
                    'type'        => 'input-text-popper',
                    'field_type'  => 'textarea',
                    'placeholder' => 'Details about this event or numeric value',
                ],
                'is_unique' => [
                    'type'        => 'yes_no_check',
                    'label'       => '',
                    'check_label' => 'Store as unique event. If enabled, it will store as unique for a contact and store the latest value only along with the counter.'
                ]
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        if (!Helper::isExperimentalEnabled('event_tracking')) {
            return false;
        }

        $settings = $sequence->settings;
        $subscriber->funnel_subscriber_id = $funnelSubscriberId;
        $title = apply_filters('fluent_crm/parse_campaign_email_text', Arr::get($settings, 'title'), $subscriber);
        $value = apply_filters('fluent_crm/parse_campaign_email_text', Arr::get($settings, 'value'), $subscriber);

        $eventAtts = [
            'event_key'  => Arr::get($settings, 'event_key'),
            'title'      => $title,
            'value'      => $value,
            'subscriber' => $subscriber
        ];

        FluentCrmApi('event_tracker')->track($eventAtts, Arr::get($settings, 'is_unique') == 'yes');

        return true;
    }

}
