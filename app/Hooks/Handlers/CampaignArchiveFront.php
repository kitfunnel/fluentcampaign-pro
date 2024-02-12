<?php

namespace FluentCampaign\App\Hooks\Handlers;

use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\Meta;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;

class CampaignArchiveFront
{
    public function register()
    {
        add_shortcode('fluent_crm_campaign_archives', array($this, 'handleShortcode'));
    }

    public function handleShortcode()
    {
        // Check if it's enabled or not
        $settings = Helper::getExperimentalSettings();
        if ($settings['campaign_archive'] != 'yes') {
            return sprintf('<p class="fc_front_error">%s</p>', __('Email Newsletter Archive features is not enabled', 'fluentcampaign-pro'));
        }

        if (isset($_GET['view_newsletter'])) {
            // This is our single newsletter
            $newsletterHash = sanitize_text_field($_GET['view_newsletter']);
            $campaign = $this->getCampaign($newsletterHash, $settings);
            if ($campaign) {
                return $this->renderSingleNewsletter($campaign);
            }

            return sprintf('<p class="fc_front_error">%s</p>', __('Email Newsletter could not be found', 'fluentcampaign-pro'));
        }

        $campaigns = $this->getRecentCampaigns($settings);

        if ($campaigns->isEmpty()) {
            return sprintf('<p class="fc_front_error">%s</p>', __('No published email newsletter found', 'fluentcampaign-pro'));
        }

        return $this->renderCampaigns($campaigns);
    }

    public function getRecentCampaigns($settings)
    {
        $campaigns = Campaign::where('status', 'archived')
            ->orderBy('scheduled_at', 'DESC');
        $max = ($settings['campaign_max_number'] <= 0 || $settings['campaign_max_number'] > 50) ? 50 : absint($settings['campaign_max_number']);
        if ($max) {
            $campaigns->limit($max);
        }

        if (!empty($settings['campaign_search'])) {
            $campaigns->where('title', 'LIKE', '%%' . $settings['campaign_search'] . '%%');
        }

        return $campaigns->get();
    }

    protected function renderCampaigns($campaigns)
    {
        $subscriber = fluentCrmApi('contacts')->getCurrentContact(true, true);

        $urlBase = apply_filters('fluent_crm/newsletter_single_permalink_base', get_the_permalink());

        $newsletters = [];
        foreach ($campaigns as $campaign) {
            $subject = $campaign->email_subject;
            if ($subscriber) {
                $subject = apply_filters('fluent_crm/parse_campaign_email_text', $subject, $subscriber);
            }
            $campaignHash = $campaign->getHash();

            $newsletters[] = [
                'title'          => apply_filters('fluent_crm/newsletter_list_title_single', $subject, $campaign),
                'formatted_date' => date(get_option('date_format'), strtotime($campaign->scheduled_at)),
                'date_time'      => $campaign->scheduled_at,
                'campaign_hash'  => $campaignHash,
                'permalink'      => add_query_arg([
                    'view_newsletter' => $campaignHash
                ], $urlBase)
            ];
        }

        if (!$newsletters) {
            return '';
        }

        $file = apply_filters('fluent_crm/newsletter_archive_template', FLUENTCAMPAIGN_PLUGIN_PATH . 'app/Views/all_newsletters.php');

        ob_start();
        require_once $file;
        return ob_get_clean();
    }

    protected function renderSingleNewsletter($campaign)
    {
        $subscriber = fluentCrmApi('contacts')->getCurrentContact(true, true);

        $subject = $campaign->email_subject;

        if ($campaign->design_template == 'raw_html' || $campaign->design_template == 'raw_classic') {
            $emailBody = $campaign->email_body;
        } else {
            $emailBody = do_blocks($campaign->email_body);
        }

        $emailBody = str_replace(['https://fonts.googleapis.com/css2', 'https://fonts.googleapis.com/css'], 'https://fonts.bunny.net/css', $emailBody);

        if ($subscriber) {
            $emailBody = apply_filters('fluent_crm/parse_campaign_email_text', $emailBody, $subscriber);
            $subject = apply_filters('fluent_crm/parse_campaign_email_text', $subject, $subscriber);
        }

        $templateConfig = wp_parse_args(Arr::get($campaign->settings, 'template_config', []), Helper::getTemplateConfig($campaign->design_template, false));

        $templateData = [
            'preHeader'   => '',
            'email_body'  => $emailBody,
            'footer_text' => '',
            'config'      => $templateConfig
        ];

        $emailBody = apply_filters(
            'fluent_crm/email-design-template-' . $campaign->design_template,
            $emailBody,
            $templateData,
            $emailBody,
            $subscriber
        );

        $emailBody = str_replace(['{{crm_global_email_footer}}', '{{crm_preheader_text}'], '', $emailBody);

        $newsletter = [
            'title'          => $subject,
            'content'        => $emailBody,
            'formatted_date' => date(get_option('date_format'), strtotime($campaign->scheduled_at)),
            'date_time'      => $campaign->scheduled_at,
        ];

        $newsletter = apply_filters('fluent_crm/newsletter_preview_data', $newsletter, $campaign);

        $file = apply_filters('fluent_crm/newsletter_single_template', FLUENTCAMPAIGN_PLUGIN_PATH . 'app/Views/single_newsletter.php');

        ob_start();
        require_once $file;
        return ob_get_clean();
    }

    protected function getCampaign($hash, $settings)
    {
        $campaignMeta = Meta::where('value', $hash)
            ->where('object_type', 'FluentCrm\App\Models\Campaign')
            ->where('key', '_campaign_hash')
            ->first();

        if (!$campaignMeta) {
            return false;
        }

        $campaign = Campaign::where('id', $campaignMeta->object_id)
            ->where('status', 'archived');

        if (!empty($settings['campaign_search'])) {
            $campaign->where('title', 'LIKE', '%%' . $settings['campaign_search'] . '%%');
        }

        return $campaign->first();
    }
}
