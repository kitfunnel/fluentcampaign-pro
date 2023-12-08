<?php

namespace FluentCampaign\App\Services\Integrations\CRM;

use FluentCampaign\App\Services\FunnelMultiConditionTrait;
use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class ListAppliedTrigger extends BaseTrigger
{
    use FunnelMultiConditionTrait;

	public function __construct()
	{
		$this->triggerName = 'fluentcrm_contact_added_to_lists';
		$this->actionArgNum = 2;
		$this->priority = 20;

		parent::__construct();
	}

	public function getTrigger()
	{
		return [
			'category'    => __('CRM', 'fluentcampaign-pro'),
			'label'       => __('List Applied', 'fluentcampaign-pro'),
			'description' => __('This will run when selected lists have been applied to a contact', 'fluentcampaign-pro'),
			'icon'        => 'fc-icon-list_applied_2'
		];
	}

	public function getFunnelSettingsDefaults()
	{
		return [
			'lists'       => [],
			'select_type' => 'any'
		];
	}

	public function getSettingsFields($funnel)
	{
		return [
			'title'     => __('List Applied', 'fluentcampaign-pro'),
			'sub_title' => __('This will run when selected lists have been applied to a contact', 'fluentcampaign-pro'),
			'fields'    => [
				'lists'       => [
					'type'        => 'option_selectors',
					'option_key'  => 'lists',
					'is_multiple' => true,
					'label'       => __('Select Lists', 'fluentcampaign-pro'),
					'placeholder' => __('Select List', 'fluentcampaign-pro'),
                    'creatable' => true
				],
				'select_type' => [
					'label'      => __('Run When', 'fluentcampaign-pro'),
					'type'       => 'radio',
					'options'    => [
						[
							'id'    => 'any',
							'title' => __('contact added in any of the selected lists', 'fluentcampaign-pro')
						],
						[
							'id'    => 'all',
							'title' => __('contact added in all of the selected lists', 'fluentcampaign-pro')
						]
					],
					'dependency' => [
						'depends_on' => 'lists',
						'operator'   => '!=',
						'value'      => []
					]
				]
			]
		];
	}


	public function handle($funnel, $originalArgs)
	{
	    $removedLists = $originalArgs[0];
		$subscriber = $originalArgs[1];

		$willProcess = $this->isProcessable($funnel, $removedLists,  $subscriber);
		$willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriber, $originalArgs);

		if (!$willProcess) {
			return;
		}

		(new FunnelProcessor())->startFunnelSequence($funnel, [], [
			'source_trigger_name' => $this->triggerName
		], $subscriber);
	}

	private function isProcessable($funnel, $removedLists, $subscriber)
	{
		$lists = $funnel->settings['lists'];

        $attachIntersection = array_intersect($lists, $removedLists);

        if(!$attachIntersection) {
            return false;
        }

		$selectType = $funnel->settings['select_type'];

		$subscriberLists = $subscriber->lists->pluck('id')->toArray();

		// Intersection of funnel lists & subscriber
		// lists will get the matching list ids.
		$intersection = array_intersect($lists, $subscriberLists);

		if ($selectType === 'any') {
			// At least one funnel list id is available.
			$match = !empty($intersection);
		} else {
			// All of the funnel list ids are present.
			$match = count($intersection) === count($lists);
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
