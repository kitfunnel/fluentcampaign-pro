<?php

namespace FluentCampaign\App\Hooks\Handlers;

use FluentCampaign\App\Models\RecurringCampaign;
use FluentCampaign\App\Models\RecurringMail;
use FluentCampaign\App\Services\RecurringCampaignRunner;
use FluentCrm\App\Models\Campaign;
use FluentCrm\Framework\Support\Arr;

class RecurringCampaignHandler
{
    public function maybePushNewEmailDraft()
    {
        $nextRunTimeStamp = (int) get_option('_fc_next_recurring_campaign');

        if (!$nextRunTimeStamp) {
            return false;
        }

        if(function_exists('fluentCrmRunTimeCache')) {
            if(fluentCrmRunTimeCache('RecurringCampaignHandler')) {
                return false;
            }

            fluentCrmRunTimeCache('RecurringCampaignHandler', 'yes');
        }

        $currentTimeStamp = current_time('timestamp');

        // check if next run is within the next 35 minutes or not
        if ($nextRunTimeStamp - $currentTimeStamp > 2100) {
            // It's more than 35 minutes
            return false;
        }

        $compareTime = date('Y-m-d H:i:s', current_time('timestamp') + 2100);

        // Let's get the parent campaigns and create new email campaigns
        $campaigns = RecurringCampaign::orderBy('scheduled_at', 'ASC')
            ->where('status', 'active')
            ->where('scheduled_at', '<=', $compareTime)
            ->whereNotNull('scheduled_at')
            ->limit(10)
            ->get();

        if ($campaigns->isEmpty()) {
            RecurringCampaignRunner::setCalculatedScheduledAt();
            return false;
        }

        foreach ($campaigns as $campaign) {

            $scheduledAt = $campaign->scheduled_at;
            // check if the campaign has a draft already. If yes then skip that campaign processing
            if (!$this->willProcessCampaign($campaign)) {
                // THIS IS THE MAIN PROBLEM I THINK. WE HAVE TO SET THE NEXT SCHEDULED AT HERE
                $campaign->scheduled_at = RecurringCampaignRunner::getNextScheduledAt($campaign->settings['scheduling_settings'], 7200);
                if(!$campaign->scheduled_at) {
                    $campaign->scheduled_at = NULL;
                }

                $campaign->save();
                continue;
            }

            $status = 'draft';

            if (Arr::get($campaign->settings, 'scheduling_settings.send_automatically') == 'yes') {
                $status = 'pending-scheduled';
            }

            if (strtotime($scheduledAt) <= current_time('timestamp')) {
                $scheduledAt = current_time('mysql');
            }

            $campaignData = [
                'parent_id'        => $campaign->id,
                'title'            => 'Recurring email at ' . $scheduledAt,
                'email_subject'    => $campaign->email_subject,
                'email_pre_header' => $campaign->email_pre_header,
                'email_body'       => $campaign->email_body,
                'status'           => $status,
                'template_id'      => $campaign->template_id,
                'utm_status'       => $campaign->utm_status,
                'utm_source'       => $campaign->utm_source,
                'utm_medium'       => $campaign->utm_medium,
                'utm_campaign'     => $campaign->utm_campaign,
                'utm_term'         => $campaign->utm_term,
                'utm_content'      => $campaign->utm_content,
                'scheduled_at'     => $scheduledAt,
                'created_by'       => $campaign->created_by,
                'settings'         => [
                    'mailer_settings'     => Arr::get($campaign->settings, 'mailer_settings', []),
                    'subscribers'         => Arr::get($campaign->settings, 'subscribers_settings.subscribers', []),
                    'excludedSubscribers' => Arr::get($campaign->settings, 'subscribers_settings.excludedSubscribers', []),
                    'sending_filter'      => Arr::get($campaign->settings, 'subscribers_settings.sending_filter', ''),
                    'dynamic_segment'     => Arr::get($campaign->settings, 'subscribers_settings.dynamic_segment', ''),
                    'advanced_filters'    => Arr::get($campaign->settings, 'subscribers_settings.advanced_filters', ''),
                    'template_config'     => Arr::get($campaign->settings, 'template_config', []),
                    'footer_settings'     => Arr::get($campaign->settings, 'footer_settings', [])
                ],
                'design_template'  => $campaign->design_template
            ];

            $recurringMail = RecurringMail::create($campaignData);

            $campaign->scheduled_at = RecurringCampaignRunner::getNextScheduledAt($campaign->settings['scheduling_settings'], 7200);
            if(!$campaign->scheduled_at) {
                $campaign->scheduled_at = NULL;
            }

            $campaign->save();

            // Now we have to advance the scheduled_at of the current_campaign

            do_action('fluent_crm/recurring_mail_created', $recurringMail, $campaign); // this hook is not created yet
            if ($recurringMail->status = 'draft') {
                do_action('fluent_crm/recurring_mail_created_as_draft', $recurringMail, $campaign);
            }
        }

        // All Done. Let's set the next cursor timing
        RecurringCampaignRunner::setCalculatedScheduledAt();

        if(function_exists('fluentCrmRunTimeCache')) {
            fluentCrmRunTimeCache('RecurringCampaignHandler', false);
        }
    }

