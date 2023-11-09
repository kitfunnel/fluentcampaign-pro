<?php

namespace FluentCampaign\App\Http\Controllers;

use FluentCrm\App\Http\Controllers\Controller;
use FluentCrm\App\Models\CampaignUrlMetric;
use FluentCampaign\App\Models\Sequence;
use FluentCampaign\App\Models\SequenceMail;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\Framework\Request\Request;

class SequenceMailController extends Controller
{

    public function get(Request $request, $sequenceId, $emailId = 0)
    {
        $with = $request->get('with');

        $sequence = [];
        if (in_array('sequence', $with)) {
            $sequence = Sequence::find($sequenceId);
        }

        $email = SequenceMail::getEmpty();
        if ($emailId) {
            $email = SequenceMail::find($emailId);
            if ($email->design_template == 'visual_builder') {
                $email->_visual_builder_design = fluentcrm_get_campaign_meta($emailId, '_visual_builder_design', true);
            }
        }

        return $this->sendSuccess([
            'sequence' => $sequence,
            'email'    => $email
        ]);

    }

    public function create(Request $request, $sequenceId = 0)
    {
        $data = $request->getJson('email');

        if (!is_array($data)) {
            return $this->sendError([
                'message' => 'Invalid Request'
            ]);
        }

        $emailData = Arr::only($data, [
            'title',
            'design_template',
            'email_subject',
            'email_pre_header',
            'email_body',
            'template_id',
            'settings',
            'utm_status',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content'
        ]);

        $emailData['title'] = $emailData['email_subject'];
        $emailData['template_id'] = intval(Arr::get($emailData, 'template_id'));

        $sequence = Sequence::findOrFail($sequenceId);
        $emailData['parent_id'] = $sequence->id;
        $email = SequenceMail::create($emailData);

        $mailerSettings = $sequence->settings['mailer_settings'];
        $email->updateMailerSettings($mailerSettings);

        if ($email->design_template == 'visual_builder' && !empty($data['_visual_builder_design'])) {
            fluentcrm_update_campaign_meta($email->id, '_visual_builder_design', $data['_visual_builder_design']);
        }

        $this->resetIndexes($sequenceId);

        return $this->sendSuccess([
            'message' => __('Sequence email has been created', 'fluentcampaign-pro'),
            'email'   => $email
        ]);
    }

    public function duplicate(Request $request, $sequenceID)
    {
        $email = SequenceMail::findOrFail($sequenceID);

        $emailData = [
            'title'            => __('[Duplicate] ', 'fluentcampaign-pro') . $email->title,
            'design_template'  => $email->design_template,
            'settings'         => $email->settings,
            'email_subject'    => $email->email_subject,
            'email_pre_header' => $email->email_pre_header,
            'email_body'       => $email->email_body,
            'template_id'      => $email->template_id,
            'parent_id'        => $email->parent_id,
            'utm_status'       => $email->utm_status,
            'utm_source'       => $email->utm_source,
            'utm_medium'       => $email->utm_medium,
            'utm_campaign'     => $email->utm_campaign,
            'utm_term'         => $email->utm_term,
            'utm_content'      => $email->utm_content
        ];
        $newEmail = SequenceMail::create($emailData);

        if ($email->design_template == 'visual_builder') {
            $design = fluentcrm_get_campaign_meta($email->id, '_visual_builder_design', true);
            fluentcrm_update_campaign_meta($newEmail->id, '_visual_builder_design', $design);
        }

        return $this->sendSuccess([
            'message' => __('Sequence email has been duplicated', 'fluentcampaign-pro'),
            'email'   => $email
        ]);
    }

    public function update(Request $request, $sequenceId, $emailId)
    {
        $data = $request->getJson('email');

        if (!is_array($data)) {
            return $this->sendError([
                'message' => 'invalid data type'
            ]);
        }

        $emailData = Arr::only($data, [
            'title',
            'design_template',
            'email_subject',
            'email_pre_header',
            'email_body',
            'template_id',
            'settings',
            'utm_status',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content'
        ]);

        $emailData['title'] = sanitize_text_field($emailData['email_subject']);
        $emailData['template_id'] = intval($emailData['template_id']);

        $sequence = Sequence::findOrFail($sequenceId);
        $mailerSettings = $sequence->settings['mailer_settings'];
        $emailData['settings']['mailer_settings'] = $mailerSettings;

        $email = SequenceMail::where('parent_id', $sequenceId)->findOrFail($emailId);

        $email->fill($emailData)->save();

        if ($email->design_template == 'visual_builder' && !empty($data['_visual_builder_design'])) {
            fluentcrm_update_campaign_meta($email->id, '_visual_builder_design', $data['_visual_builder_design']);
        }

        $this->resetIndexes($sequenceId);

        return $this->sendSuccess([
            'message' => __('Sequence email has been updated', 'fluentcampaign-pro'),
            'email'   => $email
        ]);
    }

    public function delete(Request $request, $sequenceId, $emailId)
    {
        SequenceMail::where('parent_id', $sequenceId)->where('id', $emailId)->delete();
        CampaignEmail::where('campaign_id', $emailId)->delete();
        CampaignUrlMetric::where('campaign_id', $emailId)->delete();

        fluentcrm_delete_campaign_meta($emailId, '');

        do_action('fluentcrm_sequence_email_deleted', $emailId);

        $this->resetIndexes($sequenceId);

        return $this->sendSuccess([
            'message' => __('Email sequence successfully deleted', 'fluentcampaign-pro')
        ]);
    }

    public function routeFallBackSequenceEmailCreateUpdate(Request $request)
    {
        $routeMethod = $request->get('route_method');

        if ($routeMethod == 'create') {
            return $this->create($request, $request->get('sequence_id'));
        }

        if ($routeMethod == 'update') {
            return $this->update($request, $request->get('sequence_id'), $request->get('mail_id'));
        }

        return $this->sendError([
            'message' => 'Invalid route_method'
        ]);
    }

    private function resetIndexes($sequenceId)
    {

    }
}
