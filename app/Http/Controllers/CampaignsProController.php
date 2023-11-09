<?php

namespace FluentCampaign\App\Http\Controllers;

use FluentCrm\App\Http\Controllers\Controller;
use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\CampaignUrlMetric;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\Framework\Request\Request;

class CampaignsProController extends Controller
{
    public function resendFailedEmails(Request $request, $campaignId)
    {
        $failedCount = CampaignEmail::where('campaign_id', $campaignId)->where('status', 'failed')->count();

        if (!$failedCount) {
            return $this->sendError([
                'message' => __('Sorry no failed campaign emails found', 'fluentcampaign-pro')
            ]);
        }

        $campaign = Campaign::findOrFail($campaignId);

        CampaignEmail::where('campaign_id', $campaignId)->where('status', 'failed')
            ->update([
                'status'       => 'scheduled',
                'note'         => __('Added to resend from failed', 'fluentcampaign-pro'),
                'scheduled_at' => current_time('mysql')
            ]);

        $campaign->status = 'working';
        $campaign->save();

        return [
            'message' =>  sprintf(__('%d Emails has been scheduled to resend', 'fluentcampaign-pro'), $failedCount)
        ];
    }

    public function resendEmails(Request $request, $campaignId)
    {
        $campaign = Campaign::withoutGlobalScopes()->findOrFail($campaignId);
        $emailIds = $request->get('email_ids');
        if (!$emailIds) {
            return $this->sendError([
                'message' => __('Sorry! No emails found', 'fluentcampaign-pro')
            ]);
        }
        $emails = CampaignEmail::where('campaign_id', $campaignId)
            ->with('subscriber')
            ->whereIn('status', ['sent', 'failed'])
            ->whereIn('id', $emailIds)->get();

        if ($emails->isEmpty()) {
            return $this->sendError([
                'message' => __('Sorry! No emails found', 'fluentcampaign-pro')
            ]);
        }

        foreach ($emails as $email) {
            if (!$email->subscriber) {
                if(count($emails) == 1) {
                    return $this->sendError([
                        'message' => 'Sorry, email can not be sent as subscriber could not be found'
                    ]);
                }
                continue;
            }
            if (!$email->email_body) {
                $email->email_body = $campaign->email_body;
            }
            if (!$email->email_body) {
                if(count($emails) == 1) {
                    return $this->sendError([
                        'message' => 'Sorry, email can not be sent as email body is empty'
                    ]);
                }
                continue;
            }
            $email->status = 'scheduled';
            $email->is_parsed = 0;
            $email->note = 'Manually resent';
            $email->scheduled_at = current_time('mysql');
            $email->email_address = $email->subscriber->email;
            $email->save();
            do_action('fluentcrm_process_contact_jobs', $email->subscriber);
        }

        return [
            'message' => __('Email has been resent', 'fluentcampaign-pro')
        ];
    }

    public function doTagActions(Request $request, $campaignId)
    {
        $campaign = Campaign::findOrFail($campaignId);
        if ($campaign->status != 'archived') {
            return $this->sendError([
                'message' => __('You can do this action if campaign is in archived status only', 'fluentcampaign-pro')
            ]);
        }

        $this->validate($request->all(), [
            'action_type'     => 'required',
            'tags'            => 'required',
            'activity_type'   => 'required',
            'processing_page' => 'required|integer'
        ]);

        $actionType = $request->get('action_type');
        $tags = $request->get('tags');
        $activityType = $request->get('activity_type');
        $linkIds = $request->get('link_ids');

        $processingPage = intval($request->get('processing_page'));
        $limit = apply_filters('fluent_crm/campaign_action_limit', 50);
        $offset = ($processingPage - 1) * $limit;
        $subscriberIds = [];
        $count = false;
        // Let's filter our subscribers
        if ($activityType == 'email_clicked') {
            if(!$linkIds) {
                return $this->sendError([
                    'message' => __('Links are required', 'fluentcampaign-pro')
                ]);
            }

            if ($processingPage == 1) {
                $count = CampaignUrlMetric::where('campaign_id', $campaignId)
                    ->whereIn('url_id', $linkIds)
                    ->distinct()
                    ->count('subscriber_id');
            }

            $subscriberIds = CampaignUrlMetric::where('campaign_id', $campaignId)
                ->select('subscriber_id')
                ->whereIn('url_id', $linkIds)
                ->groupBy('subscriber_id')
                ->offset($offset)
                ->limit($limit)
                ->get()->pluck('subscriber_id')->toArray();

            $subscribers = Subscriber::whereIn('id', $subscriberIds)
                ->where('status', 'subscribed')
                ->get();
        }
        else if ($activityType == 'email_open' || $activityType == 'email_not_open') {
            $isOpenValue = 0;
            if($activityType == 'email_open') {
                $isOpenValue = 1;
            }

            $campaignEmailQuery = CampaignEmail::where('campaign_id', $campaignId)
                ->select('subscriber_id')
                ->groupBy('subscriber_id')
                ->where('is_open', $isOpenValue);
            if ($processingPage == 1) {
                $count = CampaignEmail::where('campaign_id', $campaignId)
                    ->where('is_open', $isOpenValue)
                    ->distinct()
                    ->count('subscriber_id');
            }

            $subscriberIds = $campaignEmailQuery->offset($offset)
                ->limit($limit)
                ->get()->pluck('subscriber_id')->toArray();

            $subscribers = Subscriber::whereIn('id', $subscriberIds)
                ->where('status', 'subscribed')
                ->get();
        }
        else {
            return $this->sendError([
                'message' => __('invalid selection', 'fluentcampaign-pro')
            ]);
        }

        if($actionType == 'add_tags') {
            foreach ($subscribers as $subscriber) {
                $subscriber->attachTags($tags);
            }
        } else if($actionType == 'remove_tags') {
            foreach ($subscribers as $subscriber) {
                $subscriber->detachTags($tags);
            }
        }

        $totalSubscribers = count($subscribers);

        return [
            'processed_page' => $processingPage,
            'processed_contacts' => $totalSubscribers,
            'has_more' => !!$totalSubscribers,
            'total_count' => $count,
            'subscriber_ids' => $subscriberIds
        ];
    }
}
