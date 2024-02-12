<?php

namespace FluentCampaign\App\Services\Integrations\AffiliateWP;

use FluentCrm\App\Models\Funnel;
use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class AffiliateWPAffActiveTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'affwp_set_affiliate_status';
        $this->priority = 10;
        $this->actionArgNum = 3;
        parent::__construct();

        add_action('affwp_process_register_form', function () {
            // This happens before the init which is actually plugins_loaded
            // AffiliateWP could handle this better way and fire the hook at init
            add_action('affwp_set_affiliate_status', function ($affId, $status) {
                $funnels = Funnel::where('status', 'published')
                    ->where('trigger_name', 'affwp_set_affiliate_status')
                    ->get();

                $originalArgs = [
                    $affId,
                    $status
                ];

                foreach ($funnels as $funnel) {
                    ob_start();
                    do_action('fluentcrm_funnel_start_affwp_set_affiliate_status', $funnel, $originalArgs);
                    $maybeErrors = ob_get_clean();
                }
            }, 10, 2);
        });

        (new DeepIntegration())->init();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('AffiliateWP', 'fluentcampaign-pro'),
            'label'       => __('New Affiliate Joined', 'fluentcampaign-pro'),
            'description' => __('This funnel will be initiated when a new affiliate gets approved/registered directly', 'fluentcampaign-pro')
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'subscription_status' => 'subscribed'
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('New Affiliate Approved/Active Register', 'fluentcampaign-pro'),
            'sub_title' => __('This Funnel will be initiated when affiliate will be approved or register as direct approved', 'fluentcampaign-pro'),
            'fields'    => [
                'subscription_status'      => [
                    'type'        => 'option_selectors',
                    'option_key'  => 'editable_statuses',
                    'is_multiple' => false,
                    'label'       => __('Subscription Status', 'fluentcampaign-pro'),
                    'placeholder' => __('Select Status', 'fluentcampaign-pro')
                ],
                'subscription_status_info' => [
                    'type'       => 'html',
                    'info'       => '<b>' . __('An Automated double-optin email will be sent for new subscribers', 'fluentcampaign-pro') . '</b>',
                    'dependency' => [
                        'depends_on' => 'subscription_status',
                        'operator'   => '=',
                        'value'      => 'pending'
                    ]
                ],
                'run_multiple'       => [
                    'type'        => 'yes_no_check',
                    'label'       => '',
                    'check_label' => __('Restart the Automation Multiple times for a contact for this event. (Only enable if you want to restart automation for the same contact)', 'fluentcampaign-pro'),
                    'inline_help' => __('If you enable, then it will restart the automation for a contact if the contact already in the automation. Otherwise, It will just skip if already exist', 'fluentcampaign-pro')
                ]
            ]
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'update_type' => 'update', // skip_all_actions, skip_update_if_exist
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'update_type' => [
                'type'    => 'radio',
                'label'   => __('If Contact Already Exist?', 'fluentcampaign-pro'),
                'help'    => __('Please specify what will happen if the subscriber already exist in the database', 'fluentcampaign-pro'),
                'options' => FunnelHelper::getUpdateOptions()
            ]
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $affiliateId = $originalArgs[0];
        $newStatus = $originalArgs[1];
        if ($newStatus != 'active') {
            return false;
        }

        $affiliate = affwp_get_affiliate($affiliateId);

        $subscriberData = FunnelHelper::prepareUserData($affiliate->user_id);
        if (empty($subscriberData['email'])) {
            return false;
        }

        $willProcess = $this->isProcessable($funnel, $subscriberData);
        $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs);
        if (!$willProcess) {
            return false;
        }

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);
        $subscriberData['status'] = $subscriberData['subscription_status'];
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $affiliateId
        ]);

    }

    private function isProcessable($funnel, $subscriberData)
    {
        $conditions = $funnel->conditions;
        // check update_type
        $updateType = Arr::get($conditions, 'update_type');

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);
        if ($updateType == 'skip_all_if_exist') {
            return false;
        }

        // check run_only_one
        if ($subscriber && FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {
            $multipleRun = Arr::get($conditions, 'run_multiple') == 'yes';
            if ($multipleRun) {
                FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
            } else {
                return false;
            }
        }

        return true;
    }
}
