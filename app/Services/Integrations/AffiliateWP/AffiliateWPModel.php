<?php

namespace FluentCampaign\App\Services\Integrations\AffiliateWP;

use FluentCrm\App\Models\Model;

/**
 *  AffiliateWP Affiliate Model - DB Model for Affiliates
 *
 *  Database Model
 *
 * @package FluentCampaign\App\Services\Integrations
 *
 * @version 1.0.0
 */
class AffiliateWPModel extends Model
{
    protected $table = 'affiliate_wp_affiliates';

    protected $guarded = ['affiliate_id', 'user_id'];

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * belongsTo: affiliate is belongs to a contact
     * @return \FluentCrm\Framework\Database\Orm\Relations\belongsTo
     */
    public function contact()
    {
        $class = '\FluentCrm\App\Models\Subscriber';
        return $this->belongsTo($class, 'user_id', 'user_id');
    }

    public function getActivity($activity, $default = '')
    {
        $payoutProps = [
            'last_payout_amount',
            'last_payout_date',
            'last_payment_date'
        ];

        if (in_array($activity, $payoutProps)) {
            $payout = fluentCrmDb()->table('affiliate_wp_payouts')
                ->where('affiliate_id', $this->affiliate_id)
                ->orderBy('payout_id', 'DESC')
                ->first();

            if (!$payout) {
                return $default;
            }

            $activity = str_replace(['last_payout_', 'last_payment_'], '', $activity);


            return $payout->{$activity};
        }

        return $default;
    }

    public function getAffPropValue($prop, $defaultValue = '')
    {
        $affProps = [
            'affiliate_id',
            'status',
            'earnings',
            'unpaid_earnings',
            'referrals',
            'visits',
            'date_registered',
            'payment_email',
        ];

        if (in_array($prop, $affProps)) {
            return $this->{$prop};
        }

        $payoutProps = [
            'last_payout_amount',
            'last_payout_date',
            'last_payment_date'
        ];

        if (in_array($prop, $payoutProps)) {
            return $this->getActivity($prop, $defaultValue);
        }

        return $defaultValue;
    }
}
