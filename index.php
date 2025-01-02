<?php

namespace PECCMiddleware;

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Dotenv\Dotenv;
use PDO;
use PDOException;
use Ramsey\Uuid\Uuid;
use Exception;
use SoapClient;
use SoapFault;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$username = $_ENV['T24_USER'];
$password = $_ENV['T24_PASS'];
$company = $_ENV['T24_COMP'];

class Middleware
{
    private $httpClient;
    private $dbConnection;

    public function __construct()
    {
        $this->httpClient = new Client();

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

    public function normalizeArrayKeys($array) {
        $normalizedArray = [];
        foreach ($array as $key => $value) {
            $normalizedKey = trim($key);
            if (is_array($value)) {
                $value = $this->normalizeArrayKeys($value);
            }
            $normalizedArray[$normalizedKey] = $value;
        }
        return $normalizedArray;
    }

    public function callConsultService($account) {
        $currentDate = date('Ymd');
        $currentTime = date('His');
    
        $criteriaValues = [
            ["columnName" => "RRN", "criteriaValue" => "123456789", "operand" => "EQ"],
            ["columnName" => "TR.DATE", "criteriaValue" => $currentDate, "operand" => "EQ"],
            ["columnName" => "TR.HEURE", "criteriaValue" => $currentTime, "operand" => "EQ"],
            ["columnName" => "ACCOUNT", "criteriaValue" => substr($account, 8, -2), "operand" => "EQ"]
        ];
    
        $url = 'http://172.20.66.51:9045/BTL_MONETIQUE/services?wsdl';
        $soap = new SoapClient($url);
    
        $params = array(
            "WebRequestCommon" => array(
                "userName" => $GLOBALS['username'],
                "password" => $GLOBALS['password'],
                "company" => $GLOBALS['company'],
            ),
            "TRGENQACCOUNTSOLDE2Type" => array(
                "enquiryInputCollection" => array()
            )
        );
    
        foreach ($criteriaValues as $criteria) {
            $params["TRGENQACCOUNTSOLDE2Type"]["enquiryInputCollection"][] = array(
                "columnName" => $criteria['columnName'],
                "criteriaValue" => $criteria['criteriaValue'],
                "operand" => $criteria['operand']
            );
        }
    
        try {
            $response = $soap->__soapCall('SOLDECLIENT', array($params));
            return json_encode($response);
        } catch (SoapFault $e) {
            throw new Exception("SOAP Error: " . $e->getMessage());
        } catch (Exception $e) {
            throw new Exception("Error: " . $e->getMessage());
        }
    }

    public function consultCheck($session_id, $source, $check_number, $rib, $cle_securite)
    {

        if ($this->isT24Down()) {
            return $this->consultFromBackup($rib, $check_number, $_ENV['T24BKP_PHONE_NUM']);
        }

        // example of using a rib and extracting account number with substr 8, -2 26016000890125816427
        $consultService =  $this->callConsultService($rib);

        $data = json_decode($consultService, true);
        
        $http_code = 0;

        switch ($data['Status']['successIndicator']){
            case 'Success':
                $http_code = 200;
                break;
            case 'T24Error':
                switch ($data['Status']['messages'][2]){
                    case 'ERREUR COMPTE':
                        $http_code = 404;
                        break;
                }
                break;
            default:
                $http_code = 400;
        }

        $phone_number = $this->fetchPhoneNumberFromCheckHolder($rib, $check_number);

        $otpSent = $this->sendOTP($phone_number);

        $response = [
            'http_response_code' => $http_code,
            'success' => $http_code === 200,
            'result' => $http_code === 200 ? ($otpSent ? '1' : '0') : null,
            'phone_number' => $http_code === 200 ? '12345678' : null,
            'message' => $http_code === 200 ? 'test message SUCCESS' : 'test message FAIL',
        ];

        return $response;
    }

    public function reserveCheck($session_id, $amount_in_millimes, $source, $check_number, $rib, $cle_securite)
    {
        if ($this->isT24Down()) {
            return $this->reserveFromBackup($rib, $amount_in_millimes, $_ENV['T24BKP_CURRENT_SOLDE']);
        }

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
        return '+1234567890123';
    }

    private function sendOTP($phone_number)
    {
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

$middleware = new Middleware();

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
