<?php

namespace FluentCampaign\App\Services\Integrations\Edd;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class EddLicenseExpiredTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'edd_sl_post_set_status';
        $this->priority = 10;
        $this->actionArgNum = 2;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('Easy Digital Downloads', 'fluentcampaign-pro'),
            'label'       => __('License Expired', 'fluentcampaign-pro'),
            'description' => __('This funnel will start when a license gets expired', 'fluentcampaign-pro')
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
            'title'     => __('License Expired in EDD', 'fluentcampaign-pro'),
            'sub_title' => __('This Funnel will start a license status get marked as expired', 'fluentcampaign-pro'),
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
                ]
            ]
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'product_id'   => [],
            'run_multiple' => 'yes'
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'product_id'   => [
                'type'        => 'rest_selector',
                'option_key'  => 'edd_products',
                'is_multiple' => false,
                'label'       => __('Target Product', 'fluentcampaign-pro'),
                'help'        => __('Select for which product this automation will run', 'fluentcampaign-pro'),
                'inline_help' => 'Leave blank to run on any product'
            ],
            'run_multiple' => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart the Automation Multiple times for a contact for this event. (Only enable if you want to restart automation for the same contact)', 'fluentcampaign-pro'),
                'inline_help' => __('If you enable, then it will restart the automation for a contact if the contact already in the automation. Otherwise, It will just skip if already exist', 'fluentcampaign-pro')
            ],
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $newStatus = $originalArgs[1];
        if ($newStatus != 'expired') {
            return false;
        }

        $licenseId = $originalArgs[0];

        $license = edd_software_licensing()->get_license($licenseId);

        if(!$license || !$license->ID) {
            return false;
        }


        $product = $license->get_download();

        if (!$product || !$license->customer_id) {
            return false;
        }

        $productId = $product->get_ID();

        $conditions = (array)$funnel->conditions;

        if (!empty($conditions['product_id']) && $conditions['product_id'] != $productId) {
            return false;
        }

        $customer = new \EDD_Customer($license->customer_id);

        if (!$customer) {
            return false;
        }

        $hasOtherLicense = fluentCrmDb()->table('edd_licenses')
            ->select(['id'])
            ->where('download_id', $productId)
            ->where('customer_id', $license->customer_id)
            ->whereIn('status', ['active', 'inactive'])
            ->first();

        if ($hasOtherLicense) {
            return false; // Other Active License exist
        }

        if ($customer->user_id) {
            $user = get_user_by('ID', $customer->user_id);
        } else {
            $user = get_user_by('email', $customer->email);
        }

        if (!$user) {
            return false;
        }

        $subscriberData = [
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'email'      => $user->user_email,
            'user_id'    => $user->ID
        ];


        $willProcess = $this->isProcessable($funnel, $subscriberData);

        if (!$willProcess) {
            return false;
        }

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);

        $subscriberData['status'] = $subscriberData['subscription_status'];

        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $licenseId,
        ]);

    }

    private function isProcessable($funnel, $subscriberData)
    {
        $conditions = (array)$funnel->conditions;

        $subscriber = FluentCrmApi('contacts')->getContactByUserRef($subscriberData['email']);

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
