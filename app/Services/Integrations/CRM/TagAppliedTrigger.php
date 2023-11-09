<?php

namespace FluentCampaign\App\Services\Integrations\CRM;

use FluentCampaign\App\Services\FunnelMultiConditionTrait;
use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class TagAppliedTrigger extends BaseTrigger
{
    use FunnelMultiConditionTrait;

    public function __construct()
    {
        $this->triggerName = 'fluentcrm_contact_added_to_tags';
        $this->actionArgNum = 2;
        $this->priority = 20;

        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('CRM', 'fluentcampaign-pro'),
            'label'       => __('Tag Applied', 'fluentcampaign-pro'),
            'description' => __('This will run when selected tags have been applied to a contact', 'fluentcampaign-pro'),
	        'icon'        => 'fc-icon-tag_applied'
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'tags'        => [],
            'select_type' => 'any'
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('Tag Applied', 'fluentcampaign-pro'),
            'sub_title' => __('This will run when selected tags have been applied to a contact', 'fluentcampaign-pro'),
            'fields'    => [
                'tags'        => [
                    'type'        => 'option_selectors',
                    'option_key'  => 'tags',
                    'is_multiple' => true,
                    'label'       => __('Select Tags', 'fluentcampaign-pro'),
                    'placeholder' => __('Select Tag', 'fluentcampaign-pro'),
                    'creatable' => true
                ],
                'select_type' => [
                    'label'      => __('Run When', 'fluentcampaign-pro'),
                    'type'       => 'radio',
                    'options'    => [
                        [
                            'id'    => 'any',
                            'title' => __('contact added in any of the selected tags', 'fluentcampaign-pro')
                        ],
                        [
                            'id'    => 'all',
                            'title' => __('contact added in all of the selected tags', 'fluentcampaign-pro')
                        ]
                    ],
                    'dependency' => [
                        'depends_on' => 'tags',
                        'operator'   => '!=',
                        'value'      => []
                    ]
                ]
            ]
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $attachedTagIds = $originalArgs[0];
        $subscriber = $originalArgs[1];

        $willProcess = $this->isProcessable($funnel, $attachedTagIds, $subscriber);
        $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriber, $originalArgs);

        if (!$willProcess) {
            return;
        }

        (new FunnelProcessor())->startFunnelSequence($funnel, [], [
            'source_trigger_name' => $this->triggerName
        ], $subscriber);
    }

    private function isProcessable($funnel, $attachedTagIds, $subscriber)
    {
        $tags = Arr::get($funnel->settings, 'tags', []);

        $attachIntersection = array_intersect($tags, $attachedTagIds);

        if (!$attachIntersection) {
            return false;
        }

        $selectType = $funnel->settings['select_type'];

        $subscriberTags = $subscriber->tags->pluck('id')->toArray();

        // Intersection of funnel tags & subscriber
        // tags will get the matching tag ids.
        $intersection = array_intersect($tags, $subscriberTags);

        if ($selectType === 'any') {
            // At least one funnel tag id is available.
            $match = !empty($intersection);
        } else {
            // All of the funnel tag ids are present.
            $match = count($intersection) === count($tags);
        }

        if (!$match) {
            return false;
        }


        // check run_only_one
        if ($subscriber && FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {
            $multipleRun = Arr::get($funnel->conditions, 'run_multiple') == 'yes';
            if ($multipleRun) {
                FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
            } else {
                return false;
            }
        }

        return true;
    }
}
