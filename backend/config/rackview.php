<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registrierung
    |--------------------------------------------------------------------------
    |
    | Ob sich auf der Anmeldeseite jeder ein Konto anlegen kann. Jedes Konto
    | hat seinen eigenen, getrennten Bereich. Ist RackView aus dem Internet
    | erreichbar, besser abschalten: RACKVIEW_REGISTRATION=false in der .env.
    | Konten entstehen dann nur noch per "php artisan rackview:user" oder
    | durch einen Admin in den Einstellungen.
    |
    */

    'registration' => (bool) env('RACKVIEW_REGISTRATION', true),

    /*
    |--------------------------------------------------------------------------
    | Anmeldung ueber einen OIDC-Anbieter (z.B. Authentik)
    |--------------------------------------------------------------------------
    |
    | Angeschaltet wird sie mit OIDC_ENABLED=true; dazu gehoeren Issuer,
    | Client-ID und Secret aus der Application im Anbieter. Die
    | Rueckkehradresse, die dort eingetragen werden muss, lautet
    |
    |     https://<adresse-von-rackview>/api/auth/oidc/callback
    |
    | Die Anmeldung mit Passwort bleibt daneben bestehen - faellt der
    | Anbieter aus, kommt man weiterhin herein.
    |
    */

    'oidc' => [
        'enabled' => (bool) env('OIDC_ENABLED', false),
        'issuer' => env('OIDC_ISSUER'),
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),

        // Aufschrift des Knopfes auf der Anmeldeseite
        'label' => env('OIDC_LABEL', 'Mit Authentik anmelden'),

        // "openid" ist Pflicht; profile und email liefern Name und Adresse
        'scopes' => env('OIDC_SCOPES', 'openid profile email'),
    ],

];
