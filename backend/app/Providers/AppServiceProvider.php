<?php

namespace App\Providers;

use App\Models\Device;
use App\Models\DevicePort;
use App\Models\Location;
use App\Models\PortConnection;
use App\Models\PowerOutlet;
use App\Models\Rack;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->bindOwnedModels();

        // Kontoverwaltung nur fuer Admins (Route-Middleware "can:admin")
        Gate::define('admin', fn (User $user) => (bool) $user->is_admin);

        // Registrierung: hoechstens zehn neue Konten pro Stunde und Adresse
        RateLimiter::for('registrierung', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        // 2FA-Code: je offener Anmeldung hoechstens fuenf Versuche pro Minute
        RateLimiter::for('zwei-faktor', fn (Request $request) => Limit::perMinute(5)->by(
            ($request->session()->get('login.2fa')['id'] ?? 'ohne').'|'.$request->ip()
        ));
    }

    /**
     * Getrennte Workspaces an zentraler Stelle: Jede ID in einer URL
     * (/racks/{rack}, /devices/{device}, ...) wird nur innerhalb des
     * angemeldeten Kontos gesucht. Eine fremde ID ergibt 404, als gaebe
     * es sie nicht - so verraet die Antwort auch nichts ueber andere Konten.
     */
    private function bindOwnedModels(): void
    {
        $modelle = [
            'rack' => Rack::class,
            'location' => Location::class,
            'device' => Device::class,
            'port' => DevicePort::class,
            'portConnection' => PortConnection::class,
            'outlet' => PowerOutlet::class,
        ];

        foreach ($modelle as $parameter => $modell) {
            Route::bind($parameter, function (string $wert) use ($modell) {
                // Keine Zahl: sonst meldet PostgreSQL einen Typfehler (500)
                abort_unless(ctype_digit($wert), 404);

                return $modell::query()->ownedBy(Auth::id())->findOrFail((int) $wert);
            });
        }
    }
}
