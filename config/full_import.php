<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Opens the full import from another app to every user in the window
    | after onboarding, without a deploy. Off by default on this instance:
    | then only users with the `FullImport` Pennant flag can start one
    | (`php artisan feature:enable FullImport <email>`); nobody else sees the
    | Settings entry or its CTA, and the endpoints refuse new imports. Imports
    | already running finish, and the history and undo of past imports stay
    | available. Read by `User::canUseFullImport()`.
    |
    */

    'enabled' => (bool) env('FULL_IMPORT_ENABLED', false),

];
