<?php

namespace App\Providers;

use App\Models\Language;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Schema\Builder;
use Illuminate\Pagination\Paginator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Paginator::useBootstrapFive();
        try {
            if(!env('APP_DEBUG')){
                $host = request()->getHost();
                if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                    URL::forceScheme('https');
                }
            }
            Builder::defaultStringLength(191);
            try {
                $connection = DB::connection()->getPdo();
                if ($connection) {
                    $allOptions = [];
                    $allOptions['settings'] = Setting::all()->pluck('option_value', 'option_key')->toArray();
                    config($allOptions);
                    config(['app.name' => getOption('app_name')]);
                }
            } catch (\Exception $e) {
                // Database not available, skip loading settings from database
                // This allows the application to work during installation/update phases
            }
            Gate::before(function ($user, $ability) {
                return $user->role == USER_ROLE_TEAM_MEMBER ? false : true;
            });
        } catch (\Exception $e) {
            //
        }

        // Register simple version update routes without common middleware
        $this->app['router']->get('simple-version-update', [\App\Http\Controllers\SimpleVersionUpdateController::class, 'versionUpdate'])->name('simple-version-update');
        $this->app['router']->post('simple-process-update', [\App\Http\Controllers\SimpleVersionUpdateController::class, 'processUpdate'])->name('simple-process-update');
    }
}
