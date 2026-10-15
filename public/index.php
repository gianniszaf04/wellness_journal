<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Config/bootstrap.php';

\App\Core\Session::configure();
\App\Core\SecurityHeaders::apply();

use App\Core\Router;

$router = new Router;
require_once BASE_PATH . 'Routes/web.php';

$requestUri= $_SERVER['REQUEST_URI'];
$basePath=dirname(dirname($_SERVER['SCRIPT_NAME']));

if($basePath !== '/' && str_starts_with($requestUri,$basePath)) {
    $requestUri = substr($requestUri,strlen($basePath));
}

if($requestUri === '' || $requestUri === false) {
    $requestUri= '/';
}

$router->dispatch($_SERVER['REQUEST_METHOD'], $requestUri);
