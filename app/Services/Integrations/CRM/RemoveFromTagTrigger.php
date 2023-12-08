<?php

namespace FluentCampaign\App\Services\Integrations\CRM;

use FluentCampaign\App\Services\FunnelMultiConditionTrait;
use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class RemoveFromTagTrigger extends BaseTrigger
{
    use FunnelMultiConditionTrait;

    public function __construct()
	{
		$this->triggerName = 'fluentcrm_contact_removed_from_tags';
		$this->actionArgNum = 2;
		$this->priority = 20;

		parent::__construct();
	}

	public function getTrigger()
	{

		return [
			'category'    => __('CRM', 'fluentcampaign-pro'),
			'label'       => __('Tag Removed', 'fluentcampaign-pro'),
			'description' => __('This will run when selected Tags have been removed from a contact', 'fluentcampaign-pro'),
			'icon'        => 'fc-icon-tag_removed'
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
			'title'     => __('Remove From Tags', 'fluentcampaign-pro'),
			'sub_title' => __('This will run when any of the selected tags have been removed from a contact', 'fluentcampaign-pro'),
			'fields'    => [
				'tags' => [
					'type'        => 'option_selectors',
					'option_key'  => 'tags',
					'is_multiple' => true,
					'label'       => __('Select Tags', 'fluentcampaign-pro'),
					'placeholder' => __('Select Tag', 'fluentcampaign-pro'),
                    'creatable' => true
				]
			]
		];
	}

	public function handle($funnel, $originalArgs)
	{
		$tagsTobeRemoved = $originalArgs[0];
		$subscriber = $originalArgs[1];

		$willProcess = $this->isProcessable($funnel, $tagsTobeRemoved, $subscriber);
		$willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriber, $originalArgs);

		if (!$willProcess) {
			return;
		}

		(new FunnelProcessor())->startFunnelSequence($funnel, [], [
			'source_trigger_name' => $this->triggerName
		], $subscriber);
	}

	private function isProcessable($funnel, $tagsTobeRemoved, $subscriber)
	{
        $tags = Arr::get($funnel->settings, 'tags', []);

		// Intersection of funnel settings tags & tags
		// to be removed will get the matching tag ids.
		$intersection = array_intersect($tags, $tagsTobeRemoved);

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
