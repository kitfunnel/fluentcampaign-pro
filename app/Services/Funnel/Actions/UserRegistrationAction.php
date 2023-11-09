<?php

namespace FluentCampaign\App\Services\Funnel\Actions;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Libs\Parser\Parser;
use FluentCrm\Framework\Support\Arr;

class UserRegistrationAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'user_registration_action';
        $this->priority = 99;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('WordPress', 'fluentcampaign-pro'),
            'title'       => __('Create WP User', 'fluentcampaign-pro'),
            'description' => __('Create WP User with a role if user is not already registered with contact email', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-create_wp_user',//fluentCrmMix('images/funnel_icons/create_wp_user.svg'),
            'settings'    => [
                'user_role'                    => 'subscriber',
                'send_user_notification_email' => 'no',
                'auto_generate_password'       => 'yes',
                'custom_password'              => '',
                'custom_username'              => '',
                'meta_properties'              => [
                    [
                        'data_key'   => '',
                        'data_value' => ''
                    ]
                ]
            ]
        ];
    }

    public function getBlockFields()
    {

        return [
            'title'     => __('Create WordPress User', 'fluentcampaign-pro'),
            'sub_title' => __('Create WP User with a role if user is not already registered with contact email', 'fluentcampaign-pro'),
            'fields'    => [
                'user_role'                    => [
                    'type'    => 'select',
                    'label'   => __('User Role', 'fluentcampaign-pro'),
                    'options' => FunnelHelper::getUserRoles()
                ],
                'auto_generate_password'       => [
                    'type'        => 'yes_no_check',
                    'label'       => __('Password', 'fluentcampaign-pro'),
                    'check_label' => __('Generate Password Automatically', 'fluentcampaign-pro')
                ],
                'custom_password'              => [
                    'type'        => 'input-text-popper',
                    'placeholder' => __('Custom Password', 'fluentcampaign-pro'),
                    'label'       => __('Provide Custom User Password', 'fluentcampaign-pro'),
                    'inline_help' => __('If you leave blank then auto generated password will be set', 'fluentcampaign-pro'),
                    'dependency'  => [
                        'depends_on' => 'auto_generate_password',
                        'operator'   => '=',
                        'value'      => 'no'
                    ]
                ],
                'custom_username' => [
                    'type'        => 'input-text-popper',
                    'placeholder' => 'Username (Optional)',
                    'label' => __('Custom Username (optional)', 'fluentcampaign-pro'),
                    'inline_help' => __('If you leave blank then email will be used as username. If provided username is not available then email address will be used for username', 'fluentcampaign-pro'),
                ],
                'meta_properties'              => [
                    'label'                  => __('User Meta Mapping', 'fluentcampaign-pro'),
                    'type'                   => 'text-value-multi-properties',
                    'data_key_label'         => __('User Meta Key', 'fluentcampaign-pro'),
                    'data_value_label'       => __('User Meta Value', 'fluentcampaign-pro'),
                    'data_value_placeholder' => __('Meta Value', 'fluentcampaign-pro'),
                    'data_key_placeholder'   => __('Meta key', 'fluentcampaign-pro'),
                    'help'                   => __('If you want to map user meta properties you can add that here. This is totally optional', 'fluentcampaign-pro'),
                    'value_input_type'       => 'text-popper'
                ],
                'send_user_notification_email' => [
                    'type'        => 'yes_no_check',
                    'label'       => __('User Notification', 'fluentcampaign-pro'),
                    'check_label' => __('Send WordPress user notification email', 'fluentcampaign-pro')
                ]
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {

        $user = get_user_by('email', $subscriber->email);
        if ($user) {
            $funnelMetric->notes = __('Funnel Skipped because user already exist in the database', 'fluentcampaign-pro');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        $settings = $sequence->settings;

        if ($settings['auto_generate_password'] == 'yes' || empty($settings['custom_password'])) {
            $password = wp_generate_password(8);
        } else {
            $password = Parser::parse($settings['custom_password'], $subscriber);
        }

        $userName = $subscriber->email;
        if (!empty($settings['custom_username'])) {
            $customUserName = Parser::parse($settings['custom_username'], $subscriber);
            if(username_exists($customUserName)) {
                $userName = $customUserName;
            }
        }

        $userId = wp_create_user(sanitize_user($userName), $password, $subscriber->email);

        if (is_wp_error($userId)) {
            $funnelMetric->notes = __('Error when creating new User. ', 'fluentcampaign-pro') . $userId->get_error_message();
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        if ($userRole = Arr::get($settings, 'user_role')) {
            $user = new \WP_User($userId);
            $user->set_role($userRole);
        }

        $userMetas = [
            'first_name' => $subscriber->first_name,
            'last_name'  => $subscriber->last_name
        ];

        foreach ($settings['meta_properties'] as $pair) {
            if (empty($pair['data_key']) || empty($pair['data_value'])) {
                continue;
            }
            $userMetas[sanitize_text_field($pair['data_key'])] = Parser::parse($pair['data_value'], $subscriber);
        }

        $userMetas = array_filter($userMetas);
        foreach ($userMetas as $metaKey => $metaValue) {
            update_user_meta($userId, $metaKey, $metaValue);
        }

        if (Arr::get($settings, 'send_user_notification_email') == 'yes') {
            wp_send_new_user_notifications($userId, 'user');
        }

        $subscriber->user_id = $userId;
        $subscriber->save();
    }

}
