<?php

namespace FluentCampaign\App\Services\Funnel\Actions;

use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Services\Funnel\BaseAction;

class EndFunnel extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'end_this_funnel';
        $this->priority = 120;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category' => __('CRM', 'fluentcampaign-pro'),
            'title'       => __('End This Funnel Here', 'fluentcampaign-pro'),
            'description' => __('No further action will run once a contact hit this point', 'fluentcampaign-pro'),
            'icon' => 'fc-icon-end_funnel',//fluentCrmMix('images/funnel_icons/end_funnel.svg'),
            'settings'    => [
                'automation_ids' => []
            ]
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('End This Funnel Here', 'fluentcampaign-pro'),
            'sub_title' => __('This automation will be marked as completed in this point for a contact', 'fluentcampaign-pro'),
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        FunnelSubscriber::where('id', $funnelSubscriberId)
            ->update([
                'status' => 'completed'
            ]);
    }

}
