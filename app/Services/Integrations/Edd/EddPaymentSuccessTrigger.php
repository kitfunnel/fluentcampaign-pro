<?php

namespace FluentCampaign\App\Services\Integrations\Edd;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class EddPaymentSuccessTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'edd_update_payment_status';
        $this->priority = 10;
        $this->actionArgNum = 3;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('Easy Digital Downloads', 'fluentcampaign-pro'),
            'label'       => __('Edd - New Order Success', 'fluentcampaign-pro'),
            'description' => __('This funnel will start once new order payment is successful', 'fluentcampaign-pro'),
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
            'title'     => __('New Edd Order (paid) has been places', 'fluentcampaign-pro'),
            'sub_title' => __('This Funnel will start once new order will be added as successful payment', 'fluentcampaign-pro'),
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
            'purchase_type'      => 'all',
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
            'purchase_type'      => [
                'type'        => 'radio',
                'label'       => __('Purchase Type', 'fluentcampaign-pro'),
                'help'        => __('Select the purchase type', 'fluentcampaign-pro'),
                'options'     => Helper::purchaseTypeOptions(),
                'inline_help' => __('For what type of purchase you want to run this funnel', 'fluentcampaign-pro')
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
        $paymentId = $originalArgs[0];
        $newStatus = $originalArgs[1];
        $oldStatus = $originalArgs[2];
        $successStatuses = ['publish', 'complete', 'completed'];
        if ($newStatus == $oldStatus || !in_array($newStatus, $successStatuses) ) {
            return;
        }

        $payment = edd_get_payment($paymentId);

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
            'source_ref_id'       => $paymentId,
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

        $purchaseType = Arr::get($conditions, 'purchase_type');
        $result = Helper::isPurchaseTypeMatch($payment, $purchaseType) && Helper::isProductIdCategoryMatched($payment, $conditions);

        if (!$result) {
            return false;
        }

        if (!$subscriber) {
            return true;
        }

        $funnelSub = FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id);

        // check run_only_one
        if ($funnelSub) {

            if ($funnelSub->source_ref_id == $payment->ID) {
                return false;
            }

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
