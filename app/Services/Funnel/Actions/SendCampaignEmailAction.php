<?php

namespace FluentCampaign\App\Services\Funnel\Actions;

use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;

class SendCampaignEmailAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'send_campaign_email';
        $this->priority = 17;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category' => __('Email', 'fluentcampaign-pro'),
            'title'       => __('Send Campaign Email', 'fluentcampaign-pro'),
            'description' => __('Send an Email from your existing campaign', 'fluentcampaign-pro'),
            'icon' => 'fc-icon-send_campaign',//fluentCrmMix('images/funnel_icons/send_campaign.svg'),
            'settings'    => [
                'campaign_id' => ''
            ]
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Schedule Campaign Email', 'fluentcampaign-pro'),
            'sub_title' => __('Select which campaign email will be scheduled to this contact', 'fluentcampaign-pro'),
            'fields'    => [
                'campaign_id' => [
                    'type'        => 'option_selectors',
                    'option_key' => 'campaigns',
                    'is_multiple' => false,
                    'label' => __('Select Campaign', 'fluentcampaign-pro'),
                    'placeholder' => __('Select Campaign Email', 'fluentcampaign-pro')
                ]
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        if (empty($sequence->settings['campaign_id'])) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $campaignId = intval($sequence->settings['campaign_id']);
        $campaign = Campaign::find($campaignId);
        if(!$campaign) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        // check if the contact already got the email
        $alreadyIn = CampaignEmail::where('campaign_id', $campaignId)
            ->where('subscriber_id', $subscriber->id)
            ->first();
        
        if($alreadyIn) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }
        
        $campaign->subscribe([$subscriber->id], [
            'status' => 'scheduled',
            'scheduled_at' => current_time('mysql'),
            'note' => __('Email has been triggered by Automation Funnel ID: ', 'fluentcampaign-pro').$sequence->funnel_id
        ]);
        //FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id);
        do_action('fluentcrm_process_contact_jobs', $subscriber);
    }
}
