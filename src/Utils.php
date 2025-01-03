<?php

namespace PECCMiddleware;

class Utils{
    public static function normalizeArrayKeys($array) {
        $normalizedArray = [];
        foreach ($array as $key => $value) {
            $normalizedKey = trim($key);
            if (is_array($value)) {
                $value = self::normalizeArrayKeys($value);
            }
            $normalizedArray[$normalizedKey] = $value;
        }
        return $normalizedArray;
    }

    public static function isT24Down()
    {
        $currentHour = (int) date('H');
        $currentMinute = (int) date('i');
        return ($currentHour > 20 || ($currentHour < 7 || ($currentHour === 7 && $currentMinute < 30)));

        return $isDown;
    }

    public static function evalResponse($response){
        if(!isset($response->Status) || !isset($response->Status->successIndicator)){
            return [
                'http_response_code' => 500,
                'success' => false,
                'message' => 'Invalid Response from SOAP service'
            ];
        }
        
        return [
            "successIndicator" => $response->Status->successIndicator,
            "messages" => isset($response->Status->messages) ? $response->Status->messages : []
        ];
    }
}
