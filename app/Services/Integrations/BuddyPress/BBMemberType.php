<?php

namespace FluentCampaign\App\Services\Integrations\BuddyPress;

use FluentCampaign\App\Services\MetaFormBuilder;
use FluentCrm\App\Models\Tag;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class BBMemberType
{
    protected $hookedFired = false;

    protected $disableMetaHeading = false;

    public function init()
    {
        add_action('bp_set_member_type', array($this, 'membershipAdded'), 10, 2);
        add_action('bp_members_admin_load', array($this, 'processMemberTypeUpdate'), 1);

        if (defined('BP_PLATFORM_VERSION')) {
            $memberPostType = bp_get_member_type_post_type();
            add_action('add_meta_boxes_' . $memberPostType, array($this, 'addPlatformMetabox'));
            add_action('save_post_' . $memberPostType, array($this, 'platformSaveMeta'));
        } else {
            add_action('bp_member_type_edit_form_fields', array($this, 'addMemberTypeMeta'), 99);
            add_action('bp_member_type_add_form_fields', array($this, 'addMemberTypeMeta'), 99);
            add_action('bp_type_updated', array($this, 'updateSettings'), 99, 2);
            add_action('bp_type_inserted', array($this, 'addSettings'), 99, 2);
        }
    }

    public function addMemberTypeMeta($term)
    {
        $settings = [
            'attach_tags' => [],
            'remove_tag'  => ''
        ];
        if ($term && is_object($term)) {
            $crmSettings = get_term_meta($term->term_id, '_fluentcrm_settings', true);
            if ($crmSettings) {
                $settings = wp_parse_args($crmSettings, $settings);
            }
        }

        $tags = Tag::get();
        ?>
        <?php if (!$this->disableMetaHeading): ?>
        <tr>
            <th colspan="2">
                <h3><?php esc_html_e('FluentCRM Settings', 'fluentcampaign-pro'); ?></h3>
            </th>
        </tr>
    <?php endif; ?>
        <tr>
            <th><label for="fluentcrm_add_tags_member_type"><?php _e('Apply Tags', 'fluentcampaign-pro'); ?></label>
            </th>
            <td>
                <input type="hidden" value="yes" name="_has_fcrm"/>
                <select id="fluentcrm_add_tags_member_type" placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>" style="width:100%;"
                        class="fc_multi_select"
                        name="_fcrm[attach_tags][]" multiple="multiple">
                    <?php foreach ($tags as $tag): ?>
                        <option
                            value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['attach_tags'])) {
                            esc_attr_e('selected', 'fluentcampaign-pro');
                        } ?> ><?php echo $tag->title; ?></option>
                    <?php endforeach; ?>
                </select>
                <p><?php esc_html_e('Selected tags will be added to the member on joining this member type', 'fluentcampaign-pro'); ?></p>
                <label style="margin-top: 10px; margin-bottom: 5px; display: block;"
                       for="fluentcrm_remove_tags_member_type">
                    <input id="fluentcrm_remove_tags_member_type" value="yes" type="checkbox"
                           name="_fcrm[remove_tag]" <?php checked($settings['remove_tag'], 'yes'); ?>>
                    <?php _e('Remove Tags on leave defined in "Apply Tags"', 'fluentcampaign-pro'); ?></label>
            </td>
        </tr>
        <?php
        (new MetaFormBuilder())->initMultiSelect('.fc_multi_select', 'Select Tags');
    }

    public function updateSettings($termId, $taxonomy)
    {
        if ($taxonomy != bp_get_member_type_tax_name() || !isset($_POST['_has_fcrm'])) {
            return;
        }

        $defaults = [
            'attach_tags' => [],
            'remove_tag'  => ''
        ];

        $settings = Arr::get($_REQUEST, '_fcrm', []);

        $settings = wp_parse_args($settings, $defaults);
        update_term_meta($termId, '_fluentcrm_settings', $settings);
    }

    public function addSettings($type_term, $type_taxonomy)
    {
        $this->updateSettings($type_term['term_id'], $type_taxonomy);
    }

    public function processMemberTypeUpdate()
    {
        if (!isset($_POST['bp-member-type-nonce'])) {
            return;
        }

        $this->hookedFired = true;

        $userId = get_current_user_id();
        if (isset($_REQUEST['user_id'])) {
            $userId = $_REQUEST['user_id'];
        }

        $membershipTypes = (array)Arr::get($_REQUEST, 'bp-members-profile-member-type', []);
        $existingTypes = bp_get_member_type($userId, false);

        $removingTypes = [];
        if ($existingTypes && $membershipTypes) {
            $removingTypes = array_diff($existingTypes, $membershipTypes);
        } else if (!$membershipTypes) {
            $removingTypes = $existingTypes;
        }

        add_action('bp_set_member_type', function ($userId, $memberTypes) use ($removingTypes, $existingTypes) {
            $memberTypes = (array)$memberTypes;
            $existingTypes = (array)$existingTypes;

            if ($memberTypes && array_diff($memberTypes, $existingTypes)) {
                foreach ($memberTypes as $memberType) {
                    $term = get_term_by('slug', $memberType, 'bp_member_type');

                    if ($term) {
                        $settings = get_term_meta($term->term_id, '_fluentcrm_settings', true);
                        if ($settings && !empty($settings['attach_tags'])) {
                            $contact = $this->getContact($userId);
                            $contact->attachTags($settings['attach_tags']);
                        }
                    }
                }
            }

            if ($removingTypes) {
                foreach ($removingTypes as $removingType) {
                    $term = get_term_by('slug', $removingType, 'bp_member_type');
                    if ($term) {
                        $settings = get_term_meta($term->term_id, '_fluentcrm_settings', true);
                        if ($settings && !empty($settings['attach_tags']) && !empty($settings['remove_tag'])) {
                            $contact = $this->getContact($userId, false);
                            if ($contact) {
                                $contact->detachTags($settings['attach_tags']);
                            }
                        }
                    }
                }
            }
        }, 10, 2);

    }

    public function getContact($userId, $create = true)
    {
        static $contacts = [];

        if (isset($contacts[$userId])) {
            return $contacts[$userId];
        }

        $contact = FluentCrmApi('contacts')->getContactByUserRef($userId);

        if ($contact) {
            $contacts[$userId] = $contact;
            return $contact;
        } else if (!$create) {
            return false; // We will not create a contact here
        }

        $subscriberData = FunnelHelper::prepareUserData($userId);
        $contact = FunnelHelper::createOrUpdateContact($subscriberData);
        $contacts[$userId] = $contact;
        return $contact;
    }

    public function membershipAdded($userId, $memberTypes)
    {
        if ($this->hookedFired) {
            return;
        }

        $memberTypes = (array)$memberTypes;

        if ($memberTypes) {
            foreach ($memberTypes as $memberType) {
                $term = get_term_by('slug', $memberType, 'bp_member_type');

                if ($term) {
                    $settings = get_term_meta($term->term_id, '_fluentcrm_settings', true);
                    if ($settings && !empty($settings['attach_tags'])) {
                        $contact = $this->getContact($userId);
                        if ($contact) {
                            $contact->attachTags($settings['attach_tags']);
                        }
                    }
                }
            }
        }
    }

    /*
     * Platform Specific Methods
     */

    public function addPlatformMetabox()
    {
        add_meta_box('fcrm-type-bp-settings', __('FluentCRM Settings', 'fluentcampaign-pro'), array($this, 'platFormBox'), null, 'side', 'low');
    }

    public function platFormBox($post)
    {
        $this->disableMetaHeading = true;
        $key = bp_get_member_type_key($post->ID);
        $term = get_term_by('slug', $key, 'bp_member_type');

        if ($term) {
            $this->addMemberTypeMeta($term);
        } else {
            echo '<p>'.__('Please add at least one member to this Member type to add FluentCRM Tag Settings', 'fluentcampaign-pro').'</p>';
        }
    }

    public function platformSaveMeta($postId)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $key = bp_get_member_type_key($postId);
        $term = get_term_by('slug', $key, bp_get_member_type_tax_name());

        if (!$term) {
            return;
        }

        $this->updateSettings($term->term_id, bp_get_member_type_tax_name());
    }

}
