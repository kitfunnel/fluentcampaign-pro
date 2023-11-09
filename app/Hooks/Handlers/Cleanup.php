<?php

namespace FluentCampaign\App\Hooks\Handlers;

use FluentCampaign\App\Models\SequenceMail;
use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCampaign\App\Services\Commerce\ContactRelationItemsModel;
use FluentCampaign\App\Services\Commerce\ContactRelationModel;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\CampaignUrlMetric;
use FluentCrm\App\Models\Funnel;
use FluentCrm\Framework\Support\Arr;

class Cleanup
{
    public function deleteSequenceAssets($sequenceId)
    {
        $sequenceEmails = SequenceMail::where('parent_id', $sequenceId)
            ->get();

        SequenceMail::whereIn('id', $sequenceEmails->pluck('id')->toArray())->delete();

        foreach ($sequenceEmails as $sequenceEmail) {
            $this->deleteCampaignAssets($sequenceEmail->id);
        }
    }

    public function deleteCampaignAssets($campaignId)
    {
        CampaignEmail::where('id', $campaignId)->delete();
        CampaignUrlMetric::where('campaign_id', $campaignId)->delete();
    }

    public function deleteCommerceItems($subscriberIds)
    {
        if (Commerce::getEnabledModules() && Commerce::isMigrated(true)) {
            ContactRelationModel::whereIn('subscriber_id', $subscriberIds)->delete();
            ContactRelationItemsModel::whereIn('subscriber_id', $subscriberIds)->delete();
        }
    }

    public function routingDoiRedirect($config, $subscriber)
    {
        if (Arr::get($config, 'tag_based_redirect') == 'yes' && !empty(Arr::get($config, 'tag_redirects'))) {
            $tagRedirects = Arr::get($config, 'tag_redirects', []);
            $subscriberTags = [];
            foreach ($subscriber->tags as $tag) {
                $subscriberTags[] = $tag->id;
            }

            if (!$subscriberTags) {
                return $config;
            }

            foreach ($tagRedirects as $tagRedirect) {
                $targetTags = Arr::get($tagRedirect, 'field_key', []);
                if (!$targetTags || !array_intersect($subscriberTags, $targetTags)) {
                    continue;
                }

                $targetUrl = Arr::get($tagRedirect, 'field_value', []);

                if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
                    continue;
                }

                $config['after_confirmation_type'] = 'redirect';
                $config['after_conf_redirect_url'] = $targetUrl;

                return $config;
            }
        }

        return $config;
    }

    public function syncAutomationSteps($funnel)
    {
        $latestAction = \FluentCrm\App\Models\FunnelSequence::where('funnel_id', $funnel->id)
            ->orderBy('sequence', 'DESC')
            ->first();

        if (!$latestAction) {
            return new \WP_Error('no_action_found', __('No action found to sync', 'fluentcampaign-pro'));
        }

        $nextExecutionTime = date('Y-m-d H:i:s', current_time('timestamp') + 300); // 5 minutes from now

        \FluentCrm\App\Models\FunnelSubscriber::where('funnel_id', $funnel->id)
            ->with(['subscriber'])
            ->where('status', 'completed')
            ->whereHas('subscriber', function ($q) {
                $q->where('status', 'subscribed');
            })
            ->whereHas('last_sequence', function ($q) use ($latestAction) {
                $q->where('action_name', '!=', 'end_this_funnel')
                    ->where('id', '!=', $latestAction->id)
                    ->where('sequence', '<', $latestAction->sequence);
            })
            ->update([
                'status'              => 'active',
                'next_execution_time' => $nextExecutionTime,
                'next_sequence_id'    => NULL,
                'notes'               => 'Re-Synced manually at ' . current_time('mysql')
            ]);

        return true;
    }
}
