<?php

namespace FluentCampaign\App\Hooks\Handlers;


use FluentCrm\App\Models\Subscriber;

class ExtendedSmartCodesHandler
{
    public function register()
    {
        add_filter('fluent_crm/smartcode_groups', array($this, 'pushUserCodes'));
        add_filter('fluent_crm/smartcode_group_callback_wp_user', array($this, 'parseUserSmartCode'), 10, 4);
    }

    public function pushUserCodes($codes)
    {
        $codes[] = [
            'key'        => 'wp_user',
            'title'      => 'WP User',
            'shortcodes' => [
                '{{wp_user.display_name}}'        => 'User\'s Display Name',
                '{{wp_user.user_login}}'          => 'User Login (username)',
                '##wp_user.password_reset_url## ' => 'Password Reset URL (on button / link)',
                '{{wp_user.password_reset_url}} ' => 'Password Reset URL (as plain text)',
                '{{wp_user.meta.META_KEY}}'       => 'User Meta Data',
            ]
        ];

        return $codes;
    }

    public function parseUserSmartCode($code, $valueKey, $defaultValue, $subscriber)
    {
        $wpUser = $subscriber->getWpUser();

        if (!$wpUser) {
            return $defaultValue;
        }

        $userKeys = [
            'ID',
            'user_login',
            'user_nicename',
            'user_url',
            'user_registered',
            'display_name'
        ];

        if (in_array($valueKey, $userKeys)) {
            return $wpUser->{$valueKey};
        }

        if (strpos($valueKey, 'meta.') === 0) {
            $metaKey = str_replace('meta.', '', $valueKey);
            $metaValue = get_user_meta($wpUser->ID, $metaKey, true);
            if (!$metaValue) {
                return $defaultValue;
            }
            return $metaValue;
        }

        if ($valueKey == 'password_reset_url') {
            $key = get_password_reset_key($wpUser);
            if (is_wp_error($key)) {
                return wp_lostpassword_url();
            }

            return network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($wpUser->user_login), 'login') . '&wp_lang=' . get_user_locale($wpUser);
        }

        return $defaultValue;
    }
}
