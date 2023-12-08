<?php

namespace FluentCampaign\App\Services\Funnel\Actions;

use FluentCrm\App\Models\Funnel;
use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;

class RemoveFromFunnelAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'remove_from_funnel';
        $this->priority = 108;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category' => __('CRM', 'fluentcampaign-pro'),
            'title'       => __('Cancel Automations', 'fluentcampaign-pro'),
            'description' => __('Pause/Cancel another automation for contact', 'fluentcampaign-pro'),
            'icon' => 'fc-icon-cancel_automation',//fluentCrmMix('images/funnel_icons/cancel_automation.svg'),
            'settings'    => [
                'automation_ids' => []
            ]
        ];
    }

    public function getBlockFields()
    {
        $automations = Funnel::get();
        return [
            'title'     => __('Cancel Automations', 'fluentcampaign-pro'),
            'sub_title' => __('Select which automations will be cancelled from the contact', 'fluentcampaign-pro'),
            'fields'    => [
                'automation_ids' => [
                    'label' => __('Select Automations that you want to cancel', 'fluentcampaign-pro'),
                    'type'        => 'multi-select',
                    'placeholder' => __('Select Automations', 'fluentcampaign-pro'),
                    'options' => $automations
                ]
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        if (empty($sequence->settings['automation_ids'])) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $automationIds = $sequence->settings['automation_ids'];
        foreach ($automationIds as $automationId) {
            FunnelSubscriber::where('subscriber_id', $subscriber->id)
                ->where('funnel_id', $automationId)
                ->update([
                    'status' => 'cancelled',
                    'notes' => __('Cancelled by Automation ID: ', 'fluentcampaign-pro').$sequence->funnel_id
                ]);
        }

        //FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id);
    }
}
