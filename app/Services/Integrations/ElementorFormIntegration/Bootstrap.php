<?php

namespace FluentCampaign\App\Services\Integrations\ElementorFormIntegration;


class Bootstrap
{
    public function init()
    {

        add_action('init', function () {
            if (!defined('ELEMENTOR_PRO_VERSION')) {
                return;
            }

            if (!class_exists('\ElementorPro\Plugin') || apply_filters('fluent_crm/disable_elementor_form', false)) {
                return;
            }

            $formModule = \ElementorPro\Plugin::instance()->modules_manager->get_modules('forms');

            if (!$formModule) {
                return;
            }

            $formWidget = new FormWidget();
            // Register the action with form widget
            if (version_compare(ELEMENTOR_PRO_VERSION, '3.5.0', '>')) {
                $formModule->actions_registrar->register($formWidget, $formWidget->get_name());
            } else {
                $formModule->add_form_action($formWidget->get_name(), $formWidget);
            }
        });
    }
}
