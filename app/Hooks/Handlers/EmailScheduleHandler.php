<?php

namespace FluentCampaign\App\Hooks\Handlers;

use FluentCampaign\App\Models\Sequence;
use FluentCampaign\App\Models\SequenceMail;
use FluentCampaign\App\Models\SequenceTracker;
use FluentCrm\App\Models\Subscriber;

class EmailScheduleHandler
{
    private $subscribersCache = [];

    private $sequenceCache = [];

    public function handle()
    {

        $lastRun = get_option('_fc_last_sequence_run', 0);
        if ($lastRun && (time() - $lastRun) < 60) {
            return;
        }

        update_option('_fc_last_sequence_run', time(), 'no');

        $startTime = time();
        $processTrackers = SequenceTracker::ofNextTrackers()->limit(200)->get();

        $sequenceModel = new Sequence();

        foreach ($processTrackers as $tracker) {
            if (time() - $startTime > 50) {
                return;
            }

            $nextItems = $this->getNextItems($tracker);
            if (!empty($nextItems['currents'])) {
                $subscriber = $this->getSubscriber($tracker->subscriber_id);

                if (!$subscriber) {
                    $tracker->status = 'completed';
                    $tracker->save();
                    continue;
                } else if ($subscriber->status != 'subscribed') {
                    $tracker->status = 'cancelled';
                    $tracker->save();
                    continue;
                }
                
                $sequenceModel->attachEmails([$subscriber], $nextItems['currents'], $nextItems['next'], $tracker);
            } else {
                $tracker->status = 'completed';
                $tracker->save();
                do_action('fluentcrm_email_sequence_completed', $tracker->subscriber_id, $tracker->campaign_id);
            }
        }

        if ((time() - $startTime) < 40) {
            usleep(random_int(100, 500000)); // sleep upto 0.5 seconds
            (new RecurringCampaignHandler())->maybePushNewEmailDraft();
        }
    }

    private function getSubscriber($id)
    {
        if (!isset($this->subscribersCache[$id])) {
            $this->subscribersCache[$id] = Subscriber::find($id);
        }

        return $this->subscribersCache[$id];
    }

    private function getNextItems($tracker)
    {
        $lastSequenceId = $tracker->last_sequence_id;

        if (isset($this->sequenceCache[$lastSequenceId])) {
            return $this->sequenceCache[$lastSequenceId];
        }

        $lastSequence = $tracker->last_sequence;

        if ($lastSequence) {
            $sequenceEmails = SequenceMail::where('parent_id', $tracker->campaign_id)
                ->where('delay', '>', $lastSequence->delay)
                ->orderBy('delay', 'ASC')
                ->get();
        } else if ($nextItem = $tracker->next_sequence) {
            $sequenceEmails = SequenceMail::where('parent_id', $tracker->campaign_id)
                ->where('delay', '>=', $nextItem->delay)
                ->orderBy('delay', 'ASC')
                ->get();
        } else {
            $calculatedDelay = current_time('timestamp') - strtotime($tracker->created_at) - 50;

            $sequenceEmails = SequenceMail::where('parent_id', $tracker->campaign_id)
                ->where('delay', '>', $calculatedDelay)
                ->orderBy('delay', 'ASC')
                ->get();
        }

        if ($sequenceEmails->isEmpty()) {
            return [
                'next'     => null,
                'currents' => []
            ];
        }

        $firstSequence = $sequenceEmails[0];
        $immediateSequences = [];
        $nextSequence = null;

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

        $this->sequenceCache[$lastSequenceId] = [
            'next'     => $nextSequence,
            'currents' => $immediateSequences
        ];

        return $this->sequenceCache[$lastSequenceId];
    }
}
