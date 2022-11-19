<?php

namespace FluentCampaign\App\Services\Funnel\Actions;

use FluentCrm\App\Models\SubscriberNote;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;

class AddActivityAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'add_contact_activity';
        $this->priority = 29;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category' => __('CRM', 'fluentcampaign-pro'),
            'title'       => __('Add Notes & Activity', 'fluentcampaign-pro'),
            'description' => __('Add Notes or Activity to the Contact Profile', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-writing',
            'settings'    => [
                'type'        => 'note',
                'title'       => '',
                'description' => ''
            ]
        ];
    }

    public function getBlockFields()
    {
        $noteTypes = fluentcrm_activity_types();
        $typesOptions = [];
        foreach ($noteTypes as $type => $label) {
            $typesOptions[] = [
                'id'    => $type,
                'title' => $label
            ];
        }
        return [
            'title'     => __('Add Notes or Activity to Contact', 'fluentcampaign-pro'),
            'sub_title' => __('Add Notes or Activity to the Contact Profile', 'fluentcampaign-pro'),
            'fields'    => [
                'type' => [
                    'type'    => 'select',
                    'label'   => __('Select Activity Type', 'fluentcampaign-pro'),
                    'options' => $typesOptions
                ],
                'title' => [
                    'type' => 'input-text',
                    'label' => __('Activity Title', 'fluentcampaign-pro')
                ],
                'description' => [
                    'type' => 'html_editor',
                    'label' => __('Description', 'fluentcampaign-pro')
                ]
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $description = wp_unslash($sequence->settings['description']);
        $title = sanitize_text_field($sequence->settings['title']);
        $type = sanitize_text_field($sequence->settings['type']);

        if(!$description || !$title || !$type) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        SubscriberNote::create([
            'description' => $description,
            'title' => $title,
            'type' => $type,
            'created_by' => $sequence->created_by,
            'subscriber_id' => $subscriber->id
        ]);

        //FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id);
    }

}
