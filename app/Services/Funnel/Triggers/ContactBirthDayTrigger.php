<?php

namespace FluentCampaign\App\Services\Funnel\Triggers;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;

class ContactBirthDayTrigger extends BaseTrigger
{

    public function __construct()
    {
        $this->triggerName = 'fluentcrm_contact_birthday';
        $this->priority = 25;
        $this->actionArgNum = 1;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('CRM', 'fluentcampaign-pro'),
            'label'       => __('Contact\'s Birthday', 'fluentcampaign-pro'),
            'description' => __('Funnel will be initiated on the day of contact\'s birthday', 'fluentcampaign-pro'),
            'icon'        => 'el-icon-present',
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('Contact\'s Birthday', 'fluentcampaign-pro'),
            'sub_title' => __('Funnel will be initiated on the day of contact\'s birthday', 'fluentcampaign-pro'),
            'fields'    => [
                'subscription_status_info' => [
                    'type' => 'html',
                    'info' => '<b>' . __('This automation will be initiated for contact on his/her birthday. Will only initiated only for subscribed status contacts', 'fluentcampaign-pro') . '</b>'
                ]
            ]
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [];
    }

    public function handle($funnel, $originalArgs)
    {
        $subscriber = $originalArgs[0];

        if ($subscriber->status != 'subscribed') {
            return false;
        }

        /*
         * Remove if already exist
         */
        FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);

        (new FunnelProcessor())->startFunnelSequence($funnel, [], [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $subscriber->id
        ], $subscriber);
    }
}
