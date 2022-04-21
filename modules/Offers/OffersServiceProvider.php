<?php

namespace Modules\Offers;

use Illuminate\Support\ServiceProvider;
use Modules\Offers\Providers\CommandServiceProvider;
use Modules\Offers\Providers\DatabaseServiceProvider;
use Modules\Offers\Providers\EventServiceProvider;
use Modules\Offers\Providers\LanguageServiceProvider;
use Modules\Offers\Providers\PolicyServiceProvider;
use Modules\Offers\Providers\RouteServiceProvider;

class OffersServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->register(CommandServiceProvider::class);
        $this->app->register(DatabaseServiceProvider::class);
        $this->app->register(LanguageServiceProvider::class);
        $this->app->register(PolicyServiceProvider::class);
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);
    }
}
