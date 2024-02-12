<?php

namespace FluentCampaign\App\Services\Integrations\CRM;

use FluentCampaign\App\Services\FunnelMultiConditionTrait;
use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class CompanyAppliedTrigger extends BaseTrigger
{
    use FunnelMultiConditionTrait;

	public function __construct()
	{
		$this->triggerName = 'fluentcrm_contact_added_to_companies';
		$this->actionArgNum = 2;
		$this->priority = 30;

		parent::__construct();
	}

	public function getTrigger()
	{
		return [
			'category'    => __('CRM', 'fluentcampaign-pro'),
			'label'       => __('Company Applied', 'fluentcampaign-pro'),
			'description' => __('This will run when selected companies have been applied to a contact', 'fluentcampaign-pro'),
			'icon'        => 'fc-icon-list_applied_2'
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
			'title'     => __('Company Applied', 'fluentcampaign-pro'),
			'sub_title' => __('This will run when selected companies have been applied to a contact', 'fluentcampaign-pro'),
			'fields'    => [
				'companies'       => [
					'type'        => 'option_selectors',
					'option_key'  => 'companies',
					'is_multiple' => true,
					'label'       => __('Select Companies', 'fluentcampaign-pro'),
					'placeholder' => __('Select Company', 'fluentcampaign-pro'),
				],
				'select_type' => [
					'label'      => __('Run When', 'fluentcampaign-pro'),
					'type'       => 'radio',
					'options'    => [
						[
							'id'    => 'any',
							'title' => __('contact added in any of the selected companies', 'fluentcampaign-pro')
						],
						[
							'id'    => 'all',
							'title' => __('contact added in all of the selected companies', 'fluentcampaign-pro')
						]
					],
					'dependency' => [
						'depends_on' => 'companies',
						'operator'   => '!=',
						'value'      => []
					]
				]
			]
		];
	}


	public function handle($funnel, $originalArgs)
	{
	    $removedCompanies = $originalArgs[0];
		$subscriber = $originalArgs[1];

		$willProcess = $this->isProcessable($funnel, $removedCompanies,  $subscriber);
		$willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriber, $originalArgs);

		if (!$willProcess) {
			return;
		}

		(new FunnelProcessor())->startFunnelSequence($funnel, [], [
			'source_trigger_name' => $this->triggerName
		], $subscriber);
	}

	private function isProcessable($funnel, $removedCompanies, $subscriber)
	{
		$companies = $funnel->settings['companies'];

        $attachIntersection = array_intersect($companies, $removedCompanies);

        if(!$attachIntersection) {
            return false;
        }

		$selectType = $funnel->settings['select_type'];

		$subscriberCompanies = $subscriber->companies->pluck('id')->toArray();

		// Intersection of funnel companies & subscriber
		// companies will get the matching company ids.
		$intersection = array_intersect($companies, $subscriberCompanies);

		if ($selectType === 'any') {
			// At least one funnel company id is available.
			$match = !empty($intersection);
		} else {
			// All of the funnel company ids are present.
			$match = count($intersection) === count($companies);
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
