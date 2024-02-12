<?php

namespace FluentCampaign\App\Services\Integrations\Edd;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class EddRecurringPaymentTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'edd_recurring_add_subscription_payment';
        $this->priority = 10;
        $this->actionArgNum = 1;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('Easy Digital Downloads', 'fluentcampaign-pro'),
            'label'       => __('Renewal Payment Received', 'fluentcampaign-pro'),
            'description' => __('This funnel will start when a renewal payment is received for an active subscription', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-edd_new_order_success',
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
            'title'     => __('Renewal Payment Received', 'fluentcampaign-pro'),
            'sub_title' => __('This Funnel will start once a Renewal Payment received for an active subscription', 'fluentcampaign-pro'),
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
            'update_type'        => 'update', // skip_all_actions, skip_update_if_exist
            'product_ids'        => [],
            'product_categories' => [],
            'run_multiple'       => 'no'
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'update_type'        => [
                'type'    => 'radio',
                'label'   => __('If Contact Exist?', 'fluentcampaign-pro'),
                'help'    => __('Please specify what will happen if the subscriber already exist in the database', 'fluentcampaign-pro'),
                'options' => FunnelHelper::getUpdateOptions()
            ],
            'product_ids'        => [
                'type'        => 'rest_selector',
                'option_key'  => 'edd_products',
                'is_multiple' => true,
                'label'       => __('Target Products', 'fluentcampaign-pro'),
                'help'        => __('Select for which products this automation will run', 'fluentcampaign-pro'),
                'inline_help' => __('Keep it blank to run to any product purchase', 'fluentcampaign-pro')
            ],
            'product_categories' => [
                'type'        => 'tax_selector',
                'taxonomy'    => 'download_category',
                'is_multiple' => true,
                'label'       => __('OR Target Product Categories', 'fluentcampaign-pro'),
                'help'        => __('Select for which product category the automation will run', 'fluentcampaign-pro'),
                'inline_help' => __('Keep it blank to run to any category products', 'fluentcampaign-pro')
            ],
            'run_multiple'       => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart the Automation Multiple times for a contact for this event. (Only enable if you want to restart automation for the same contact)', 'fluentcampaign-pro'),
                'inline_help' => __('If you enable, then it will restart the automation for a contact if the contact already in the automation. Otherwise, It will just skip if already exist', 'fluentcampaign-pro')
            ],
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $payment = $originalArgs[0];

        $subscriberData = Helper::prepareSubscriberData($payment);
        $subscriberData['source'] = 'edd';

        if (empty($subscriberData['email'])) {
            return;
        }

        $willProcess = $this->isProcessable($funnel, $payment, $subscriberData);

        $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs);

        if (!$willProcess) {
            return;
        }

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);

        $subscriberData['status'] = $subscriberData['subscription_status'];
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $payment->ID
        ]);

    }

    private function isProcessable($funnel, $payment, $subscriberData)
    {
        $conditions = (array)$funnel->conditions;

        // check update_type
        $updateType = Arr::get($conditions, 'update_type');

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);
        if ($subscriber && $updateType == 'skip_all_if_exist') {
            return false;
        }

        if (!Helper::isProductIdCategoryMatched($payment, $conditions)) {
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
