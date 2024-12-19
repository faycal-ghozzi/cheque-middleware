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
