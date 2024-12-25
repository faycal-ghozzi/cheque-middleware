<?php

namespace PECCMiddleware;

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Dotenv\Dotenv;
use PDO;
use PDOException;
use Ramsey\Uuid\Uuid;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

class Middleware
{
    private $httpClient;
    private $dbConnection;

    public function __construct()
    {
        $this->httpClient = new Client();

        // Initialize database connection from .env file
        $dsn = sprintf('mysql:host=%s;dbname=%s', $_ENV['DB_HOST'], $_ENV['DB_NAME']);
        $username = $_ENV['DB_USER'];
        $password = $_ENV['DB_PASS'];

        try {
            $this->dbConnection = new PDO($dsn, $username, $password);
            $this->dbConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }

    public function consultCheck($session_id, $source, $check_number, $rib, $cle_securite)
    {
        if ($this->isT24Down()) {
            return $this->consultFromBackup($rib, $check_number, $_ENV['T24BKP_PHONE_NUM']);
        }

        // Simulate fetching phone number from RIB/check holder
        $phone_number = $this->fetchPhoneNumberFromCheckHolder($rib, $check_number);

        // Simulate OTP sending and validation process
        $otpSent = $this->sendOTP($phone_number);

        $response = [
            'http_response_code' => $otpSent ? 200 : 500,
            'success' => $otpSent,
            'result' => $otpSent ? '1' : '0',
            'phone_number' => $otpSent ? $phone_number : null,
            'session_id' => $session_id,
            'message' => $otpSent ? 'OTP sent successfully' : 'Failed to send OTP'
        ];

        return $response;
    }

    public function reserveCheck($session_id, $amount_in_millimes, $source, $check_number, $rib, $cle_securite)
    {
        if ($this->isT24Down()) {
            return $this->reserveFromBackup($rib, $amount_in_millimes, $_ENV['T24BKP_CURRENT_SOLDE']);
        }

        // Simulate reserving the sum in the database
        $reservationSuccess = $this->reserveAmountInDatabase($rib, $amount_in_millimes);

        $response = [
            'http_response_code' => $reservationSuccess ? 200 : 500,
            'success' => $reservationSuccess,
            'message' => $reservationSuccess ? 'Amount reserved successfully' : 'Failed to reserve amount'
        ];

        return $response;
    }

    private function isT24Down()
    {
        $currentHour = (int) date('H');
        $currentMinute = (int) date('i');
        $isDown = ($currentHour > 20 || ($currentHour < 7 || ($currentHour === 7 && $currentMinute < 30)));

        return $isDown;
    }

    private function consultFromBackup($rib, $check_number, $backupFilePath)
    {
        $backupData = json_decode(file_get_contents($backupFilePath), true);

        foreach ($backupData as $entry) {
            if ($entry['rib'] === $rib && $entry['check_number'] === $check_number) {
                return [
                    'http_response_code' => 200,
                    'success' => true,
                    'result' => '0',
                    'phone_number' => $entry['phone_number'],
                    'session_id' => Uuid::uuid4()->toString(),
                    'message' => 'Data fetched from backup'
                ];
            }
        }

        return [
            'http_response_code' => 404,
            'success' => false,
            'message' => 'Data not found in backup'
        ];
    }

    private function reserveFromBackup($rib, $amount, $backupFilePath)
    {
        $backupData = json_decode(file_get_contents($backupFilePath), true);

        foreach ($backupData as $entry) {
            if ($entry['rib'] === $rib && $entry['current_solde'] >= $amount) {
                return [
                    'http_response_code' => 200,
                    'success' => true,
                    'message' => 'Amount reserved from backup'
                ];
            }
        }

        return [
            'http_response_code' => 500,
            'success' => false,
            'message' => 'Insufficient funds in backup'
        ];
    }

    private function fetchPhoneNumberFromCheckHolder($rib, $check_number)
    {
        // Simulate database lookup
        return '+1234567890123';
    }

    private function sendOTP($phone_number)
    {
        // Simulate OTP sending logic (e.g., through an external service)
        return true;
    }

    private function reserveAmountInDatabase($rib, $amount)
    {
        try {
            $query = "INSERT INTO reservations (rib, amount, reserved_at) VALUES (:rib, :amount, :reserved_at)";
            $stmt = $this->dbConnection->prepare($query);
            $stmt->execute([
                ':rib' => $rib,
                ':amount' => $amount,
                ':reserved_at' => date('Y-m-d H:i:s')
            ]);
            return true;
        } catch (PDOException $e) {
            error_log('Database error: ' . $e->getMessage());
            return false;
        }
    }
}

// Create middleware instance
$middleware = new Middleware();

// Routing logic
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json');

if ($requestUri === '/pecc/v1/check/consult' && $requestMethod === 'POST') {
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
} elseif ($requestUri === '/pecc/v1/check/reserve' && $requestMethod === 'POST') {
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
} else {
    http_response_code(404);
    echo json_encode(['message' => 'Endpoint not found']);
}



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
