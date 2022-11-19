<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class WooOrderStatusChangeTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'woocommerce_order_status_changed';
        $this->priority = 22;
        $this->actionArgNum = 4;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('WooCommerce', 'fluentcampaign-pro'),
            'label'       => __('Order Status Changed', 'fluentcampaign-pro'),
            'description' => __('This Funnel will start when a Order status will change from one state to another', 'fluentcampaign-pro'),
	        'icon'        => 'fc-icon-woo',
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
            'title'     => __('WooCommerce Order Status Changed', 'fluentcampaign-pro'),
            'sub_title' => __('This Funnel will start when a Order status will change from one state to another', 'fluentcampaign-pro'),
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
            'from_status'   => 'any',
            'to_status'     => 'any',
            'run_multiple'  => 'no'
        ];
    }

    public function getConditionFields($funnel)
    {
        $orderStatuses = wc_get_order_statuses();

        $formattedStatuses = [[
            'id'    => 'any',
            'title' => __('Any', 'fluentcampaign-pro')
        ]];

        foreach ($orderStatuses as $statusId => $statusName) {
            $formattedStatuses[] = [
                'id' => $statusId,
                'title' => $statusName
            ];
        }

        return [
            'from_status' => [
                'type' => 'select',
                'label' => __('From Order Status'),
                'help' => __('Your From Order Status.'),
                'options' => $formattedStatuses
            ],
            'to_status' => [
                'type' => 'select',
                'label' => __('To Order Status'),
                'help' => __('Your To Order Status.'),
                'options' => $formattedStatuses
            ],
            'run_multiple'  => [
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
        $fromStatus = $originalArgs[1];
        $toStatus = $originalArgs[2];
        $order = $originalArgs[3];

        $subscriberData = Helper::prepareSubscriberData($order);

        $subscriberData = FunnelHelper::maybeExplodeFullName($subscriberData);

        if (!is_email($subscriberData['email'])) {
            return false;
        }

        $willProcess = $this->isProcessable($funnel, $subscriberData, $fromStatus, $toStatus, $order);

        $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs);
        if (!$willProcess) {
            return false;
        }

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);

        $subscriberData['status'] = (!empty($subscriberData['subscription_status'])) ? $subscriberData['subscription_status'] : 'subscribed';
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $orderId
        ]);
    }

    private function isProcessable($funnel, $subscriberData, $fromStatus, $toStatus, $order)
    {
        $conditions = (array)$funnel->conditions;

        if($conditions['from_status'] != 'any') {
            if(str_replace('wc-', '', $conditions['from_status']) != $fromStatus) {
                return false;
            }
        }

        if($conditions['to_status'] != 'any') {
            if(str_replace('wc-', '', $conditions['to_status']) != $toStatus) {
                return false;
            }
        }

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);

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
