<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;

abstract class BaseWooAction extends BaseAction
{
    public function __construct()
    {
        parent::__construct();
    }

    public function pushBlock($blocks, $funnel)
    {
        if($this->isValid($funnel)) {
            $block = $this->getBlock();
            if($block) {
                $block['type'] = 'action';
                $blocks[$this->actionName] = $block;
            }
        }

        return $blocks;
    }

    public function pushBlockFields($fields, $funnel)
    {
        if($this->isValid($funnel)) {
            $this->funnel = $funnel;
            $fields[$this->actionName] = $this->getBlockFields();
        }

        return $fields;
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $funnelSub = FunnelSubscriber::find($funnelSubscriberId);

        if (!$funnelSub || !Helper::isWooTrigger($funnelSub->source_trigger_name)) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        $orderId = $funnelSub->source_ref_id;
        $order = wc_get_order($orderId);

        if (!$order) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        return $this->handleAction($order, $subscriber, $sequence, $funnelSubscriberId, $funnelMetric);
    }

    private function isValid($funnel)
    {
        if (!$funnel || !Helper::isWooTrigger($funnel->trigger_name)) {
            return false;
        }
        return true;
    }

    abstract public function handleAction($order, $subscriber, $sequence, $funnelSubscriberId, $funnelMetric);
}