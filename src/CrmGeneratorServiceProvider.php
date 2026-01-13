<?php

namespace DevByVishal\crmCrud;

use Illuminate\Support\ServiceProvider;
use DevByVishal\crmCrud\Console\MakeCrm;

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
