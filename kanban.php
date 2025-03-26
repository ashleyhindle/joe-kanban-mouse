<?php

// Enable all error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/vendor/autoload.php';

use App\Kanban;

try {
    // Run the kanban board
    $kanban = new Kanban;
    $result = $kanban->prompt();

    // If the user quits, display a message
    if ($result === false) {
        echo "Thanks for using Kanban Mouse!\n";
    }
} catch (Throwable $e) {
    echo 'Error: '.$e->getMessage()."\n";
    echo 'File: '.$e->getFile().' on line '.$e->getLine()."\n";
    echo "Trace:\n".$e->getTraceAsString()."\n";
}
