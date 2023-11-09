<?php

namespace FluentCampaign\App\Services\Commerce;

use FluentCrm\App\Models\Model;

class ItemTaxonomyRelation extends Model
{
    protected $table = 'term_relationships';

    public $timestamps = false;
}
