<?php

namespace FluentCampaign\App\Services\Funnel\Benchmarks;

use FluentCrm\App\Services\Funnel\BaseBenchMark;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class LinkClickBenchmark extends BaseBenchMark
{
    public function __construct()
    {
        $this->triggerName = 'fluencrm_benchmark_link_clicked';
        $this->actionArgNum = 2;
        $this->priority = 42;

        parent::__construct();

        add_filter('fluentcrm_funnel_sequence_filtered_'.$this->triggerName, function ($benchMark) {
            if(isset($benchMark['id'])) {
                $benchMark['settings']['mask_url'] = add_query_arg([
                    'fluentcrm' => 1,
                    'route' => 'bnu',
                    'aid' => $benchMark['id']
                ], site_url('/'));
            }
            return $benchMark;
        }, 10, 1);

    }

    public function getBlock()
    {
        return [
            'title'       => __('Link Click', 'fluentcampaign-pro'),
            'description' => __('This will run once a subscriber click on this provided link', 'fluentcampaign-pro'),
            'icon' => 'fc-icon-link_clicked',//fluentCrmMix('images/funnel_icons/link_clicked.svg'),
            'settings'    => [
                'redirect_to' => site_url('/'),
                'mask_url'    => '',
                'type'        => 'optional',
                'can_enter' => 'yes'
            ],
            'reload_on_insert' => true
        ];
    }

    public function getBlockFields($funnel)
    {
        return [
            'title'     => __('Link Click', 'fluentcampaign-pro'),
            'sub_title' => __('This will run once a subscriber click on this provided link', 'fluentcampaign-pro'),
            'fields'    => [
                'mask_url'           => [
                    'type'        => 'input-text',
                    'placeholder' => __('Please save to get the sharable link', 'fluentcampaign-pro'),
                    'label'       => __('Copy This Link', 'fluentcampaign-pro'),
                    'readonly'    => true,
                    'help'        => __('Select for which products this goal will run', 'fluentcampaign-pro'),
                    'inline_help' => __('Paste this link in any email or page. When a contact click this link then it will be recorded and redirect to the url as provided bellow.', 'fluentcampaign-pro')
                ],
                'redirect_to' => [
                    'type'        => 'url_selector',
                    'label'       => __('Redirect To', 'fluentcampaign-pro'),
                    'placeholder' => __('Your Target URL', 'fluentcampaign-pro'),
                    'help'        => __('Contacts will be redirected to this link.', 'fluentcampaign-pro'),
                    'inline_help' => __('Please provide the url to where the contact will be redirected', 'fluentcampaign-pro')
                ],
                'type'               => $this->benchmarkTypeField(),
                'can_enter' => $this->canEnterField()
            ]
        ];
    }

    /*
     * @todo: Remove this method at January 2023
     */
    public function canEnterField()
    {
        return [
            'type'        => 'yes_no_check',
            'check_label' => __('Contacts can enter directly to this sequence point. If you enable this then any contact meet with goal will enter in this goal point.', 'fluentcampaign-pro'),
            'default_set_value' => 'yes'
        ];
    }

    public function handle($benchMark, $originalArgs)
    {
        $benchmarkId = $originalArgs[0];
        if($benchMark->id != $benchmarkId) {
            return;
        }

        $subscriber = $originalArgs[1];

        if($subscriber) {
            $funnelProcessor = new FunnelProcessor();
            $funnelProcessor->startFunnelFromSequencePoint($benchMark, $subscriber);
        }


        $url = Arr::get($benchMark->settings, 'redirect_to', site_url('/'));

        $url = str_replace(['&amp;', '+'], ['&', '%2B'], $url);

        wp_redirect($url);
        exit();
    }
}
