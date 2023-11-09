<?php

namespace FluentCampaign\App\Services\Funnel\Actions;

use FluentCampaign\App\Models\Sequence;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;

class RemoveFromEmailSequenceAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'remove_from_email_sequence';
        $this->priority = 17;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category' => __('Email', 'fluentcampaign-pro'),
            'title'       => __('Cancel Sequence Emails', 'fluentcampaign-pro'),
            'description' => __('Cancel Sequence Emails for the contact', 'fluentcampaign-pro'),
            'icon' => 'fc-icon-cancel_sequence',//fluentCrmMix('images/funnel_icons/cancel_sequence.svg'),
            'settings'    => [
                'sequence_ids' => []
            ]
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Remove Email Sequences', 'fluentcampaign-pro'),
            'sub_title' => __('Select which sequences will be removed from this contact', 'fluentcampaign-pro'),
            'fields'    => [
                'sequence_ids' => [
                    'type'        => 'option_selectors',
                    'option_key' => 'email_sequences',
                    'is_multiple' => true,
                    'placeholder' => __('Select Sequences', 'fluentcampaign-pro')
                ]
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        if (empty($sequence->settings['sequence_ids'])) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $sequenceIds = $sequence->settings['sequence_ids'];
        foreach ($sequenceIds as $sequenceId) {
            $sequenceModel = Sequence::find($sequenceId);
            if($sequenceModel) {
                $sequenceModel->unsubscribe([$subscriber->id], 'Cancelled by Automation Funnel ID: '.$sequence->funnel_id);
            }
        }
        //FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id);
    }
}
