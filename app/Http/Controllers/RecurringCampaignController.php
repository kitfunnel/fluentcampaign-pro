<?php

namespace FluentCampaign\App\Http\Controllers;

use FluentCampaign\App\Models\RecurringCampaign;
use FluentCampaign\App\Models\RecurringMail;
use FluentCampaign\App\Services\RecurringCampaignRunner;
use FluentCrm\App\Http\Controllers\Controller;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\Framework\Request\Request;

class RecurringCampaignController extends Controller
{
    public function getCampaigns(Request $request)
    {
        $order = $request->get('order') ?: 'desc';
        $orderBy = $request->get('orderBy') ?: 'id';

        $campaigns = RecurringCampaign::select([
            'id', 'title', 'settings', 'scheduled_at', 'created_at', 'status'
        ])
            ->orderBy($orderBy, $order);
        if (!empty($request->get('search'))) {
            $campaigns = $campaigns->where('title', 'LIKE', '%' . $request->get('search') . '%');
        }

        $campaigns = $campaigns->paginate();

        foreach ($campaigns as $campaign) {
            if($campaign->status == 'active' && Arr::get($campaign->settings, 'scheduling_settings.send_automatically') != 'yes') {
                $campaign->has_draft = !!RecurringMail::where('parent_id', $campaign->id)->where('status', 'draft')->first();
            }

            $campaign->emails_count = $campaign->getMailCampaignCounts();
        }

        return [
            'campaigns' => $campaigns
        ];
    }

    public function createCampaign(Request $request)
    {
        $campaignData = $request->getJson('campaign');

        $this->validate($campaignData, [
            'title'                             => 'required',
            'settings.scheduling_settings.time' => 'required',
            'settings.scheduling_settings.type' => 'required'
        ]);

        $campaignData['title'] = sanitize_text_field($campaignData['title']);

        if (RecurringCampaign::where('title', $campaignData['title'])->first()) {
            return $this->sendError([
                'message'    => __('Another campaign with the same name already exist. Please provide a different name', 'fluentcampaign-pro'),
                'go_to_step' => 0
            ]);
        }

        $data = [
            'title'    => $campaignData['title'],
            'settings' => [
                'scheduling_settings'  => Arr::get($campaignData, 'settings.scheduling_settings', []),
                'sending_conditions'   => Arr::get($campaignData, 'settings.sending_conditions', []),
                'subscribers_settings' => Arr::get($campaignData, 'settings.subscribers_settings', []),
            ]
        ];

        $createdCampaign = RecurringCampaign::create($data);

        return [
            'message'     => __('Recurring campaign has been created. Please setup the email contents now'),
            'campaign_id' => $createdCampaign->id
        ];
    }

    public function getCampaign(Request $request, $id)
    {
        $campaign = RecurringCampaign::findOrFail($id);

        return [
            'campaign' => $campaign
        ];
    }

    public function updateCampaignData(Request $request)
    {
        $campaign = RecurringCampaign::findOrFail($request->get('campaign_id'));

        $campaignData = $request->getJson('campaign');

        $this->validate($campaignData, [
            'email_body'    => 'required',
            'email_subject' => 'required'
        ]);

        $campaignData = Arr::only($campaignData, [
            'title',
            'email_subject',
            'email_body',
            'email_pre_header',
            'template_id',
            'utm_status',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'design_template',
            'settings'
        ]);

        $campaignData['scheduled_at'] = RecurringCampaignRunner::getNextScheduledAt($campaignData['settings']['scheduling_settings']);


        $campaign->fill($campaignData)->save();

        return [
            'message'  => __('Email data has been updated', 'fluentcampaign-pro'),
            'campaign' => $campaign
        ];
    }

