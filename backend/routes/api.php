<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceImageController;
use App\Http\Controllers\Api\DevicePortController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\OidcController;
use App\Http\Controllers\Api\PortConnectionController;
use App\Http\Controllers\Api\PowerOutletController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RackController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Anmeldung
|--------------------------------------------------------------------------
|
| Alle Routen laufen zusaetzlich durch die web-Middleware: Sitzung per
| HttpOnly-Cookie und CSRF-Schutz fuer aendernde Anfragen. Das Frontend
| liegt unter derselben Adresse und schickt das Cookie automatisch mit.
|
*/

Route::middleware('web')->group(function () {
    Route::get('auth/status', [AuthController::class, 'status']);

    // Hoechstens fuenf Versuche pro Minute - bremst Passwortraten aus
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Zweiter Anmeldeschritt: Code aus der Authenticator-App
    Route::post('auth/two-factor', [AuthController::class, 'twoFactor'])->middleware('throttle:zwei-faktor');

    // Selbst registrieren (abschaltbar per RACKVIEW_REGISTRATION)
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:registrierung');

    // Anmeldung ueber einen OIDC-Anbieter (z.B. Authentik). Beides sind
    // gewoehnliche Seitenaufrufe im Browser, keine API-Aufrufe: der
    // Anbieter schickt den Browser selbst zurueck.
    Route::get('auth/oidc/redirect', [OidcController::class, 'redirect'])->middleware('throttle:20,1');
    Route::get('auth/oidc/callback', [OidcController::class, 'callback'])->middleware('throttle:20,1');

    /*
    |----------------------------------------------------------------------
    | Alles Weitere nur mit Anmeldung
    |----------------------------------------------------------------------
    */

    Route::middleware('auth')->group(function () {
        // Eigenes Konto. Passwortaenderung und -bestaetigung gebremst,
        // weil beide das bisherige Passwort pruefen.
        Route::put('profile', [ProfileController::class, 'update']);
        Route::put('profile/password', [ProfileController::class, 'password'])->middleware('throttle:6,1');
        Route::post('profile/confirm-password', [ProfileController::class, 'confirmPassword'])->middleware('throttle:6,1');

        // Zwei-Faktor-Anmeldung einrichten und verwalten
        Route::get('profile/two-factor', [TwoFactorController::class, 'show']);
        Route::post('profile/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:6,1');
        Route::middleware('password.confirm')->group(function () {
            Route::post('profile/two-factor', [TwoFactorController::class, 'store']);
            Route::post('profile/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate']);
            Route::delete('profile/two-factor', [TwoFactorController::class, 'destroy']);
        });

        // Eigenes Konto samt Workspace loeschen (Passwort im Aufruf)
        Route::delete('profile', [ProfileController::class, 'destroy'])->middleware('throttle:6,1');

        // Konten verwalten: nur Admins, Aendern nur nach
        // Passwortbestaetigung (Antwort 423, danach drei Stunden Ruhe)
        Route::middleware('can:admin')->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::middleware('password.confirm')->group(function () {
                Route::post('users', [UserController::class, 'store']);
                Route::put('users/{user}', [UserController::class, 'update']);
                Route::delete('users/{user}', [UserController::class, 'destroy']);
            });
        });

        /*
        | Ab hier: Daten des Workspaces. Jede ID in der URL loest der
        | AppServiceProvider nur innerhalb des angemeldeten Kontos auf.
        */

        // Vollstaendiger Datenstand fuer die JSON-Sicherung
        Route::get('export', ExportController::class);

        // Gegenstueck dazu: eine Sicherung wieder einlesen. Die Vorschau
        // schreibt nichts und bleibt deshalb ungebremst; das Einlesen
        // selbst kann viel Arbeit ausloesen.
        Route::post('import/preview', [ImportController::class, 'preview']);
        Route::post('import', [ImportController::class, 'store'])->middleware('throttle:10,1');

        Route::apiResource('racks', RackController::class);

        // Standorte ausserhalb der Racks - Flur, Dachboden, Carport
        Route::apiResource('locations', LocationController::class);

        /*
        | Geraete liegen flach unter /devices statt unter ihrem Rack:
        | Ein Geraet kann auch an einem Standort stehen und haette dann
        | kein Rack, das in die URL passt.
        */
        Route::get('devices', [DeviceController::class, 'index']);
        Route::post('devices', [DeviceController::class, 'store']);
        Route::get('devices/{device}', [DeviceController::class, 'show']);
        Route::put('devices/{device}', [DeviceController::class, 'update']);
        Route::delete('devices/{device}', [DeviceController::class, 'destroy']);

        // Drag & Drop: nur Position und Rack, alle anderen Felder bleiben
        Route::patch('devices/{device}/position', [DeviceController::class, 'move']);

        // Die Rack-Ansicht laedt nur die Geraete, die sie auch zeigt
        Route::get('racks/{rack}/devices', [DeviceController::class, 'forRack']);

        // Foto eines Geraets. Bewusst ohne Rack im Pfad, weil das
        // Bild am Geraet haengt und nicht an seiner Position.
        Route::post('devices/{device}/image', [DeviceImageController::class, 'store']);
        Route::delete('devices/{device}/image', [DeviceImageController::class, 'destroy']);

        // Steckplaetze einer Steckdosenleiste oder USV. Wie viele es
        // gibt, steht am Geraet; hier wird nur belegt und freigemacht.
        Route::get('devices/{device}/outlets', [PowerOutletController::class, 'index']);
        Route::put('devices/{device}/outlets/{outlet}', [PowerOutletController::class, 'update']);

        Route::apiResource('devices.ports', DevicePortController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->parameters(['devices' => 'device', 'ports' => 'port']);

        Route::get('/port-connections', [PortConnectionController::class, 'index']);
        Route::post('/port-connections', [PortConnectionController::class, 'store']);
        Route::get('/port-connections/{portConnection}', [PortConnectionController::class, 'show']);
        Route::put('/port-connections/{portConnection}', [PortConnectionController::class, 'update']);
        Route::patch('/port-connections/{portConnection}', [PortConnectionController::class, 'update']);
        Route::delete('/port-connections/{portConnection}', [PortConnectionController::class, 'destroy']);
    });
});
