<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default password for new customer accounts (checkout)
    |--------------------------------------------------------------------------
    |
    | Plain-text value; the User model's "hashed" cast hashes it on save.
    | Override in production via CUSTOMER_DEFAULT_PASSWORD in .env.
    |
    */

    'default_password' => env('CUSTOMER_DEFAULT_PASSWORD', '12345678'),

];
