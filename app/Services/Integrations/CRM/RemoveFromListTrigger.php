<?php

namespace FluentCampaign\App\Services\Integrations\CRM;

use FluentCampaign\App\Services\FunnelMultiConditionTrait;
use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class RemoveFromListTrigger extends BaseTrigger
{
    use FunnelMultiConditionTrait;

	public function __construct()
	{
		$this->triggerName = 'fluentcrm_contact_removed_from_lists';
		$this->actionArgNum = 2;
		$this->priority = 20;

		parent::__construct();
	}

	public function getTrigger()
	{

		return [
			'category'    => __('CRM', 'fluentcampaign-pro'),
			'label'       => __('List Removed', 'fluentcampaign-pro'),
			'description' => __('This will run when selected lists have been removed from a contact', 'fluentcampaign-pro'),
			'icon'        => 'fc-icon-list_removed'
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
			'title'     => __('Remove From List', 'fluentcampaign-pro'),
			'sub_title' => __('This will run when any of the selected lists have been removed from a contact', 'fluentcampaign-pro'),
			'fields'    => [
				'lists' => [
					'type'        => 'option_selectors',
					'option_key'  => 'lists',
					'is_multiple' => true,
					'label'       => __('Select Lists', 'fluentcampaign-pro'),
					'placeholder' => __('Select List', 'fluentcampaign-pro'),
                    'creatable' => true
				]
			]
		];
	}

	public function getFunnelConditionDefaults($funnel)
	{
		return [];
	}

	public function getConditionFields($funnel)
	{
		return [];
	}

	public function handle($funnel, $originalArgs)
	{
		$listsTobeRemoved = $originalArgs[0];
		$subscriber = $originalArgs[1];

		$willProcess = $this->isProcessable($funnel, $listsTobeRemoved, $subscriber);
		$willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriber, $originalArgs);

		if (!$willProcess) {
			return;
		}

		(new FunnelProcessor())->startFunnelSequence($funnel, [], [
			'source_trigger_name' => $this->triggerName
		], $subscriber);
	}

	private function isProcessable($funnel, $listsTobeRemoved, $subscriber)
	{
		$lists = $funnel->settings['lists'];

		// Intersection of funnel settings lists & lists
		// to be removed will get the matching list ids.
		$intersection = array_intersect($lists, $listsTobeRemoved);

		if (empty($intersection)) {
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
