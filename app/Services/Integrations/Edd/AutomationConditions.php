<?php

namespace FluentCampaign\App\Services\Integrations\Edd;

use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCrm\App\Models\Subscriber;

class AutomationConditions
{
    public function init()
    {
        add_filter('fluentcrm_automation_condition_groups', array($this, 'addAutomationConditions'), 10, 1);
        add_filter('fluentcrm_automation_conditions_assess_edd', array($this, 'assessAutomationConditions'), 10, 3);
    }

    public function addAutomationConditions($groups)
    {
        $disabled = !Commerce::isEnabled('edd');

        $groups['edd'] = [
            'label'    => ($disabled) ? __('EDD (Sync Required)', 'fluentcampaign-pro') : __('EDD', 'fluentcampaign-pro'),
            'value'    => 'edd',
            'children' => [
                [
                    'value'             => 'purchased_items',
                    'label'             => __('Purchased Products', 'fluentcampaign-pro'),
                    'type'              => 'selections',
                    'component'         => 'product_selector',
                    'cacheable'         => true,
                    'is_singular_value' => true,
                    'is_multiple'       => true,
                    'disabled'          => false
                ],
                [
                    'value'    => 'total_order_count',
                    'label'    => __('Total Order Count', 'fluentcampaign-pro'),
                    'type'     => 'numeric',
                    'disabled' => $disabled,
                    'min'      => 1,
                ],
                [
                    'value'    => 'total_order_value',
                    'label'    => __('Total Order Value', 'fluentcampaign-pro'),
                    'type'     => 'numeric',
                    'disabled' => $disabled,
                    'min'      => 1,
                ],
                [
                    'value'    => 'last_order_date',
                    'label'    => __('Last Order Date', 'fluentcampaign-pro'),
                    'type'     => 'dates',
                    'disabled' => $disabled
                ],
                [
                    'value'    => 'first_order_date',
                    'label'    => __('First Order Date', 'fluentcampaign-pro'),
                    'type'     => 'dates',
                    'disabled' => $disabled
                ],
                [
                    'value'       => 'purchased_categories',
                    'label'       => __('Purchased Categories', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'tax_selector',
                    'taxonomy'    => 'download_category',
                    'is_multiple' => true,
                    'disabled'    => $disabled
                ],
                [
                    'value'       => 'purchased_tags',
                    'label'       => __('Purchased Tags', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'tax_selector',
                    'taxonomy'    => 'download_tag',
                    'is_multiple' => true,
                    'disabled'    => $disabled
                ],
                [
                    'value'       => 'commerce_coupons',
                    'label'       => __('Used Coupons', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'ajax_selector',
                    'option_key'  => 'edd_coupons',
                    'is_multiple' => true,
                    'disabled'    => $disabled
                ],
                [
                    'value'             => 'commerce_exist',
                    'label'             => 'Is a customer?',
                    'type'              => 'selections',
                    'is_multiple'       => false,
                    'disable_values'    => true,
                    'value_description' => 'This filter will check if a contact has at least one shop order or not',
                    'custom_operators'  => [
                        'exist'     => 'Yes',
                        'not_exist' => 'No',
                    ],
                    'disabled'          => $disabled
                ]
            ],
        ];

        if (defined('EDD_SL_VERSION')) {
            $groups['edd']['children'][] = [
                'value'             => 'has_active_license',
                'label'             => __('Valid License', 'fluentcampaign-pro'),
                'type'              => 'selections',
                'component'         => 'product_selector',
                'is_singular_value' => true,
                'is_multiple'       => true,
                'disabled'          => false
            ];
        }

        if (defined('EDD_RECURRING_VERSION')) {
            $groups['edd']['children'][] = [
                'value'             => 'has_active_subscription',
                'label'             => __('Active Subscription', 'fluentcampaign-pro'),
                'type'              => 'selections',
                'component'         => 'product_selector',
                'is_singular_value' => true,
                'is_multiple'       => true,
                'disabled'          => false
            ];
        }

        return $groups;
    }

    public function assessAutomationConditions($result, $conditions, $subscriber)
    {
        $manualProps = ['has_active_license', 'has_active_subscription'];

        if (Commerce::isEnabled('edd')) {
            $formattedConditions = [];

            foreach ($conditions as $condition) {
                $prop = $condition['data_key'];
                $operator = $condition['operator'];

                if (in_array($prop, $manualProps)) {
                    $isTrue = Helper::getSubscriptionLicenseProp($prop, $subscriber, $condition['data_value']);
                    if (($operator == 'in' && !$isTrue) || ($operator == 'not_in' && $isTrue)) {
                        return false;
                    }
                    continue;
                }

                $formattedConditions[] = [
                    'operator' => $operator,
                    'value'    => $condition['data_value'],
                    'property' => $prop,
                ];
            }

            $hasSubscriber = Subscriber::where('id', $subscriber->id)->where(function ($q) use ($formattedConditions) {
                do_action_ref_array('fluentcrm_contacts_filter_edd', [&$q, $formattedConditions]);
            })->first();

            return !!$hasSubscriber;
        } else {
            foreach ($conditions as $condition) {
                $prop = $condition['data_key'];
                $value = $condition['data_value'];
                $operator = $condition['operator'];
                if ($prop == 'purchased_items') {
                    $isPurchases = Helper::isProductPurchased($value, $subscriber);
                    if (($operator == 'in' && !$isPurchases) || ($operator == 'not_in' && $isPurchases)) {
                        return false;
                    }
                }

                if (in_array($prop, $manualProps)) {
                    $isTrue = Helper::getSubscriptionLicenseProp($prop, $subscriber, $condition['data_value']);
                    if (($operator == 'in' && !$isTrue) || ($operator == 'not_in' && $isTrue)) {
                        return false;
                    }
                    continue;
                }

            }
        }

        return $result;
    }
}
