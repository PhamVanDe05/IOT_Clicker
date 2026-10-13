<?php
// Copy outside the web root and point the CLICKER_CONFIG environment variable at it.
return [
    'dsn' => 'mysql:host=localhost;dbname=smart_clicker;charset=utf8mb4',
    'db_user' => 'clicker_app',              // SELECT/INSERT/UPDATE/DELETE only
    'db_password' => 'change-me',
    'phone_signing_key' => 'random-64-hex-chars',
    'public_url' => '',                      // HTTPS link for remote use, '' if none
    'internet_host' => '',                   // host name of that link, '' if none
    // Optional: sign-in with Google/Facebook and AI question generation; leave empty to turn off.
    'google_client_id' => '', 'google_client_secret' => '',
    'facebook_app_id' => '', 'facebook_app_secret' => '',
    'ai_provider' => '', 'ai_api_key' => '', 'ai_base_url' => '', 'ai_model' => '',
];
