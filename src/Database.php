<?php

namespace PECCMiddleware;

use PDO;
use PDOException;

class Database{
    private $connection;

    public function __construct()
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s', $_ENV['DB_HOST'], $_ENV['DB_NAME']);
        $username = $_ENV['DB_USER'];
        $password = $_ENV['DB_PASS'];

        try {
            $this->connection = new PDO($dsn, $username, $password);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }

    public function executeQuery($query, $params = []){
        try{
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }catch(PDOException $e){
            error_log('Database error: ' . $e->getMessage());
            return false;
        }
    }

    public function insertReservation($rib, $amount){
        $query = "INSERT INTO reservations (rib, amount, reserved_at) VALUES (:rib, :amount, :reserved_at)";
        return $this->executeQuery($query, [
            ':rib' => $rib,
            ':amount' => $amount,
            ':reserved_at' => date('Y-m-d H:i:s')
        ]);
    }
}