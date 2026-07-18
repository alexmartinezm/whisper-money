<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hide Authentication Buttons
    |--------------------------------------------------------------------------
    |
    | When set to true, this hides authentication buttons (login/register)
    | from the landing page and blocks public registration.
    |
    */

    'hide_auth_buttons' => env('HIDE_AUTH_BUTTONS', false),

    /*
    |--------------------------------------------------------------------------
    | Authentication Override
    |--------------------------------------------------------------------------
    |
    | Signed landing links can carry waitlist or invitation context, but they
    | do not override disabled public registration.
    |
    */

    'auth_override' => [
        'query_parameter' => 'signup',
        'cookie_name' => 'landing_auth_override',
        'cookie_minutes' => 60 * 24 * 7,
        'ignore_signature_query_parameters' => [
            'lang',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'gclid',
            'fbclid',
        ],
    ],

];
