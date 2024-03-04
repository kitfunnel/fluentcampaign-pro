<?php

namespace FluentCampaign\App\Services\Integrations\ElementorFormIntegration;

use \Elementor\Controls_Manager;
use ElementorPro\Modules\Forms\Classes\Integration_Base;
use FluentCrm\App\Models\CustomContactField;
use FluentCrm\Framework\Support\Arr;

class FormWidget extends Integration_Base
{

    /**
     * Integration name
     *
     * @return string
     */
    public function get_name()
    {
        return 'fluentcrm_integration';
    }

    /**
     * Integration label for dropdown
     *
     * @return string
     */
    public function get_label()
    {
        return esc_html__('FluentCRM', 'fluentcampaign-pro');
    }

    public function register_settings_section($widget)
    {

        if (!method_exists($this, 'register_fields_map_control')) {
            return false;
        }

        $widget->start_controls_section(
            '_ei_section_newsletter',
            [
                'label'     => __('FluentCRM', 'fluentcampaign-pro'),
                'condition' => [
                    'submit_actions' => $this->get_name(),
                ],
            ]
        );

        $this->register_fields_map_control($widget);

        $list_ids = [];

        $lists = FluentCrmApi('lists')->all();
        foreach ($lists as $list) {
            $list_ids[$list->id] = $list->title;
        }

        $widget->add_control(
            'fluentcrm_lists',
            [
                'label'       => __('Select Lists', 'fluentcampaign-pro'),
                'label_block' => true,
                'type'        => Controls_Manager::SELECT2,
                'multiple'    => true,
                'options'     => $list_ids,
            ]
        );

        $tag_ids = [];
        $tags = FluentCrmApi('tags')->all();
        foreach ($tags as $tag) {
            $tag_ids[$tag->id] = $tag->title;
        }

        $widget->add_control(
            'fluentcrm_tags',
            [
                'label'       => __('Select tags', 'fluentcampaign-pro'),
                'label_block' => true,
                'type'        => Controls_Manager::SELECT2,
                'multiple'    => true,
                'options'     => $tag_ids
            ]
        );

        $widget->add_control(
            'fluentcrm_add_only',
            [
                'label'       => __('Add Only', 'fluentcampaign-pro'),
                'description' => __('Only add new contacts, don\'t update existing ones.', 'fluentcampaign-pro'),
                'type'        => Controls_Manager::SWITCHER,
                'label_block' => false,
                'show_label'  => true,
            ]
        );

        $widget->add_control(
            'fluentcrm_send_double_optin',
            [
                'label'       => __('Double Opt-in', 'fluentcampaign-pro'),
                'description' => __('Send Double Optin Email for new or pending contacts. If you don\'t enable this then contact will be added as subscribed state.', 'fluentcampaign-pro'),
                'type'        => Controls_Manager::SWITCHER,
                'label_block' => false,
                'show_label'  => true,
            ]
        );

        $widget->end_controls_section();
    }

    private function get_fields()
    {
        $mainFields = FluentCrmApi('contacts')->getInstance()->mappables();
        unset($mainFields['ip']);
        unset($mainFields['source']);

        $formattedFields = [];

        foreach ($mainFields as $fieldKey => $fieldLabel) {
            $formattedFields[] = array(
                'remote_label'    => $fieldLabel,
                'remote_type'     => 'text',
                'remote_id'       => $fieldKey,
                'remote_required' => $fieldKey == 'email',
            );
        }

        foreach ((new CustomContactField)->getGlobalFields()['fields'] as $field) {
            $formattedFields[] = array(
                'remote_label'    => $field['label'],
                'remote_type'     => 'text',
                'remote_id'       => $field['slug'],
                'remote_required' => false,
            );
        }

        return $formattedFields;
    }

    public function run($record, $ajax_handler)
    {
        $settings = $record->get('form_settings');
        $sent_data = $record->get('sent_data');
        $mapFields = Arr::get($settings, 'fluentcrm_integration_fields_map', []);
        if (!$mapFields) {
            // For Old Style
            $mapFields = Arr::get($settings, 'fluentcrm_fields_map', []);
        }

        $processedData = [];
        foreach ($mapFields as $mapField) {
            if (!empty($mapField['local_id']) && !empty($sent_data[$mapField['local_id']])) {
                $processedData[$mapField['remote_id']] = $sent_data[$mapField['local_id']];
            }
        }

        if (empty($processedData['email']) || !is_email($processedData['email'])) {
            return;
        }

        if ($addedLists = Arr::get($settings, 'fluentcrm_lists', [])) {
            $processedData['lists'] = $addedLists;
        }

        if ($addedTags = Arr::get($settings, 'fluentcrm_tags', [])) {
            $processedData['tags'] = $addedTags;
        }

        $isDoubleOptin = Arr::get($settings, 'fluentcrm_send_double_optin', '') == 'yes';
        $addOnly = Arr::get($settings, 'fluentcrm_add_only', '') == 'yes';

        $exist = FluentCrmApi('contacts')->getContact($processedData['email']);

        if ($addOnly && $exist) {
            if ($isDoubleOptin && in_array($exist->status, ['pending', 'unsubscribed'])) {
                $exist->sendDoubleOptinEmail();
            }

            return false;
        }

        if ($isDoubleOptin) {
            if (!$exist || $exist->status != 'subscribed') {
                $processedData['status'] = 'pending';
            }
        }

        if (!$isDoubleOptin) {
            $processedData['status'] = 'subscribed';
        }

        $contact = FluentCrmApi('contacts')->createOrUpdate($processedData);

        if ($contact && $isDoubleOptin && $contact->status == 'pending') {
            $contact->sendDoubleOptinEmail();
        }

        return true;
    }

    public function on_export($element)
    {
        unset(
            $element['settings']['fluentcrm_fields_map'],
            $element['settings']['fluentcrm_integration_fields_map'],
            $element['settings']['fluentcrm_tags'],
            $element['settings']['fluentcrm_lists']
        );
    }

    /**
     * @param array $data
     *
     * @return void
     */

    public function handle_panel_request(array $data)
    {
    }

    protected function get_fields_map_control_options()
    {
        return [
            'default'   => $this->get_fields(),
            'condition' => [],
        ];
    }
}