    public function updateCampaignSettings(Request $request, $campaignId)
    {
        $campaign = RecurringCampaign::findOrFail($campaignId);
        $campaignData = $request->getJson('campaign');

        $campaignData['title'] = sanitize_text_field($campaignData['title']);

        if (RecurringCampaign::where('title', $campaignData['title'])->where('id', '!=', $campaign->id)->first()) {
            return $this->sendError([
                'message' => __('Another campaign with the same name already exist. Please provide a different name', 'fluentcampaign-pro'),
            ]);
        }

        $settings = Arr::get($campaignData, 'settings', []);

        $this->validate($campaignData, [
            'title'                             => 'required',
            'settings.scheduling_settings.time' => 'required',
            'settings.scheduling_settings.type' => 'required'
        ]);


        $campaign->settings = $settings;
        $campaign->title = $campaignData['title'];
        $campaign->scheduled_at = RecurringCampaignRunner::getNextScheduledAt($campaignData['settings']['scheduling_settings']);
        $campaign->save();

        RecurringCampaignRunner::setCalculatedScheduledAt();

        return [
            'message'  => __('Settings has been updated', 'fluentcampaign-pro'),
            'campaign' => $campaign
        ];
    }

    public function changeStatus(Request $request, $id)
    {
        $campaign = RecurringCampaign::findOrFail($id);
        $status = $request->get('status');

        if ($status != 'active') {
            $status = 'draft';
        }

        if ($status == 'active') {
            $this->validate([
                'email_subject' => $campaign->email_subject,
                'email_body' => $campaign->email_body
            ], [
                'email_subject' => 'required',
                'email_body' => 'required'
            ]);
        }

        $campaign->status = $status;
        $campaign->scheduled_at = RecurringCampaignRunner::getNextScheduledAt($campaign->settings['scheduling_settings']);

        $campaign->save();

        RecurringCampaignRunner::setCalculatedScheduledAt();

        return [
            'message'    => sprintf(__('Campaign status has been changed to %s', 'fluentcampaign-pro'), $status),
            'new_status' => $status,
            'campaign' => $campaign
        ];
    }

    public function duplicate(Request $request, $id)
    {
        $campaign = RecurringCampaign::findOrFail($id);

        $newData = [
            'title'            => __('[Duplicate] ', 'fluentcampaign-pro') . $campaign->title . ' @ ' . date('Y-m-d'),
            'settings'         => $campaign->settings,
            'template_id'      => $campaign->template_id,
            'email_subject'    => $campaign->email_subject,
            'email_pre_header' => $campaign->email_pre_header,
            'email_body'       => $campaign->email_body,
            'utm_status'       => $campaign->utm_status,
            'utm_source'       => $campaign->utm_source,
            'utm_medium'       => $campaign->utm_medium,
            'utm_campaign'     => $campaign->utm_campaign,
            'utm_term'         => $campaign->utm_term,
            'utm_content'      => $campaign->utm_content,
            'design_template'  => $campaign->design_template,
            'status'           => 'draft'
        ];

        $newCampaign = RecurringCampaign::create($newData);

        return [
            'campaign'    => $newCampaign,
            'campaign_id' => $newCampaign->id,
            'message'     => __('Selected Campaign has been successfully duplicated', 'fluentcampaign-pro')
        ];

    }

    public function deleteBulk(Request $request)
    {

        $campaignIds = $request->get('campaign_ids');

        if (!is_array($campaignIds) || empty($campaignIds)) {
            return $this->sendError([
                'message' => 'Please provide valid IDs'
            ]);
        }

        $campaigns = RecurringCampaign::whereIn('id', $campaignIds)->get();

        if ($campaigns->isEmpty()) {
            return $this->sendError([
                'message' => 'No campaigns found based on your request'
            ]);
        }

        foreach ($campaigns as $campaign) {
            // Delete the child campaigns
            $childCampaigns = RecurringMail::where('parent_id', $campaign->id);
            $childIds = [];
            foreach ($childCampaigns as $childCampaign) {
                $childIds[] = $childCampaign->id;
            }

            CampaignEmail::whereIn('campaign_id', $childIds)->delete();
            RecurringMail::whereIn('id', $childIds)->delete();

            foreach ($childIds as $childId) {
                do_action('fluent_crm/campaign_deleted', $childId);
            }

            $campaignId = $campaign->id;

            $campaign->delete();
            do_action('fluent_crm/campaign_deleted', $campaignId);
        }

        return $this->sendSuccess([
            'message' => __('Recurring Email campaign has been deleted', 'fluentcampaign-pro')
        ]);
    }

