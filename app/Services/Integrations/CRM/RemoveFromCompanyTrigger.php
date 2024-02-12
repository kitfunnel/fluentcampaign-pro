<?php

namespace FluentCampaign\App\Services\Integrations\CRM;

use FluentCampaign\App\Services\FunnelMultiConditionTrait;
use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class RemoveFromCompanyTrigger extends BaseTrigger
{
    use FunnelMultiConditionTrait;

	public function __construct()
	{
		$this->triggerName = 'fluentcrm_contact_removed_from_companies';
		$this->actionArgNum = 2;
		$this->priority = 30;

		parent::__construct();
	}

	public function getTrigger()
	{

		return [
			'category'    => __('CRM', 'fluentcampaign-pro'),
			'label'       => __('Company Removed', 'fluentcampaign-pro'),
			'description' => __('This will run when selected companies have been removed from a contact', 'fluentcampaign-pro'),
			'icon'        => 'fc-icon-list_removed'
		];
	}

	public function getFunnelSettingsDefaults()
	{
		return [
			'companies'       => [],
			'select_type' => 'any'
		];
	}

	public function getSettingsFields($funnel)
	{
		return [
			'title'     => __('Remove From Company', 'fluentcampaign-pro'),
			'sub_title' => __('This will run when any of the selected companies have been removed from a contact', 'fluentcampaign-pro'),
			'fields'    => [
				'companies' => [
					'type'        => 'option_selectors',
					'option_key'  => 'companies',
					'is_multiple' => true,
					'label'       => __('Select Companies', 'fluentcampaign-pro'),
					'placeholder' => __('Select Company', 'fluentcampaign-pro')
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
		$companiesTobeRemoved = $originalArgs[0];
		$subscriber = $originalArgs[1];

		$willProcess = $this->isProcessable($funnel, $companiesTobeRemoved, $subscriber);
		$willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriber, $originalArgs);

		if (!$willProcess) {
			return;
		}

		(new FunnelProcessor())->startFunnelSequence($funnel, [], [
			'source_trigger_name' => $this->triggerName
		], $subscriber);
	}

	private function isProcessable($funnel, $companiesTobeRemoved, $subscriber)
	{
		$companies = $funnel->settings['companies'];

		// Intersection of funnel settings companies & companies
		// to be removed will get the matching company ids.
		$intersection = array_intersect($companies, $companiesTobeRemoved);

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
