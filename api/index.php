<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Auth;
use App\Core\Bootstrap;
use App\Core\Request;
use App\Core\Router;

Bootstrap::init();
Auth::startSession();

$request = new Request();
$router = new Router();

$routes = require __DIR__ . '/../routes.php';
$routes($router);

$router->dispatch($request);
