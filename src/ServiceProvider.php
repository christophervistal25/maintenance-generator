<?php

namespace DevKits\ModuleGenerator;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use DevKits\ModuleGenerator\Commands\MakeModuleCommand;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/module-generator.php',
            'module-generator'
        );
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Register the command if we are using the application via the CLI
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeModuleCommand::class,
            ]);

            // Publish stubs
            $this->publishes([
                __DIR__ . '/Stubs' => resource_path('stubs/vendor/module-generator'),
            ], 'module-generator-stubs');

            // Publish config
            $this->publishes([
                __DIR__ . '/../config/module-generator.php' => config_path('module-generator.php'),
            ], 'module-generator-config');
        }
    }
}
