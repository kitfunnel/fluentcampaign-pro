<?php

namespace FluentCampaign\App\Services\Integrations\BuddyPress;

use FluentCampaign\App\Services\MetaFormBuilder;
use FluentCrm\App\Models\Tag;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class Group
{
    public function init()
    {
        add_action('bp_groups_admin_load', function () {
            add_meta_box('bp_fluentcrm_group_settings', __('FluentCRM', 'fluentcampaign-pro'), array($this, 'addMeta'), get_current_screen()->id, 'side', 'core', 'low');
        });
        add_action('bp_group_admin_edit_after', array($this, 'saveMeta'));

        add_action('groups_join_group', array($this, 'onJoin'), 20, 2);
        add_action('groups_leave_group', array($this, 'onLeave'), 20, 2);
        add_action('groups_member_after_remove', array($this, 'onRemove'), 20, 3);

        add_action('groups_accept_invite', function ($userId, $groupId) {
            $this->onJoin($groupId, $userId);
        }, 10, 2);

    }

    public function addMeta($item)
    {

        $settings = BBHelper::getGroupSettings($item->id);
        $tags = Tag::get();

        ?>
        <div class="bp-groups-settings-section" id="bp-groups-settings-section-fcrm">
            <input type="hidden" value="yes" name="_has_fcrm"/>
            <fieldset>
                <legend><?php _e('Apply Tags', 'fluentcampaign-pro'); ?></legend>
                <select placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>" style="width:100%;"
                        class="fc_multi_select"
                        name="_fcrm[attach_tags][]" multiple="multiple">
                    <?php foreach ($tags as $tag): ?>
                        <option
                            value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['attach_tags'])) {
                            esc_attr_e('selected', 'fluentcampaign-pro');
                        } ?> ><?php echo $tag->title; ?></option>
                    <?php endforeach; ?>
                </select>
                <p><?php esc_html_e('Selected tags will be added to the member on joining', 'fluentcampaign-pro'); ?></p>
                <label style="margin-top: 0px; margin-bottom: 5px; display: block;"
                       for="fluentcrm_remove_tags_member_type">
                    <input id="fluentcrm_remove_tags_member_type" value="yes" type="checkbox"
                           name="_fcrm[remove_tag]" <?php checked($settings['remove_tag'], 'yes'); ?>>
                    <?php _e('Remove Tags on leave defined in "Apply Tags"', 'fluentcampaign-pro'); ?></label>
            </fieldset>
        </div>
        <?php

        (new MetaFormBuilder())->initMultiSelect('.fc_multi_select', 'Select Tags');
    }

    public function saveMeta($groupId)
    {
        if (isset($_REQUEST['_has_fcrm'])) {
            $settings = Arr::get($_REQUEST, '_fcrm', []);
            $defaults = [
                'attach_tags' => [],
                'remove_tag'  => ''
            ];
            $settings = wp_parse_args($settings, $defaults);
            groups_update_groupmeta($groupId, '_fcrm_config', $settings);
        }
    }

    public function onJoin($groupId, $userId)
    {
        $settings = BBHelper::getGroupSettings($groupId);
        if (empty($settings['attach_tags'])) {
            return false;
        }

        $subscriberData = FunnelHelper::prepareUserData($userId);
        $subscriberData['tags'] = $settings['attach_tags'];

        return FluentCrmApi('contacts')->createOrUpdate($subscriberData);
    }

    public function onLeave($groupId, $userId)
    {
        $settings = BBHelper::getGroupSettings($groupId);

        if (empty($settings['attach_tags']) || empty($settings['remove_tag'])) {
            return false;
        }

        $contact = FluentCrmApi('contacts')->getContactByUserRef($userId);
        if (!$contact) {
            return false;
        }

        $contact->detachTags($settings['attach_tags']);
        return true;
    }

    public function onRemove($args)
    {
        $this->onLeave($args->group_id, $args->user_id);
    }
}