    public function getEmails(Request $request, $id)
    {
        $campaign = RecurringCampaign::findOrFail($id);

        $data = [
            'emails' => RecurringMail::where('parent_id', $id)->orderBy('id', 'DESC')->where('status', '!=', 'draft')->paginate()
        ];

        if ($request->get('page') == 1) {
            $data['drafts'] = RecurringMail::where('parent_id', $id)->orderBy('id', 'DESC')->where('status', 'draft')->get();
        }

        return $data;
    }

    public function getEmail(Request $request, $campaignId, $emailId)
    {
        $campaign = RecurringCampaign::findOrFail($campaignId);
        $campaignEmail = RecurringMail::findOrFail($emailId);

        if ($campaignEmail->status == 'scheduled' && $campaignEmail->scheduled_at) {
            if (strtotime($campaignEmail->scheduled_at) < current_time('timestamp')) {
                $campaignEmail->status = 'working';
                $campaignEmail->save();
            }
        }

        return [
            'campaign' => $campaign,
            'email'    => $campaignEmail
        ];
    }

    public function patchCampaignEmail(Request $request, $campaignId, $emailId)
    {
        $campaign = RecurringCampaign::findOrFail($campaignId);
        $campaignEmail = RecurringMail::findOrFail($emailId);

        $status = sanitize_text_field($request->get('status'));

        if ($status) {
            // Change Status here
            $changeFromStatuses = ['draft', 'cancelled'];
            $changeToStatuses = ['draft', 'cancelled'];

            if (in_array($campaignEmail->status, $changeFromStatuses) && in_array($status, $changeToStatuses)) {
                $campaignEmail->status = $status;
                $campaignEmail->save();
                return [
                    'message' => sprintf(__('Email status has been changed to %s', 'fluentcampaign-pro'), $status)
                ];
            }
        }

    }

    public function updateCampaignEmail(Request $request, $campaignId)
    {
        $campaign = RecurringCampaign::findOrFail($campaignId);
        $emailData = $request->getJson('email');
        $step = $request->get('step');

        $email = RecurringMail::where('parent_id', $campaign->id)->findOrFail($emailData['id']);

        if ($step == 'edit') {
            if (empty($emailData['email_body'])) {
                return $this->sendError([
                    'message' => __('Email body is required', 'fluentcampaign-pro')
                ]);
            }

            $email->email_body = $emailData['email_body'];
            $email->settings = $emailData['settings'];
            $email->design_template = $emailData['design_template'];
            $email->save();
            return [
                'message' => __('Email body has been successfully updated', 'fluentcampaign-pro')
            ];
        }

        if ($step == 'review') {
            $this->validate($emailData, [
                'email_subject' => 'required',
                'scheduled_at'  => 'required',
                'settings'      => 'required',
                'status'        => 'required'
            ]);

            if (strtotime($emailData['scheduled_at']) < current_time('timestamp')) {
                $emailData['scheduled_at'] = current_time('mysql');
            }

            if ($emailData['status'] == 'pending-scheduled' && $campaign->status != 'active') {
                return $this->sendError([
                    'message' => sprintf('Recurring campaign status is set to %s. You can not publish this email. Please make your recurring campaign status as active first.', $campaign->status)
                ]);
            }

            if ($emailData['status'] == 'pending-scheduled' && $email->status == 'draft') {
                fluentcrm_update_campaign_meta($campaign->id, '_recipient_processed', 0);
                fluentcrm_update_campaign_meta($campaign->id, '_last_recipient_id', 0);
            }

            $email->status = $emailData['status'];
            $email->email_subject = $emailData['email_subject'];
            $email->scheduled_at = $emailData['scheduled_at'];
            $email->settings = $emailData['settings'];
            $email->recipients_count = 0;
            $email->save();

            return [
                'message' => __('Settings has been successfully updated', 'fluentcampaign-pro')
            ];
        }

    }
}
