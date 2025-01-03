<?php

namespace PECCMiddleware;

use PECCMiddleware\Middleware;

$middleware = new Middleware();

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

// header('Content-Type: application/json');


$routes = [
    'POST' => [
        '/pecc/v1/check/consult' => function () use ($middleware) {
            $input = json_decode(file_get_contents('php://input'), true);
            $response = $middleware->consultCheck(
                $input['session_id'],
                $input['source'],
                $input['check_number'],
                $input['rib'],
                $input['cle_securite']
            );
            http_response_code($response['http_response_code']);
            echo json_encode($response);
        },
        '/pecc/v1/check/reserve' => function () use ($middleware) {
            $input = json_decode(file_get_contents('php://input'), true);
            $response = $middleware->reserveCheck(
                $input['session_id'],
                $input['amount_in_millimes'],
                $input['source'],
                $input['check_number'],
                $input['rib'],
                $input['cle_securite']
            );
            http_response_code($response['http_response_code']);
            echo json_encode($response);
        },
        '/pecc/v1/account/initiate_associate_rib' => function () use ($middleware) {
            $input = json_decode(file_get_contents('php://input'), true);
            $response = $middleware->initiateAssociateRIB(
                $input['rib'],
                $input['username']
            );
            http_response_code($response['http_response_code']);
            echo json_encode($response);
        },
        '/pecc/v1/account/confirm_associate_rib' => function () use ($middleware) {
            $input = json_decode(file_get_contents('php://input'), true);
            $response = $middleware->confirmAssociateRIB(
                $input['session_id'],
                $input['otp']
            );
            http_response_code($response['http_response_code']);
            echo json_encode($response);
        },
        '/pecc/v1/account/dissociate_rib' => function () use ($middleware) {
            $input = json_decode(file_get_contents('php://input'), true);
            $response = $middleware->dissociateRIB(
                $input['pecc_id'],
                $input['rib']
            );
            http_response_code($response['http_response_code']);
            echo json_encode($response);
        }
    ]
    ];

if(isset($routes[$requestMethod][$requestUri])) {
    $routes[$requestMethod][$requestUri]();
}else{
    http_response_code(404);
    echo json_encode(['message' => 'URL not found :(']);
}