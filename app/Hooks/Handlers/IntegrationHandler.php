<?php

namespace FluentCampaign\App\Hooks\Handlers;

use FluentCampaign\App\Services\Funnel\Actions\AddActivityAction;
use FluentCampaign\App\Services\Funnel\Actions\AddEmailSequenceAction;
use FluentCampaign\App\Services\Funnel\Actions\AddEventTrackerAction;
use FluentCampaign\App\Services\Funnel\Actions\ChangeUserRoleAction;
use FluentCampaign\App\Services\Funnel\Actions\EndFunnel;
use FluentCampaign\App\Services\Funnel\Actions\HTTPSendDataAction;
use FluentCampaign\App\Services\Funnel\Actions\RemoveFromEmailSequenceAction;
use FluentCampaign\App\Services\Funnel\Actions\RemoveFromFunnelAction;
use FluentCampaign\App\Services\Funnel\Actions\SendCampaignEmailAction;
use FluentCampaign\App\Services\Funnel\Actions\UpdateContactPropertyAction;
use FluentCampaign\App\Services\Funnel\Actions\UpdateUserMetaAction;
use FluentCampaign\App\Services\Funnel\Actions\UserRegistrationAction;
use FluentCampaign\App\Services\Funnel\Benchmarks\EmailSequenceCompletedBenchmark;
use FluentCampaign\App\Services\Funnel\Benchmarks\LinkClickBenchmark;
use FluentCampaign\App\Services\Funnel\Conditions\FunnelABTesting;
use FluentCampaign\App\Services\Funnel\Conditions\FunnelCondition;
use FluentCampaign\App\Services\Funnel\Triggers\ContactBirthDayTrigger;
use FluentCampaign\App\Services\Funnel\Triggers\TrackingEventRecordedTrigger;
use FluentCampaign\App\Services\Funnel\Triggers\UserLoginTrigger;
use FluentCampaign\App\Services\Integrations\Integrations;
use FluentCrm\App\Models\Subscriber;
use FluentCampaign\App\Services\Funnel\Actions\UserRoleRemoveAction;
use FluentCrm\App\Services\AutoSubscribe;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class IntegrationHandler
{
    public function init()
    {
        $this->initTriggers();
        $this->initAddons();
        $this->initFunnelActions();
        $this->initBenchmarks();
        $this->initConditionals();

        $this->initBirthDayFireHooks();

    }

    private function initAddons()
    {
        (new Integrations())->init();;
    }

    private function initConditionals()
    {
        (new FunnelCondition())->register();
        (new FunnelABTesting())->register();
    }

    private function initFunnelActions()
    {
        new AddEmailSequenceAction();
        new RemoveFromEmailSequenceAction();
        new SendCampaignEmailAction();
        new RemoveFromFunnelAction();
        new EndFunnel();
        new  UpdateContactPropertyAction();
        new  UserRegistrationAction();
        new  UpdateUserMetaAction();
        new  ChangeUserRoleAction();
        new  HTTPSendDataAction();
        new  AddActivityAction();
        new UserRoleRemoveAction();
        new AddEventTrackerAction();
    }

    private function initBenchmarks()
    {
        new LinkClickBenchmark();
        new EmailSequenceCompletedBenchmark();
    }

    public function maybeAutoAlterTags($userId, $newRole)
    {
        if (is_multisite()) {
            if (is_network_admin()) {
                return false;
            }
            if (function_exists('WP_Ultimo')) {
                return false;
            }
        }

        $settings = fluentcrm_get_option('role_based_tagging_settings', []);
        if (!$settings || Arr::get($settings, 'status') != 'yes' || !$tagMappings = Arr::get($settings, 'tag_mappings.' . $newRole)) {
            return false;
        }

        if (empty($tagMappings['add_tags']) && empty($tagMappings['remove_tags'])) {
            return false;
        }

        $user = get_user_by('ID', $userId);
        $subscriber = Subscriber::where('user_id', $userId)
            ->orWhere('email', $user->user_email)
            ->first();

        if (!$subscriber) {
            $subscriberData = FunnelHelper::prepareUserData($userId);
            $subscriber = FunnelHelper::createOrUpdateContact($subscriberData);
        } else {
            $subscriber->user_id = $user->ID;
            $subscriber->save();
        }

        if (!$subscriber) {
            return false;
        }

        $tagToBeAdded = Arr::get($tagMappings, 'add_tags', []);
        $tagsToBeRemoved = Arr::get($tagMappings, 'remove_tags', []);

        if ($tagToBeAdded) {
            $subscriber->attachTags($tagToBeAdded);
        }

        if ($tagsToBeRemoved) {
            $subscriber->detachTags($tagsToBeRemoved);
        }

        return true;
    }

    public function initTriggers()
    {
        new UserLoginTrigger();
        new ContactBirthDayTrigger();
        new TrackingEventRecordedTrigger();
    }

    public function initBirthDayFireHooks()
    {
        add_action('fluentcrm_check_daily_birthday', [$this, 'runBirthDayAction']);
        add_action('fluentcrm_check_daily_birthday_once', [$this, 'runBirthDayAction']);

        /*
         * Register missed daily CRON hook
         */
        add_action('fluentcrm_loading_app', function () {
            if (!wp_next_scheduled('fluentcrm_check_daily_birthday')) {
                wp_schedule_event(time(), 'daily', 'fluentcrm_check_daily_birthday');
            }
        });
    }

    public function runBirthDayAction()
    {

        $triggers = get_option('fluentcrm_funnel_settings', []);

        if (!$triggers || !in_array('fluentcrm_contact_birthday', $triggers)) {
            if (!apply_filters('fluent_crm/enable_force_birthday_trigger', false)) {
                return false;
            }
        }

        // check the last completed run
        $lastRunDateTime = fluentcrm_get_option('_last_bday_last_run');
        if ($lastRunDateTime) {
            if (date('Ymd') == date('Ymd', strtotime($lastRunDateTime))) {
                return false; // We don't want to run this at the same date
            }
        }
        
        $subscribers = $this->getBirthDaySubscribers();
        if (!$subscribers) {
            fluentcrm_update_option('_last_bday_last_run', date('Y-m-d H:i:s'));
            return false;
        }

        $startTime = time();

        $hasMore = true;
        $run = true;
        while ($subscribers && $run) {
            $lastId = '';
            foreach ($subscribers as $subscriber) {
                do_action('fluentcrm_contact_birthday', $subscriber);
                $lastId = $subscriber->id;
            }
            fluentcrm_update_option('_last_bday_contact_id', $lastId);

            if (fluentCrmIsMemoryExceeded() || time() - $startTime > 40) {
                $run = false;
                $hasMore = true;
            } else {
                $subscribers = $this->getBirthDaySubscribers();
                if (!$subscribers) {
                    $run = false;
                    $hasMore = false;
                }
            }
        }

        if ($hasMore) {
            // run again after 30 seconds
            wp_schedule_single_event(time() + 30, 'fluentcrm_check_daily_birthday_once');
        } else {
            fluentcrm_update_option('_last_bday_last_run', date('Y-m-d H:i:s'));
        }

        return $hasMore;
    }

    private function getBirthDaySubscribers($limit = 30)
    {
        $lastContactId = fluentcrm_get_option('_last_bday_contact_id');

        $timestamp = current_time('timestamp');
        $day = date('d', $timestamp);
        $month = date('m', $timestamp);

        $subscribersQuery = \FluentCrm\App\Models\Subscriber::where('status', 'subscribed')
            ->whereRaw("DAY(date_of_birth) = {$day} AND MONTH(date_of_birth) = {$month}")
            ->orderBy('id', 'ASC')
            ->limit($limit);

        if ($lastContactId) {
            $subscribersQuery->where('id', '>', $lastContactId);
        }

        $subscribers = $subscribersQuery->get();

        if ($subscribers->isEmpty()) {
            fluentcrm_update_option('_last_bday_contact_id', '');
            return false;
        }

        return $subscribers;

    }


    public function maybeFillUpWooCheckoutFields($fields)
    {
        $settings = (new AutoSubscribe())->getWooCheckoutSettings();
        
        if (!$settings || $settings['auto_checkout_fill'] != 'yes') {
            return $fields;
        }

        if (is_user_logged_in()) {
            return $fields;
        }

        $subscriber = fluentcrm_get_current_contact();

        if (!$subscriber) {
            return $fields;
        }

        if ($wpUser = $subscriber->getWpUser()) {
            $customerData = [
                'billing' => array_filter([
                    'billing_first_name' => ($wpUser->first_name) ? $wpUser->first_name : $subscriber->first_name,
                    'billing_last_name'  => ($wpUser->last_name) ? $wpUser->last_name : $subscriber->last_name,
                    'billing_email'      => $wpUser->user_email,
                    'billing_company'    => get_user_meta($wpUser->ID, 'billing_company', true),
                    'billing_address_1'  => get_user_meta($wpUser->ID, 'billing_address_1', true),
                    'billing_address_2'  => get_user_meta($wpUser->ID, 'billing_address_2', true),
                    'billing_city'       => get_user_meta($wpUser->ID, 'billing_city', true),
                    'billing_state'      => get_user_meta($wpUser->ID, 'billing_state', true),
                    'billing_postcode'   => get_user_meta($wpUser->ID, 'billing_postcode', true),
                    'billing_country'    => get_user_meta($wpUser->ID, 'billing_country', true),
                    'billing_phone'      => get_user_meta($wpUser->ID, 'billing_phone', true),
                ])
            ];
        } else {
            $customerData = [
                'billing' => array_filter([
                    'billing_first_name' => $subscriber->first_name,
                    'billing_last_name'  => $subscriber->last_name,
                    'billing_email'      => $subscriber->email,
                    'billing_address_1'  => $subscriber->address_line_1,
                    'billing_address_2'  => $subscriber->address_line_2,
                    'billing_postcode'   => $subscriber->postal_code,
                    'billing_city'       => $subscriber->city,
                    'billing_phone'      => $subscriber->phone,
                ])
            ];
        }

        foreach ($customerData as $key => $items) {
            foreach ($items as $itemKey => $default) {
                if (isset($fields[$key][$itemKey]) && empty($fields[$key][$itemKey]['default'])) {
                    $fields[$key][$itemKey]['default'] = $default;
                }
            }
        }

        return $fields;
    }
}
