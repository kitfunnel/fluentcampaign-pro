<?php

namespace FluentCampaign\App\Models;

use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\Model;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;

class Sequence extends Model
{
    protected $table = 'fc_campaigns';

    protected $guarded = ['id'];

    protected $fillable = [
        'title',
        'settings',
        'design_template'
    ];

    protected static $type = 'email_sequence';

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->email_body = $model->email_body ? $model->email_body : '';
            $model->status = $model->status ?: 'draft';
            $model->type = self::$type;
            $model->design_template = $model->design_template ? $model->design_template : 'simple';
            $model->slug = $model->slug ?: sanitize_title($model->title, '', 'preview');
            $model->created_by = $model->created_by ?: get_current_user_id();
            $model->settings = $model->settings ? $model->settings : [
                'mailer_settings' => [
                    'from_name'      => '',
                    'from_email'     => '',
                    'reply_to_name'  => '',
                    'reply_to_email' => '',
                    'is_custom'      => 'no'
                ]
            ];
        });

        static::addGlobalScope('type', function ($builder) {
            $builder->where('fc_campaigns.type', '=', self::$type);
        });
    }

    public function setSlugAttribute($slug)
    {
        $this->attributes['slug'] = \sanitize_title($slug, '', 'preview');
    }

    public function setSettingsAttribute($settings)
    {
        $this->attributes['settings'] = \maybe_serialize($settings);
    }

    public function getSettingsAttribute($settings)
    {
        return \maybe_unserialize($settings);
    }

    public function getRecipientsCountAttribute($recipientsCount)
    {
        return (int)$recipientsCount;
    }

    public function scopeOfType($query, $status)
    {
        return $query->where('status', $status);
    }

    public function subscribe($subscribers)
    {
        $sequenceEmails = SequenceMail::where('parent_id', $this->id)
            ->orderBy('delay', 'ASC')
            ->get();

        if ($sequenceEmails->isEmpty()) {
            return [];
        }

        $firstSequence = $sequenceEmails[0];
        $nextSequence = null;
        $immediateSequences = [];

        $offset = $firstSequence->delay;

        foreach ($sequenceEmails as $sequence) {
            if ($sequence->delay == $firstSequence->delay) {
                $immediateSequences[] = $sequence;
            } else {
                if (!$nextSequence) {
                    $nextSequence = $sequence;
                }
                if ($sequence->delay < $nextSequence->delay) {
                    $nextSequence = $sequence;
                }
            }
        }

        return $this->attachEmails($subscribers, $immediateSequences, $nextSequence, false, $offset);
    }

    public function attachEmails($subscribers, $immediateSequences, $nextSequence, $tracker = false, $offset = 0)
    {
        $scheduledEmails = [];
        $campaignUrl = [];
        $time = current_time('mysql');

        $parentCampaignId = false;

        foreach ($immediateSequences as $sequenceEmail) {
            $emailBody = $sequenceEmail->email_body;
            if (fluentcrmTrackClicking()) {
                $campaignUrl[$sequenceEmail->id] = Helper::urlReplaces($emailBody);
            }
            $parentCampaignId = $sequenceEmail->parent_id;

            $scheduledTime = $this->guessScheduledTime($sequenceEmail, $offset);

            $scheduledEmails[] = [
                'campaign_id'   => $sequenceEmail->id,
                'status'        => 'scheduled',
                'email_type'    => 'sequence_mail',
                'created_at'    => $time,
                'email_headers' => Helper::getMailHeadersFromSettings($sequenceEmail->settings['mailer_settings']),
                'email_subject' => $sequenceEmail->email_subject,
                'email_body'    => $emailBody,
                'scheduled_at'  => $scheduledTime,
                'updated_at'    => $time,
                'delay'         => $sequenceEmail->delay
            ];
        }

        $insertIds = [];

        foreach ($subscribers as $subscriber) {
            if (!$subscriber) {
                continue;
            }

            $trackerData = [];

            $notes = [];

            foreach ($scheduledEmails as $scheduledEmail) {
                $sequenceEmailId = $scheduledEmail['campaign_id'];
                $urls = [];
                if (!empty($campaignUrl[$sequenceEmailId])) {
                    $urls = $campaignUrl[$sequenceEmailId];
                }

                $notes[] = [
                    'email'        => $scheduledEmail['email_subject'],
                    'campaign_id'  => $sequenceEmailId,
                    'scheduled_at' => $scheduledEmail['scheduled_at'],
                    'time' => current_time('mysql')
                ];

                $emailBody = apply_filters('fluent_crm/parse_campaign_email_text', $scheduledEmail['email_body'], $subscriber);

                $scheduledEmail['subscriber_id'] = $subscriber->id;
                $scheduledEmail['email_address'] = $subscriber->email;
                $scheduledEmail['email_subject'] = apply_filters('fluent_crm/parse_campaign_email_text', $scheduledEmail['email_subject'], $subscriber);

                if (!$urls) {
                    $scheduledEmail['email_body'] = $emailBody;
                }

                $delay = ($scheduledEmail['delay']) ? $scheduledEmail['delay'] : 0;
                unset($scheduledEmail['delay']);

                $inserted = CampaignEmail::create($scheduledEmail);

                $insertIds[] = $inserted->id;

                $updateData = [
                    'email_hash' => Helper::generateEmailHash($inserted->id)
                ];

                if ($urls) {
                    $updateData['email_body'] = Helper::attachUrls($emailBody, $urls, $inserted->id);
                }

                CampaignEmail::where('id', $inserted->id)->update($updateData);

                $trackerData = [
                    'subscriber_id'       => $subscriber->id,
                    'campaign_id'         => $parentCampaignId,
                    'last_sequence_id'    => $scheduledEmail['campaign_id'],
                    'next_sequence_id'    => ($nextSequence) ? $nextSequence->id : NULL,
                    'status'              => ($nextSequence) ? 'active' : 'completed',
                    'last_executed_time'  => current_time('mysql'),
                    'next_execution_time' => ($nextSequence) ? date('Y-m-d H:i:s', strtotime($scheduledEmail['scheduled_at']) + $nextSequence->delay - $delay) : NULL
                ];
            }


            if ($trackerData) {

                if (!$tracker) {
                    $tracker = SequenceTracker::where('subscriber_id', $subscriber->id)
                        ->where('campaign_id', $trackerData['campaign_id'])
                        ->first();
                }

                if ($tracker) {
                    if ($tracker->notes) {
                        $notes = array_merge($tracker->notes, $notes);
                    }

                    $trackerData['notes'] = maybe_serialize($notes);

                    SequenceTracker::where('id', $tracker->id)
                        ->update($trackerData);
                } else {
                    $trackerData['notes'] = $notes;
                    SequenceTracker::create($trackerData);
                }

                if ($trackerData['status'] == 'completed') {
                    do_action('fluentcrm_email_sequence_completed', $subscriber->id, $trackerData['campaign_id']);
                }
            }

        }

        return $insertIds;
    }

    public function unsubscribe($subscriberIds, $note = '')
    {
        $sequenceEmails = SequenceMail::where('parent_id', $this->id)
            ->orderBy('delay', 'ASC')
            ->get();

        if (!$sequenceEmails) {
            return;
        }

        SequenceTracker::where('campaign_id', $this->id)
            ->whereIn('subscriber_id', $subscriberIds)
            ->update([
                'status' => 'cancelled'
            ]);

        $sequenceEmailIds = $sequenceEmails->pluck('id')->toArray();

        CampaignEmail::whereIn('campaign_id', $sequenceEmailIds)
            ->where('status', 'scheduled')
            ->where('email_type', 'sequence_mail')
            ->whereIn('subscriber_id', $subscriberIds)
            ->update([
                'status' => 'cancelled',
                'note'   => $note
            ]);
    }

    public function guessScheduledTime($sequenceEmail, $offsetSeconds = 0)
    {
        $isSelectedDays = Arr::get($sequenceEmail->settings, 'timings.selected_days_only') == 'yes';
        $sendingDate = current_time('Y-m-d');

        $offsetAdded = false;

        if ($isSelectedDays) {
            $allowedDays = Arr::get($sequenceEmail->settings, 'timings.allowed_days', []);
            if (count($allowedDays)) {
                $loopCounter = 0;
                $dateTimeStamp = current_time('timestamp') + $offsetSeconds;
                $offsetAdded = true; // we have added offset seconds
                while (!in_array(date('D', $dateTimeStamp), $allowedDays) && $loopCounter < 8) {
                    $dateTimeStamp += 86400;
                    $loopCounter++;
                }
                // let's guess the date only without time
                $sendingDate = date('Y-m-d', $dateTimeStamp);
            }
        }

        $sendingTimes = Arr::get($sequenceEmail->settings, 'timings.sending_time', []); //  date Range From sequence like  Array([0] => 16:35, [1] => 17:35)

        if (is_array($sendingTimes)) {
            $sendingTimes = array_filter($sendingTimes);
            if (!$sendingTimes || count($sendingTimes) != 2) {
                $dateTime = $sendingDate . ' ' . $sendingTimes[0];
                $dateTime = $this->maybeAddOffsetSeconds($dateTime, $offsetAdded, $offsetSeconds);
            } else {
                $diff = absint(strtotime($sendingTimes[1]) - strtotime($sendingTimes[0]));
                $baseDate = current_time('Y-m-d ' . $sendingTimes[0]);
                $sendingDate = $this->maybeAddOffsetSeconds($sendingDate, $offsetAdded, $offsetSeconds, true);

                $dateTime = $sendingDate . ' ' . date('H:i:s', strtotime($baseDate) + mt_rand(0, $diff));
            }

        } else {
            if (empty($sendingTimes)) {
                $dateTime = $sendingDate . ' ' . date('H:i:s', current_time('timestamp'));
            } else {
                $dateTime = $sendingDate . ' ' . $sendingTimes;
            }
            $dateTime = $this->maybeAddOffsetSeconds($dateTime, $offsetAdded, $offsetSeconds);
        }

        return $dateTime;

    }

    public function stat()
    {
        $campaignMails = SequenceMail::select('id')
            ->where('parent_id', $this->id)
            ->get();

        $subscribers = SequenceTracker::where('campaign_id', $this->id)->count();
        $data = [
            'emails'      => count($campaignMails),
            'subscribers' => $subscribers,
            'revenue'     => [
                'amount'   => 0,
                'currency' => ''
            ]
        ];

        $amounts = 0;
        $currencyItem = '';

        foreach ($campaignMails as $campaignMail) {
            if ($revenue = fluentcrm_get_campaign_meta($campaignMail->id, '_campaign_revenue')) {
                foreach ($revenue->value as $currency => $cents) {
                    $amounts += $cents / 100;
                    $currencyItem = $currency;
                }
            }
        }

        $data['revenue'] = [
            'amount'   => number_format($amounts, (is_int($amounts)) ? 0 : 2),
            'currency' => $currencyItem
        ];

        return $data;
    }

    public function maybeAttachSubscribersByIds($subscriberIds, $checkStatus = true)
    {

    }

    private function maybeAddOffsetSeconds($date, $offsetAlreadyAdded, $offsetSeconds, $onlyDate = false)
    {
        if (!$offsetAlreadyAdded && $offsetSeconds) { // If offset already added then no need to add again but if not added then we have to add offset .
            $timestamp = strtotime($date) + $offsetSeconds;
            $date = $onlyDate ? date('Y-m-d', $timestamp) : date('Y-m-d H:i:s', $timestamp);
        }
        return $date;
    }
}
