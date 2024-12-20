<?php

namespace PECCMiddleware;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PDO;

class Middleware
{
    private $httpClient;
    private $temenosServiceUrl;
    private $monetiqueServiceUrl;
    private $dbConnection;

    public function __construct()
    {
        $this->httpClient = new Client();
        $this->temenosServiceUrl = 'https://temenos-api.example.com'; // Replace with actual URL
        $this->monetiqueServiceUrl = 'https://monetique-api.example.com'; // Replace with actual URL

        // Initialize database connection
        $dsn = 'mysql:host=localhost;dbname=pecc_middleware'; // Update with your database credentials
        $username = 'root'; // Replace with your database username
        $password = ''; // Replace with your database password

        try {
            $this->dbConnection = new PDO($dsn, $username, $password);
            $this->dbConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }

    // Function to fetch data from PECC
    public function fetchPECCData(array $payload)
    {
        return [
            'amount_in_millimes' => $payload['amount_in_millimes'] ?? 0,
            'check_number' => $payload['check_number'] ?? '',
            'rib' => $payload['rib'] ?? ''
        ];
    }

    // Function to log operation to database
    private function logOperation($amount, $checkNumber, $rib)
    {
        $query = "INSERT INTO operations (amount, check_number, rib, time_of_operation) VALUES (:amount, :check_number, :rib, :time_of_operation)";
        $stmt = $this->dbConnection->prepare($query);

        $stmt->execute([
            ':amount' => $amount,
            ':check_number' => $checkNumber,
            ':rib' => $rib,
            ':time_of_operation' => date('Y-m-d H:i:s')
        ]);
    }

    // Function to call Temenos T24 webservice
    public function callTemenosService($amount, $checkNumber, $rib)
    {
        try {
            $response = $this->httpClient->post($this->temenosServiceUrl . '/validate-check', [
                'json' => [
                    'amount' => $amount,
                    'check_number' => $checkNumber,
                    'rib' => $rib
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            if ($data['success']) {
                return [
                    'status' => 'success',
                    'message' => 'Check validated and amount blocked.'
                ];
            }

            return [
                'status' => 'failure',
                'message' => $data['error'] ?? 'Validation failed.'
            ];
        } catch (RequestException $e) {
            return [
                'status' => 'error',
                'message' => 'Temenos service unavailable.'
            ];
        }
    }

    // Function to handle fallback with Monetique
    public function handleMonetiqueFallback($amount, $checkNumber, $rib)
    {
        try {
            $response = $this->httpClient->post($this->monetiqueServiceUrl . '/fallback', [
                'json' => [
                    'amount' => $amount,
                    'check_number' => $checkNumber,
                    'rib' => $rib
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            if ($data['success']) {
                return [
                    'status' => 'success',
                    'message' => 'Fallback operation completed successfully.'
                ];
            }

            return [
                'status' => 'failure',
                'message' => $data['error'] ?? 'Fallback failed.'
            ];
        } catch (RequestException $e) {
            return [
                'status' => 'error',
                'message' => 'Monetique service unavailable.'
            ];
        }
    }

    // Main handler function
    public function processRequest(array $payload)
    {
        $peccData = $this->fetchPECCData($payload);

        // Log operation to the database
        $this->logOperation(
            $peccData['amount_in_millimes'],
            $peccData['check_number'],
            $peccData['rib']
        );

        $temenosResponse = $this->callTemenosService(
            $peccData['amount_in_millimes'],
            $peccData['check_number'],
            $peccData['rib']
        );

        if ($temenosResponse['status'] === 'error') {
            return $this->handleMonetiqueFallback(
                $peccData['amount_in_millimes'],
                $peccData['check_number'],
                $peccData['rib']
            );
        }

        return $temenosResponse;
    }
}

// Example usage
$middleware = new Middleware();

$requestPayload = [
    'amount_in_millimes' => 10000,
    'check_number' => '1234567',
    'rib' => '12345678901234567890'
];

$response = $middleware->processRequest($requestPayload);

header('Content-Type: application/json');
echo json_encode($response);
