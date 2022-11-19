<?php

namespace FluentCampaign\App\Hooks\Handlers;

use FluentCrm\Framework\Support\Arr;

class VisualEmailBuilderHandler
{
    protected $frameUrl = 'https://fluentcrm.com/builder/';
    protected $frameDomain = 'https://fluentcrm.com';

    protected $designId = 'visual_builder';

    public function register()
    {
        add_filter('fluent_crm/email_design_templates', array($this, 'pushVisualBuilder'));
        add_action('fluentcrm_global_appjs_loaded', array($this, 'loadComponentJs'));

        /*
         * Template Related Filters and Actions
         */
        add_filter('fluent_crm/editing_template_data', array($this, 'maybePushDesignConfig'), 10, 2);
        add_action('fluent_crm/email_template_created', array($this, 'maybeSaveDesignConfig'), 10, 2);
        add_action('fluent_crm/email_template_duplicated', array($this, 'maybeDuplicateDesignConfig'), 10, 2);
        add_action('fluent_crm/email_template_updated', array($this, 'maybeUpdateDesignConfig'), 10, 2);

        /*
         * Campaign Related Actions and Filters
         */
        add_action('fluent_crm/update_campaign_compose', array($this, 'maybeSaveCampaignDesignConfig'), 10, 2);
        add_filter('fluent_crm/campaign_data', array($this, 'maybePushCampaignDesignConfig'), 10);
        add_action('fluent_crm/campaign_duplicated', array($this, 'campaignDuplicated'), 10, 2);

        /*
         * Email Template placeholder
         */
        add_filter('fluentcrm_email-design-template-visual_builder', array($this, 'processTemplate'), 10, 4);
    }

    public function processTemplate($emailBody, $templateData, $campaign, $subscriber = false)
    {
        return $emailBody;
    }

    public function pushVisualBuilder($builders)
    {
        $builders['visual_builder'] = [
            'id'            => 'visual_builder',
            'label'         => __('Visual Builder', 'fluentcampaign-pro'),
            'image'         => fluentCrm('url.assets') . 'images/drag-drop.png',
            'config'        => [],
            'use_gutenberg' => false,
            'template_type' => 'custom_component',
            'component'     => 'VisualEmailBuilder'
        ];

        return $builders;
    }

    public function maybeSaveCampaignDesignConfig($data, $campaign)
    {
        if (empty($data['_visual_builder_design_string'])) {
            return false;
        }

        if ($campaign->design_template == $this->designId) {
            $design = json_decode($data['_visual_builder_design_string'], true);
            fluentcrm_update_campaign_meta($campaign->id, '_visual_builder_design', $design);
        }

        return true;
    }

    public function campaignDuplicated($newCampaign, $oldCampaign)
    {
        if ($newCampaign->design_template != $this->designId) {
            return false;
        }
        $design = fluentcrm_get_campaign_meta($oldCampaign->id, '_visual_builder_design', true);
        fluentcrm_update_campaign_meta($newCampaign->id, '_visual_builder_design', $design);
    }

    public function maybePushCampaignDesignConfig($campaign)
    {
        if ($campaign->design_template != $this->designId) {
            return $campaign;
        }
        $campaign->_visual_builder_design = fluentcrm_get_campaign_meta($campaign->id, '_visual_builder_design', true);

        return $campaign;
    }

    public function maybePushDesignConfig($templateData, $template)
    {
        if ($templateData['design_template'] != 'visual_builder') {
            return $templateData;
        }

        $templateData['_visual_builder_design'] = get_post_meta($template->ID, '_visual_builder_design', true);

        return $templateData;
    }

    public function maybeSaveDesignConfig($templateId, $templateData)
    {
        if ($templateData['design_template'] != 'visual_builder') {
            return false;
        }

        if (!empty($templateData['_visual_builder_design'])) {
            update_post_meta($templateId, '_visual_builder_design', $templateData['_visual_builder_design']);
        }
    }

    public function maybeDuplicateDesignConfig($newTemplateId, $oldTemplate)
    {
        if (get_post_meta($oldTemplate->ID, '_design_template', true) != 'visual_builder') {
            return false;
        }

        $design = get_post_meta($oldTemplate->ID, '_visual_builder_design', true);

        if ($design) {
            update_post_meta($newTemplateId, '_visual_builder_design', $design);
        }

    }

    public function maybeUpdateDesignConfig($data, $template)
    {
        if ($data['design_template'] != 'visual_builder') {
            delete_post_meta($template->ID, '_visual_builder_design');
            return false;
        }

        if (isset($data['_visual_builder_design'])) {
            update_post_meta($template->ID, '_visual_builder_design', $data['_visual_builder_design']);
        }
    }

    public function loadComponentJs()
    {
        wp_enqueue_script('fluentcrm_visual_editor', fluentCrmMix('admin/js/visual-editor.js'), array('jquery'), '1.0');
        wp_localize_script('fluentcrm_visual_editor', 'fcVisualVars', [
            'url'    => $this->frameUrl,
            'params' => $this->getEditorPref(),
            'editor_domain' => $this->frameDomain
        ]);
    }

    protected function getEditorPref()
    {
        $editorPrefs = [];
        if (defined('WC_PLUGIN_FILE')) {
            $editorPrefs[] = 'woo';
        }
        if (class_exists('\Easy_Digital_Downloads')) {
            $editorPrefs[] = 'edd';
        }
        if (defined('LLMS_PLUGIN_FILE')) {
            $editorPrefs[] = 'lifter';
        } else if (defined('LEARNDASH_VERSION')) {
            $editorPrefs[] = 'ld';
        } else if (defined('TUTOR_VERSION')) {
            $editorPrefs[] = 'tutor';
        } else if (defined('LP_PLUGIN_FILE')) {
            $editorPrefs[] = 'lp';
        }

        if (defined('PMPRO_VERSION')) {
            $editorPrefs[] = 'pmp';
        } else if (defined('WLM3_PLUGIN_VERSION')) {
            $editorPrefs[] = 'wl';
        } else if (defined('MEPR_PLUGIN_NAME')) {
            $editorPrefs[] = 'mp';
        } else if (class_exists('\Restrict_Content_Pro')) {
            $editorPrefs[] = 'rcp';
        } else if (class_exists('\Restrict_Content_Pro')) {
            $editorPrefs[] = 'rcp';
        }
        if (defined('BP_REQUIRED_PHP_VERSION')) {
            $editorPrefs[] = 'bbs';
        }

        $siteRegister = get_option('__fluentcrm_campaign_license');
        $hash = '';
        if ($siteRegister && is_array($siteRegister)) {
            $key = Arr::get($siteRegister, 'license_key');
            $hash = base64_encode(substr($key, 0, 26));
        }

        return [
            'site'        => urlencode(site_url('/')),
            'hash'        => $hash,
            'editor_pref' => implode('_', $editorPrefs)
        ];
    }
}
