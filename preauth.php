<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Preauth\Auth;

/* kickoff the script */
$auth = new Auth($_SERVER['HTTP_HOST'] ?? 'example.com');
$auth->run();

/* no additional output most of the time, sometimes we'll continue to display the login screen */
if ($auth->showTemplate()) {
    include __DIR__ . '/template.php';
}
