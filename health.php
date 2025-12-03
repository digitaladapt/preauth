<?php
/* do garbage collection on rate limit monitoring sessions */
session_start();
$success = session_gc();
session_destroy();

if ($success !== false) {
    /* if garbage collection was successful, report that we are healthy */
    echo implode('', ['o', 'n', 'l', 'i', 'n', 'e', "\n"]);
} else {
    echo "error\n";
}

