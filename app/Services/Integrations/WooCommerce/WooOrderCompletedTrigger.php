<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class WooOrderCompletedTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'woocommerce_order_status_completed';
        $this->priority = 20;
        $this->actionArgNum = 2;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('WooCommerce', 'fluentcampaign-pro'),
            'label'       => __('Order Completed', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-woo_order_complete',
            'description' => __('This funnel will start when an order is completed', 'fluentcampaign-pro')
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
            'title'     => __('WooCommerce Order has been completed', 'fluentcampaign-pro'),
            'sub_title' => __('This Funnel will start once new order will be marked as completed', 'fluentcampaign-pro'),
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
            'product_ids'        => [],
            'product_categories' => [],
            'purchase_type'      => 'all',
            'run_multiple'       => 'no'
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'product_ids'        => [
                'type'        => 'rest_selector',
                'option_key'  => 'woo_products',
                'is_multiple' => true,
                'label'       => __('Target Products', 'fluentcampaign-pro'),
                'help'        => __('Select for which products this automation will run', 'fluentcampaign-pro'),
                'inline_help' => __('Keep it blank to run to any product purchase', 'fluentcampaign-pro')
            ],
            'product_categories' => [
                'type'        => 'rest_selector',
                'option_key'  => 'woo_categories',
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
            ]
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $orderId = $originalArgs[0];
        $order = $originalArgs[1];

        $subscriberData = Helper::prepareSubscriberData($order);

        $subscriberData = FunnelHelper::maybeExplodeFullName($subscriberData);

        if (!is_email($subscriberData['email'])) {
            return;
        }

        $willProcess = $this->isProcessable($funnel, $order, $subscriberData);

        $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs);
        if (!$willProcess) {

            return;
        }

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);

        $subscriberData['status'] = (!empty($subscriberData['subscription_status'])) ? $subscriberData['subscription_status'] : 'subscribed';
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $orderId
        ]);

    }

    private function isProcessable($funnel, $order, $subscriberData)
    {
        $conditions = (array)$funnel->conditions;
        $purchaseType = Arr::get($conditions, 'purchase_type');
        $result = Helper::isPurchaseTypeMatch($order, $purchaseType) && Helper::isProductIdCategoryMatched($order, $conditions);

        if (!$result) {
            return false;
        }

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);

        // check run_only_one
        if ($subscriber) {
            $funnelSub = FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id);
            if ($funnelSub) {
                $multipleRun = Arr::get($conditions, 'run_multiple') == 'yes';
                if ($multipleRun) {
                    if ($funnelSub->source_ref_id == $order->get_id()) {
                        return false;
                    }
                    FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
                }
                return false;
            }
        }

        return true;

    }
}
