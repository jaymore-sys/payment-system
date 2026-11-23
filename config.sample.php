<?php
// Copy to config.php on the server (config.php is not in git) and fill in.
// On first run the app moves it to ../payment-config.php, outside public_html.
return [
    'db_host' => 'localhost',
    'db_name' => 'u000000000_payments',
    'db_user' => 'u000000000_payments',
    'db_pass' => '',
    // 'upload_dir' => '/path/outside/public_html',  // default: one folder above the app
];
