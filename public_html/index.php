<?php

require __DIR__ . '/../vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Omniplexer\Multiplexer;

$logger = new Logger('omniplexer');
$logger->pushHandler(new StreamHandler(__DIR__ . '/logs/omniplexer.log', Logger::DEBUG));
$configFile = __DIR__ . '/config.ini';

header('X-datasource: omniplexer');

try {
    $multiplexer = new Multiplexer($configFile, $logger);
    $multiplexer->handleRequest();
} catch (Throwable $e) {
    http_response_code(500);
    $logger->error('Unhandled exception', ['exception' => $e]);
    header('Content-Type: text/plain');
    
    echo "# Internal server error\n";
}
