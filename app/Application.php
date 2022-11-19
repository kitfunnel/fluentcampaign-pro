<?php

namespace FluentCampaign\App;

class Application
{
    public function __construct($app)
    {
        $this->boot($app);
    }

    public function boot($app)
    {
        $router = $app->router;

        require_once 'Hooks/actions.php';
        require_once 'Hooks/filters.php';
        require_once 'Http/routes.php';
    }
}
