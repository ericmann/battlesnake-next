<?php

declare(strict_types=1);

/*
 * Battlesnake-Next front controller.
 *
 * Four endpoints, no framework, no router library. Hand-rolled match() handles
 * everything in well under a millisecond. Keeps the request budget honest.
 *
 * Routes:
 *   GET  /         → snake metadata JSON
 *   POST /start    → 200 {}
 *   POST /move     → 200 {"move":"up|down|left|right"[, "shout":"..."]}
 *   POST /end      → 200 {}
 *   *              → 404
 */

require __DIR__ . '/../vendor/autoload.php';

use BattlesnakeAI\Controller;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';

[$status, $body] = match (true) {
    $method === 'GET'  && $path === '/'      => Controller::meta(),
    $method === 'POST' && $path === '/start' => Controller::start(),
    $method === 'POST' && $path === '/move'  => Controller::move(file_get_contents('php://input') ?: ''),
    $method === 'POST' && $path === '/end'   => Controller::end(),
    default                                  => [404, ['error' => 'not found']],
};

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($body, JSON_UNESCAPED_SLASHES);
