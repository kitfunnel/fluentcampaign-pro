<?php

namespace FluentCampaign\App\Services\Funnel\Conditions;

use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class FunnelABTesting
{
    protected $priority = 99;

    protected $name = 'funnel_ab_testing';

    public function register()
    {
        add_filter('fluentcrm_funnel_blocks', array($this, 'pushBlock'), $this->priority, 2);
        add_filter('fluentcrm_funnel_block_fields', array($this, 'pushBlockFields'), $this->priority, 2);
        add_action('fluentcrm_funnel_sequence_handle_funnel_ab_testing', array($this, 'handle'), 10, 4);
    }

    public function pushBlock($blocks, $funnel)
    {
        $blocks[$this->name] = [
            'type'             => 'conditional',
            'category'    => __('CRM', 'fluentcampaign-pro'),
            'title'            => __('Split (A/B Testing)', 'fluentcampaign-pro'),
            'description'      => __('Evenly split the contacts or choose how to distribute them', 'fluentcampaign-pro'),
            'icon'             => 'el-icon-guide',//fluentCrmMix('images/funnel_icons/has_contact_property.svg'),
            'settings'         => [
                'path_a' => '50',
                'path_b' => '50'
            ],
            'reload_on_insert' => true
        ];
        return $blocks;
    }

    public function pushBlockFields($allFields, $funnel)
    {
        $allFields[$this->name] = [
            'title'     => __('Split (A/B Testing)', 'fluentcampaign-pro'),
            'sub_title' => __('Evenly split the contacts or choose how to distribute them', 'fluentcampaign-pro'),
            'fields'    => [
                'path_a' => [
                    'wrapper_class' => 'fc_2col_inline',
                    'label' => 'Path A Contact Split (%)',
                    'type' => 'input-number'
                ],
                'path_b' => [
                    'wrapper_class' => 'fc_2col_inline',
                    'label' => 'Path B Contact Split (%)',
                    'type' => 'input-number'
                ]
            ]
        ];
        return $allFields;
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $pathA = (int) Arr::get($sequence->settings, 'path_a', 50);
        $pathB = (int) Arr::get($sequence->settings, 'path_b', 50);

        $rand = mt_rand(0, $pathA + $pathB);
        $isB = $rand > $pathA;

        $isB = apply_filters('fluent_crm/funnel_ab_test_is_b', $isB, $sequence, $sequence);

        (new FunnelProcessor())->initChildSequences($sequence, $isB, $subscriber, $funnelSubscriberId, $funnelMetric);
    }

}
