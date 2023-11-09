<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;

class CreateCouponAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'fcrm_create_woo_coupon';
        $this->priority = 99;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('WooCommerce', 'fluentcampaign-pro'),
            'title'       => __('Create Coupon', 'fluentcampaign-pro'),
            'description' => __('Create Coupon Code', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-change_woo_status',
            'settings'    => [
                'base_coupon'   => '',
                'expire_in'     => '',
                'code_settings' => [
                    'code_type' => 'masked',
                    'prefix'    => 'get',
                    'code'      => '',
                    'suffix'    => 'discount',
                ]
            ]
        ];
    }

    public function getBlockFields()
    {
        $orderStatuses = wc_get_order_statuses();

        $formattedStatuses = [];
        foreach ($orderStatuses as $statusId => $statusName) {
            $formattedStatuses[] = [
                'id'    => $statusId,
                'title' => $statusName
            ];
        }

        return [
            'title'     => __('Change Order Status', 'fluentcampaign-pro'),
            'sub_title' => __('Change status of the current order in WooCommerce', 'fluentcampaign-pro'),
            'fields'    => [
                'new_status' => [
                    'type'    => 'select',
                    'label'   => __('New Order Status'),
                    'help'    => __('Please select the order status you want to change of this reference order.'),
                    'options' => $formattedStatuses
                ]
            ]
        ];
    }

    public function handleAction($order, $subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $settings = $sequence->settings;
        if (empty($settings['new_status'])) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        $newStatus = $settings['new_status'];
        // check if valid status
        $orderStatuses = wc_get_order_statuses();

        if (!isset($orderStatuses[$newStatus])) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        if ('wc-' === substr($newStatus, 0, 3)) {
            $newStatus = substr($newStatus, 3);
        }

        if ($order->get_status() == $newStatus) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        $order->update_status($newStatus, 'Order status has been changed to <b>' . $newStatus . '</b> by FluentCRM Automation ID: ' . $sequence->funnel_id);
        //FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id);
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        // TODO: Implement handle() method.
    }
}