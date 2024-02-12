<?php

namespace FluentCampaign\App\Services\Integrations;

use FluentCrm\App\Services\Helper;

class Integrations
{
    public function init()
    {
        if (Helper::isCompanyEnabled()) {
            new \FluentCampaign\App\Services\Integrations\CRM\CompanyAppliedTrigger();
            new \FluentCampaign\App\Services\Integrations\CRM\RemoveFromCompanyTrigger();
        }

        new \FluentCampaign\App\Services\Integrations\CRM\ContactCreatedTrigger();
        new \FluentCampaign\App\Services\Integrations\CRM\ListAppliedTrigger();
        new \FluentCampaign\App\Services\Integrations\CRM\RemoveFromListTrigger();
        new \FluentCampaign\App\Services\Integrations\CRM\TagAppliedTrigger();
        new \FluentCampaign\App\Services\Integrations\CRM\RemoveFromTagTrigger();

        $this->initCrmAdvancedReports();


        // WooCommerce
        if (defined('WC_PLUGIN_FILE')) {
            (new \FluentCampaign\App\Services\Integrations\WooCommerce\WooInit())->init();
        }

        // Easy Digital Downloads
        if (class_exists('\Easy_Digital_Downloads')) {
            (new \FluentCampaign\App\Services\Integrations\Edd\EddInit())->init();
        }

        // AffiliateWP
        if (class_exists('\Affiliate_WP')) {
            new \FluentCampaign\App\Services\Integrations\AffiliateWP\AffiliateWPAffActiveTrigger();
        }

        // LifterLMS
        if (defined('LLMS_PLUGIN_FILE')) {
            (new \FluentCampaign\App\Services\Integrations\LifterLms\LifterInit())->init();
        }

        // LearnDash
        if (defined('LEARNDASH_VERSION')) {
            (new \FluentCampaign\App\Services\Integrations\LearnDash\LdInit())->init();
        }

        // PaidMembership Pro
        if (defined('PMPRO_VERSION')) {
            new \FluentCampaign\App\Services\Integrations\PMPro\PMProPMProMembershipTrigger();
            new \FluentCampaign\App\Services\Integrations\PMPro\PMProPMProExpiryLevelTrigger();
            new \FluentCampaign\App\Services\Integrations\PMPro\PMProPMProCancelLevelTrigger();
            new \FluentCampaign\App\Services\Integrations\PMPro\PMProImporter();
            (new \FluentCampaign\App\Services\Integrations\PMPro\AutomationConditions())->init();

            add_action('pmpro_after_change_membership_level', function ($level_id, $user_id, $cancel_level) {
                do_action('fcrm/pmpro_after_change_membership_level', $level_id, $user_id, $cancel_level);
            }, 10, 3);

        }

        // WishlistMember
        if (defined('WLM3_PLUGIN_VERSION')) {
            new \FluentCampaign\App\Services\Integrations\WishlistMember\WishlistMembershipTrigger();
            new \FluentCampaign\App\Services\Integrations\WishlistMember\WishlistMemberImporter();
            (new \FluentCampaign\App\Services\Integrations\WishlistMember\AutomationConditions())->init();
        }

        // MemberPress
        if (defined('MEPR_PLUGIN_NAME')) {
            new \FluentCampaign\App\Services\Integrations\MemberPress\MembershipTrigger();
            new \FluentCampaign\App\Services\Integrations\MemberPress\SubscriptionExpiredTrigger();
            new \FluentCampaign\App\Services\Integrations\MemberPress\MemberPressImporter();
        }

        if (class_exists('\Restrict_Content_Pro')) {
            new \FluentCampaign\App\Services\Integrations\RCP\RCPMembershipTrigger();
            new \FluentCampaign\App\Services\Integrations\RCP\RCPMembershipCancelTrigger();
            new \FluentCampaign\App\Services\Integrations\RCP\RCPMembershipExpireTrigger();
            new \FluentCampaign\App\Services\Integrations\RCP\RCPImporter();
            (new \FluentCampaign\App\Services\Integrations\RCP\AutomationConditions())->init();
        }

        /*
         * Pro Forms
         */
        if (defined('ELEMENTOR_VERSION') && defined('ELEMENTOR_PRO_VERSION')) {
            (new \FluentCampaign\App\Services\Integrations\ElementorFormIntegration\Bootstrap())->init();
        }

        /*
         * TutorLMS
         */
        if (defined('TUTOR_VERSION')) {
            (new \FluentCampaign\App\Services\Integrations\TutorLms\TutorLmsInit())->init();
        }

        /*
         * BuddyPress
         */
        if (defined('BP_REQUIRED_PHP_VERSION') && function_exists('\buddypress')) {
            (new \FluentCampaign\App\Services\Integrations\BuddyPress\BBInit())->init();
        }

        if (defined('LP_PLUGIN_FILE')) {
            (new \FluentCampaign\App\Services\Integrations\LearnPress\LearnPressInit())->init();
        }

        if (defined('SURECART_PLUGIN_FILE')) {
            (new \FluentCampaign\App\Services\Integrations\SureCart\SureCartInit())->register();
        }
    }

    private function initCrmAdvancedReports()
    {
        add_filter('fluent_crm/advanced_report_providers', function ($providers) {
            $providers['crm'] = [
                'title' => __('CRM', 'fluentcampaign-pro')
            ];
            return $providers;
        }, 9, 1);
    }
}
