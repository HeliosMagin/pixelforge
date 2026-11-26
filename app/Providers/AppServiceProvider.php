<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pricing\PriceCalculator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            baseUrl: config('services.pixelforge.base_url'),
            apiKey: config('services.pixelforge.key'),
        ));

        $this->app->singleton(PriceCalculator::class, fn () => new PriceCalculator(
            markupPercent: (int) config('services.pixelforge.markup_percent'),
        ));
    }

    public function boot(): void
    {
        Gate::define('view-admin', fn (User $user) => $user->is_admin);
    }
}
