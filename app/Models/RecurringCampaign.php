<?php

namespace FluentCampaign\App\Models;

use FluentCrm\App\Models\Model;
use FluentCrm\App\Services\Helper;

class RecurringCampaign extends Model
{
    protected $table = 'fc_campaigns';

    protected $guarded = ['id'];

    protected $fillable = [
        'title',
        'email_subject',
        'email_pre_header',
        'email_body',
        'status',
        'template_id',
        'utm_status',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'scheduled_at',
        'created_by',
        'settings',
        'design_template'
    ];

    protected static $type = 'recurring_campaign';

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $defaultTemplate = $model->design_template ? $model->design_template : Helper::getDefaultEmailTemplate();

            $settings = [
                'mailer_settings'      => [
                    'from_name'      => '',
                    'from_email'     => '',
                    'reply_to_name'  => '',
                    'reply_to_email' => '',
                    'is_custom'      => 'no'
                ],
                'scheduling_settings'  => [
                    'type'               => 'weekly',
                    'day'                => 'mon',
                    'time'               => '09.00',
                    'send_automatically' => 'yes'
                ],
                'sending_conditions'   => [],
                'subscribers_settings' => [
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
                    'advanced_filters'    => [[]]
                ],
                'template_config'      => Helper::getTemplateConfig($defaultTemplate)
            ];

            if ($model->settings) {
                $settings = wp_parse_args($model->settings, $settings);
            }
            $model->email_subject = $model->email_subject ? $model->email_subject : '';
            $model->email_pre_header = $model->email_pre_header ? $model->email_pre_header : '';
            $model->email_body = $model->email_body ? $model->email_body : '';
            $model->status = 'draft';
            $model->type = self::$type;
            $model->design_template = $defaultTemplate;
            $model->slug = $model->slug ?: sanitize_title($model->title, '', 'preview');
            $model->created_by = $model->created_by ?: get_current_user_id();
            $model->settings = $settings;
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

    public function stat()
    {
        return [
            'emails' => ''
        ];
    }

    public function getMailCampaignCounts()
    {
        return RecurringMail::where('parent_id', $this->id)->count();
    }
}
