<?php
// Copy outside the web root and point CLICKER_CONFIG at it (default path is set in web/api.php line 50).
return [
    'dsn' => 'mysql:host=localhost;dbname=smart_clicker;charset=utf8mb4',
    'db_user' => 'clicker_app',           // SELECT/INSERT/UPDATE/DELETE only
    'db_password' => 'change-me',
    'public_url' => '',                   // HTTPS link for remote use, '' if none
    'phone_signing_key' => 'random-64-hex-chars',
    'internet_host' => '',                // host name of the public link, '' if none
];
