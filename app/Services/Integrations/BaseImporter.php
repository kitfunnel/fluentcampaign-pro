<?php

namespace FluentCampaign\App\Services\Integrations;


abstract class BaseImporter
{
    public $importKey;

    public function __construct()
    {
        $this->init();
    }

    public function init()
    {
        add_filter('fluent_crm/import_providers', array($this, 'getDriverInfo'));
        add_filter('fluent_crm/get_import_driver_' . $this->importKey, array($this, 'processUserDriver'), 10, 2);
        add_filter('fluent_crm/post_import_driver_' . $this->importKey, array($this, 'importData'), 10, 3);
    }

    public function getDriverInfo($drivers)
    {
        $driver = $this->getInfo();
        if($driver) {
            $drivers[$this->importKey] = $driver;
        }

        return $drivers;
    }

    abstract public function getInfo();

    abstract public function processUserDriver($config, $request);

    abstract public function importData($returnData, $config, $page);

}
