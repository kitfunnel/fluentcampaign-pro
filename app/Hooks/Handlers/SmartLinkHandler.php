<?php

namespace FluentCampaign\App\Hooks\Handlers;

use FluentCampaign\App\Models\SmartLink;
use FluentCrm\Framework\Support\Arr;

class SmartLinkHandler
{
   public function handleClick($slug, $contact = null)
   {
       $smartLink = SmartLink::where('short', $slug)->first();

       if(!$smartLink) {
           return;
       }

       if(!$contact) {
           $contact = fluentcrm_get_current_contact();
       }

       if(!$contact) {
           $smartLink->all_clicks = $smartLink->all_clicks + 1;
           $smartLink->save();
           wp_redirect($smartLink->target_url);
           exit();
       }

       if($tags = Arr::get($smartLink->actions, 'tags')) {
           $contact->attachTags($tags);
       }

       if($lists = Arr::get($smartLink->actions, 'lists')) {
           $contact->attachLists($lists);
       }

       if($removeTags = Arr::get($smartLink->actions, 'remove_tags')) {
           $contact->detachTags($removeTags);
       }

       if($removeLists = Arr::get($smartLink->actions, 'remove_lists')) {
           $contact->detachLists($removeLists);
       }

       $smartLink->contact_clicks = $smartLink->contact_clicks + 1;
       $smartLink->all_clicks = $smartLink->all_clicks + 1;
       $smartLink->save();

       $targetUrl = $smartLink->target_url;

       if(strpos($targetUrl, '{{')) {
           // we have smart codes
           $targetUrl = apply_filters('fluent_crm/parse_campaign_email_text', $targetUrl, $contact);
           $targetUrl = str_replace(['&amp;', '+'], ['&', '%2B'], $targetUrl);
       }

       do_action('fluent_crm/smart_link_clicked_by_contact', $smartLink, $contact);

       nocache_headers();
       wp_redirect($targetUrl, 307);
       exit();
   }
}