    public function willProcessCampaign($campaign)
    {
        $exist = RecurringMail::whereIn('status', ['draft', 'processing', 'pre-scheduled', 'scheduled', 'working'])
            ->where('parent_id', $campaign->id)
            ->first();

        if ($exist) {
            return false;
        }

        $cutup = 64800; // 18 hours

        $frequency = Arr::get($campaign->settings, 'scheduling_settings.type', 'daily');

        if($frequency == 'daily') {
            $cutup = 64800; // 18 hours
        } elseif ($frequency == 'weekly') {
            $cutup = 6 * 86400; // 6 days
        } elseif ($frequency == 'monthly') {
            $cutup = 27 * 86400; // 27 days
        }
        
        // check if there has any created campaign email in the last 16 hours
        $exist = RecurringMail::where('parent_id', $campaign->id)
            ->where('created_at', '>=', date('Y-m-d H:i:s', current_time('timestamp') - $cutup))
            ->first();

        if($exist) {
            return false;
        }

        $subscribersSettings = [
            'mailer_settings'     => Arr::get($campaign->settings, 'mailer_settings', []),
            'subscribers'         => Arr::get($campaign->settings, 'subscribers_settings.subscribers', []),
            'excludedSubscribers' => Arr::get($campaign->settings, 'subscribers_settings.excludedSubscribers', []),
            'sending_filter'      => Arr::get($campaign->settings, 'subscribers_settings.sending_filter', ''),
            'dynamic_segment'     => Arr::get($campaign->settings, 'subscribers_settings.dynamic_segment', ''),
            'advanced_filters'    => Arr::get($campaign->settings, 'subscribers_settings.advanced_filters', ''),
            'template_config'     => Arr::get($campaign->settings, 'template_config', [])
        ];

        $count = (new Campaign())->getSubscriberIdsCountBySegmentSettings($subscribersSettings);

        if (!$count) {
            return false;
        }

        $conditions = Arr::get($campaign->settings, 'sending_conditions', []);

        if (!$conditions) {
            return true;
        }

        $hadCondition = false;

        foreach ($conditions as $conditionBlock) {
            if (empty($conditionBlock)) {
                continue;
            }

            $hadCondition = true;

            $isPassed = true;
            foreach ($conditionBlock as $condition) {
                $isPassed = RecurringCampaignRunner::assesCondition($condition);
                if (!$isPassed) {
                    break;
                }
            }

            if ($isPassed) {
                return true;
            }
        }

        if (!$hadCondition) {
            return true;
        }

        return false;
    }

    public function draftMailCreated($recurringMail, $campaign)
    {
        $globalSettings = fluentcrmGetGlobalSettings('business_settings');

        $adminEmail = Arr::get($globalSettings, 'admin_email', '');

        if(!$adminEmail) {
            return;
        }

        $adminEmail = str_replace('{{wp.admin_email}}', get_bloginfo('admin_email'), $adminEmail);

        if(!$adminEmail) {
            return;
        }

        $campaignUrl = fluentcrm_menu_url_base() . 'email/recurring-campaigns/emails/' . $campaign->id . '/history';

        // construct the email
        $subject = sprintf(__('New recurring email has been created in your site %s', 'fluentcampaign-pro'), get_bloginfo('name'));

        $line1 = sprintf('A manual recurring email campaign has been created in your site %s.', get_bloginfo('name'));

        $message = '<p style="font-family: Arial, sans-serif; font-size: 16px; line-height: 22px; color: #333333;">Hello There,</p>';
        $message .= '<p style="font-family: Arial, sans-serif; font-size: 16px; line-height: 22px; color: #333333;">' . $line1 . '</p>';
        $message .= '<p style="font-family: Arial, sans-serif; font-size: 16px; line-height: 18px; color: #333333;"><b>Campaign Name:</b> '.$campaign->title.'</p>';
        $message .= '<p style="font-family: Arial, sans-serif; font-size: 16px; line-height: 18px; color: #333333;"><b>Email Subject:</b> '.$recurringMail->email_subject.'</p>';
        $message .= '<p style="font-family: Arial, sans-serif; font-size: 16px; line-height: 22px; color: #333333;">Please click on the button below to review and approve the campaign:</p>';
        $message .= '<p style="text-align: center; margin-top: 20px; margin-bottom: 20px;">
                    <a href="' . $campaignUrl . '" target="_blank" style="display: inline-block;color: #ffffff; background-color: #a245ff; font-size: 16px; border-radius: 5px; text-decoration: none; font-weight: normal; font-style: normal; padding: 0.8rem 1rem; border-color: #0072ff;">Review and Schedule Campaign</a>
                </p>';

        $headers = array('Content-Type: text/html; charset=UTF-8');
        $message = $this->withTemplate([
            'body'  => $message,
            'show_footer' => true,
            'pre_header' => 'New draft recurring mail is available'
        ]);

        // send the email
        \wp_mail($adminEmail, $subject, $message, $headers);
    }


    private function withTemplate($data)
    {
        extract($data, EXTR_SKIP);
        $file = apply_filters('fluent_crm/notification_template_file', FLUENTCAMPAIGN_PLUGIN_PATH . 'app/Views/generic_notification.php');

        ob_start();
        require_once $file;
        return ob_get_clean();
    }

}
