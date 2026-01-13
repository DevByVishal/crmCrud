<?php

namespace YourName\CrmGenerator;

use Illuminate\Support\ServiceProvider;
use YourName\CrmGenerator\Console\MakeCrm;

class CrmGeneratorServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeCrm::class,
            ]);
        }
    }
}
