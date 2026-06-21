<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public Instagram (landing page credit link)
    |--------------------------------------------------------------------------
    */

    'instagram_url' => env('WORKATMO_INSTAGRAM_URL', 'https://www.instagram.com/workatmo_technologies_pvt_ltd/'),

    /*
    | wa.me expects digits only, with country code (no +). Example India: 919392599067
    */
    'whatsapp_phone' => env('WORKATMO_WHATSAPP_PHONE', '919392599067'),

    /* Prefill text for wa.me only (Instagram is a separate button on the landing page). */
    'whatsapp_message' => env('WORKATMO_WHATSAPP_MESSAGE', 'Hi from Matrix Backend API'),

    /*
    |--------------------------------------------------------------------------
    | Frontend base URL (Stripe redirect URLs)
    |--------------------------------------------------------------------------
    |
    | Used to build success/cancel URLs for Stripe Checkout.
    | Example: https://matrixmobiletyresandautos.com
    */
    // WORKATMO_FRONTEND_URL is canonical; FRONTEND_URL is a legacy alias used in older .env files.
    'frontend_url' => env('WORKATMO_FRONTEND_URL', env('FRONTEND_URL', 'http://localhost:3000')),

];
