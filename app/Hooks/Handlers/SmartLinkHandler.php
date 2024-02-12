<?php

namespace FluentCampaign\App\Hooks\Handlers;

use FluentCampaign\App\Models\SmartLink;
use FluentCrm\Framework\Support\Arr;

class SmartLinkHandler
{
    public function handleClick($slug, $contact = null)
    {
        $smartLink = SmartLink::where('short', $slug)->first();

        if (!$smartLink) {
            return;
        }

        if (!$contact) {
            $contact = fluentcrm_get_current_contact();
        }

        if (!$contact) {
            $smartLink->all_clicks = $smartLink->all_clicks + 1;
            $smartLink->save();
            wp_redirect($smartLink->target_url);
            exit();
        }

        if ($tags = Arr::get($smartLink->actions, 'tags')) {
            $contact->attachTags($tags);
        }

        if ($lists = Arr::get($smartLink->actions, 'lists')) {
            $contact->attachLists($lists);
        }

        if ($removeTags = Arr::get($smartLink->actions, 'remove_tags')) {
            $contact->detachTags($removeTags);
        }

        if ($removeLists = Arr::get($smartLink->actions, 'remove_lists')) {
            $contact->detachLists($removeLists);
        }

        $smartLink->contact_clicks = $smartLink->contact_clicks + 1;
        $smartLink->all_clicks = $smartLink->all_clicks + 1;
        $smartLink->save();

        if (Arr::get($smartLink->actions, 'auto_login') === 'yes') {
            $this->makeAutoLogin($contact);
        }

        $targetUrl = $smartLink->target_url;

        if (strpos($targetUrl, '{{')) {
            // we have smart codes
            $targetUrl = apply_filters('fluent_crm/parse_campaign_email_text', $targetUrl, $contact);
            $targetUrl = esc_url_raw($targetUrl);
        }

        do_action('fluent_crm/smart_link_clicked_by_contact', $smartLink, $contact);

        nocache_headers();
        wp_redirect($targetUrl, 307);
        exit();
    }


    private function makeAutoLogin($contact)
    {
        if (is_user_logged_in()) {
            return false;
        }

        $user = get_user_by('email', $contact->email);

        if (!$user) {
            return false;
        }

        $willAllowLogin = apply_filters('fluent_crm/will_make_auto_login', did_action('fluent_crm/smart_link_verified'), $contact);
        if (!$willAllowLogin) {
            return false;
        }

        if ($user->has_cap('publish_posts') && !apply_filters('fluent_crm/enable_high_level_auto_login', false, $contact)) {
            return false;
        }

        $currentContact = fluentcrm_get_current_contact();

        if (!$currentContact || $currentContact->id != $contact->id) {
            return false;
        }

        add_filter('authenticate', array($this, 'allowProgrammaticLogin'), 10, 3);    // hook in earlier than other callbacks to short-circuit them
        $user = wp_signon(array(
                'user_login'    => $user->user_login,
                'user_password' => ''
            )
        );
        remove_filter('authenticate', array($this, 'allowProgrammaticLogin'), 10, 3);

        if ($user instanceof \WP_User) {
            wp_set_current_user($user->ID, $user->user_login);
            if (is_user_logged_in()) {
                return true;
            }
        }

        return false;
    }

    public function allowProgrammaticLogin($user, $username, $password)
    {
        return get_user_by('login', $username);
    }
}
