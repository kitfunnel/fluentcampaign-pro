<?php

namespace FluentCampaign\App\Hooks\Handlers;

use FluentCampaign\App\Models\SequenceMail;
use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCampaign\App\Services\Commerce\ContactRelationItemsModel;
use FluentCampaign\App\Services\Commerce\ContactRelationModel;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\CampaignUrlMetric;
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
                if(!$targetTags || ! array_intersect($subscriberTags, $targetTags)) {
                    continue;
                }

                $targetUrl = Arr::get($tagRedirect, 'field_value', []);

                if ( ! filter_var( $targetUrl, FILTER_VALIDATE_URL ) ) {
                    continue;
                }

                $config['after_confirmation_type'] = 'redirect';
                $config['after_conf_redirect_url'] = $targetUrl;

                return $config;
            }
        }

        return $config;
    }
}
