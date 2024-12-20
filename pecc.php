<?php

declare(strict_types=1);

namespace App\Middleware;

use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;

class PeccMiddleware
{
    private const CONFIG_FILE = __DIR__ . '/config.php';
    private array $config;
    private PDO $db;

    public function __construct()
    {
        $this->loadConfig();
        $this->db = new PDO($this->config['db_dsn'], $this->config['db_user'], $this->config['db_password']);
    }

    public function handle(array $server, array $requestBody): void
    {
        try {
            $this->validateApiKey($server);
            $authToken = $this->getBearerToken($server);
            $decodedToken = $this->validateJwtToken($authToken);

            $this->processTransaction($requestBody, $decodedToken);

            echo json_encode(['success' => true, 'message' => 'Transaction processed successfully.']);
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function loadConfig(): void
    {
        if (!file_exists(self::CONFIG_FILE)) {
            throw new Exception('Configuration file not found.');
        }
        $this->config = require self::CONFIG_FILE;
    }

    private function validateApiKey(array $server): void
    {
        $apiKey = $server['HTTP_' . str_replace('-', '_', strtoupper($this->config['api_key_header']))] ?? '';
        if (empty($apiKey) || $apiKey !== $this->config['api_key']) {
            throw new Exception('Invalid or missing API key.');
        }
    }

    private function getBearerToken(array $server): string
    {
        $authHeader = $server['HTTP_' . str_replace('-', '_', strtoupper($this->config['auth_header']))] ?? '';
        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            throw new Exception('Missing or invalid Authorization header.');
        }

        return substr($authHeader, 7);
    }

    private function validateJwtToken(string $token): object
    {
        try {
            return JWT::decode($token, new Key($this->config['jwt_secret'], $this->config['jwt_algo']));
        } catch (Exception $e) {
            throw new Exception('Invalid JWT token: ' . $e->getMessage());
        }
    }

    private function processTransaction(array $requestBody, object $decodedToken): void
    {
        $amount = $requestBody['amount'] ?? null;
        $chequeNumber = $requestBody['cheque_number'] ?? null;

        if (is_null($amount) || is_null($chequeNumber)) {
            throw new Exception('Invalid transaction data.');
        }

        $this->storeTransaction($chequeNumber, $amount);

        $monetiqueResponse = $this->notifyMonetique($chequeNumber, $amount);
        $peccResponse = $this->notifyPecc($chequeNumber, $amount);

        $this->handleServerResponses($monetiqueResponse, $peccResponse);
    }

    private function storeTransaction(string $chequeNumber, float $amount): void
    {
        $stmt = $this->db->prepare('INSERT INTO transactions (cheque_number, amount, status) VALUES (:cheque_number, :amount, :status)');
        $stmt->execute([
            ':cheque_number' => $chequeNumber,
            ':amount' => $amount,
            ':status' => 'held',
        ]);
    }

    private function notifyMonetique(string $chequeNumber, float $amount): array
    {
        // Simulate a request to the Monetique server with placeholder response
        return [
            'success' => true,
            'code' => 200,
            'message' => 'Amount held successfully on Monetique server.'
        ];
    }

    private function notifyPecc(string $chequeNumber, float $amount): array
    {
        // Simulate a request to the PECC server with placeholder response
        return [
            'success' => true,
            'code' => 200,
            'message' => 'Amount held successfully on PECC server.'
        ];
    }

    private function handleServerResponses(array $monetiqueResponse, array $peccResponse): void
    {
        if (!$monetiqueResponse['success']) {
            throw new Exception('Monetique server error: ' . $monetiqueResponse['message']);
        }

        if (!$peccResponse['success']) {
            throw new Exception('PECC server error: ' . $peccResponse['message']);
        }

        // Use response codes to handle additional logic if needed
        if ($monetiqueResponse['code'] === 414) {
            throw new Exception('Cheque blocked due to opposition on Monetique server.');
        }

        if ($peccResponse['code'] === 415) {
            throw new Exception('Cheque associated account under banking prohibition on PECC server.');
        }
    }
}

// Example usage
$middleware = new PeccMiddleware();
$middleware->handle($_SERVER, json_decode(file_get_contents('php://input'), true));


// ----

<?php

declare(strict_types=1);

namespace App\Middleware;

use Exception;
use PDO;

class PeccMiddleware
{
    private const CONFIG_FILE = __DIR__ . '/config.php';
    private array $config;
    private PDO $db;

    public function __construct()
    {
        $this->loadConfig();
        $this->db = new PDO($this->config['db_dsn'], $this->config['db_user'], $this->config['db_password']);
    }

    public function handle(array $server, array $requestBody): void
    {
        try {
            $this->validateApiKey($server);

            $operation = $requestBody['operation'] ?? null;
            if ($operation === 'consult') {
                $this->processConsultation($requestBody);
            } elseif ($operation === 'reserve') {
                $this->processReservation($requestBody);
            } else {
                throw new Exception('Invalid operation type.');
            }
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function loadConfig(): void
    {
        if (!file_exists(self::CONFIG_FILE)) {
            throw new Exception('Configuration file not found.');
        }
        $this->config = require self::CONFIG_FILE;
    }

    private function validateApiKey(array $server): void
    {
        $apiKey = $server['HTTP_' . str_replace('-', '_', strtoupper($this->config['api_key_header']))] ?? '';
        if (empty($apiKey) || $apiKey !== $this->config['api_key']) {
            throw new Exception('Invalid or missing API key.');
        }
    }

    private function processConsultation(array $requestBody): void
    {
        $response = $this->communicateWithPecc('/pecc/v1/check/consult', $requestBody);
        $this->handleResponse($response, 'consultation');
    }

    private function processReservation(array $requestBody): void
    {
        $response = $this->communicateWithPecc('/pecc/v1/check/reserve', $requestBody);
        $this->handleResponse($response, 'reservation');
        $this->storeTransaction($requestBody['check_number'], $requestBody['amount'], 'reserved');
    }

    private function communicateWithPecc(string $endpoint, array $data): array
    {
        $url = $this->config['pecc_api_base_url'] . $endpoint;
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Api-Key: ' . $this->config['pecc_api_key']
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('PECC API communication error. HTTP Code: ' . $httpCode);
        }

        return json_decode($response, true);
    }

    private function handleResponse(array $response, string $operationType): void
    {
        if (!$response['success']) {
            throw new Exception("Error during $operationType: " . ($response['message'] ?? 'Unknown error'));
        }

        echo json_encode(['success' => true, 'message' => "$operationType successful", 'details' => $response]);
    }

    private function storeTransaction(string $checkNumber, float $amount, string $status): void
    {
        $stmt = $this->db->prepare('INSERT INTO transactions (check_number, amount, status) VALUES (:check_number, :amount, :status)');
        $stmt->execute([
            ':check_number' => $checkNumber,
            ':amount' => $amount,
            ':status' => $status,
        ]);
    }
}

// Example usage
$middleware = new PeccMiddleware();
$middleware->handle($_SERVER, json_decode(file_get_contents('php://input'), true));