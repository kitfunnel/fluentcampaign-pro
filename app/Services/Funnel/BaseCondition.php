<?php

namespace FluentCampaign\App\Services\Funnel;

abstract class BaseCondition
{
    protected $conditionName;

    protected $priority = 10;

    public function __construct()
    {
        $this->register();
    }

    public function register()
    {
//        add_filter('fluentcrm_funnel_blocks', array($this, 'pushBlock'), $this->priority, 2);
//        add_filter('fluentcrm_funnel_block_fields', array($this, 'pushBlockFields'), $this->priority, 2);
//        add_action('fluentcrm_funnel_sequence_handle_' . $this->conditionName, array($this, 'handle'), 10, 4);
    }

    public function pushBlock($blocks, $funnel)
    {
        $block = $this->getBlock();

        if ($block) {
            $block['type'] = 'conditional';
            $blocks[$this->conditionName] = $block;
        }

        return $blocks;
    }

    public function pushBlockFields($fields, $funnel)
    {
        $fields[$this->conditionName] = $this->getBlockFields();
        return $fields;
    }

    abstract public function getBlock();

    abstract public function getBlockFields();

    abstract public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric);

}
