<?php

namespace FluentCampaign\App\Models;

use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\CampaignUrlMetric;
use FluentCrm\App\Models\Model;
use FluentCrm\App\Services\Helper;

class RecurringMail extends Model
{
    protected $table = 'fc_campaigns';

    protected $guarded = ['id'];

    protected static $type = 'recurring_mail';

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $defaultTemplate = $model->design_template ? $model->design_template : Helper::getDefaultEmailTemplate();
            $model->email_body = $model->email_body ?: '';
            $model->status = $model->status ?: 'draft';
            $model->type = static::$type;
            $model->design_template = $defaultTemplate;
            $model->slug = $model->slug ?: sanitize_title($model->title, '', 'preview');
            $model->created_by = $model->created_by ?: get_current_user_id();
            $model->settings = $model->settings ?: [
                'mailer_settings'     => [
                    'from_name'      => '',
                    'from_email'     => '',
                    'reply_to_name'  => '',
                    'reply_to_email' => '',
                    'is_custom'      => 'no'
                ],
                'subscribers'         => [
                    [
                        'list' => 'all',
                        'tag'  => 'all'
                    ]
                ],
                'excludedSubscribers' => [
                    [
                        'list' => null,
                        'tag'  => null
                    ]
                ],
                'sending_filter'      => 'list_tag',
                'dynamic_segment'     => [
                    'id'   => '',
                    'slug' => ''
                ],
                'advanced_filters'    => [[]],
                'template_config'     => ($model->template_config) ? $model->template_config : Helper::getTemplateConfig($defaultTemplate)
            ];
        });

        static::addGlobalScope('type', function ($builder) {
            $builder->where('type', '=', static::$type);
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

    public function stats()
    {
        $totalEmails = CampaignEmail::where('campaign_id', $this->id)
            ->count();
        $totalSent = CampaignEmail::where('campaign_id', $this->id)
            ->where('status', 'sent')
            ->count();
        $clicks = CampaignUrlMetric::where('campaign_id', $this->id)
            ->where('type', 'click')
            ->count();
        $views = CampaignUrlMetric::where('campaign_id', $this->id)
            ->where('type', 'open')
            ->count();

        $unSubscribed = CampaignUrlMetric::where('campaign_id', $this->id)
            ->where('type', 'unsubscribe')
            ->distinct()
            ->count('subscriber_id');

        $revs = false;
        if ($revenue = fluentcrm_get_campaign_meta($this->id, '_campaign_revenue')) {
            $amounts = [];
            $currencies = [];
            foreach ($revenue->value as $currency => $cents) {
                $money = $cents/100;
                $amounts[] = number_format($money, (is_int($money)) ? 0 : 2);
                $currencies[] = $currency;
            }
            if($amounts && $currencies) {
                $revs = [
                    'total' => implode(' | ', $amounts),
                    'label' => 'Revenue ('.implode(' | ', $currencies).')'
                ];
            }
        }

        return [
            'total' => $totalEmails,
            'sent' => $totalSent,
            'clicks' => $clicks,
            'views' => $views,
            'unsubscribers' => $unSubscribed,
            'revenue' => $revs
        ];
    }

    public function updateMailerSettings($mailerSettings)
    {
        $settings = $this->settings;
        $settings['mailer_settings'] = $mailerSettings;
        $this->settings = $settings;
        $this->save();
        return $this;
    }
}
