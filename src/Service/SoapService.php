<?php

namespace PECCMiddleware\Service;

use SoapClient;
use SoapFault;
use Exception;

class SoapService{

    private $client;

    public function __construct($wsdl) {
        $this->client = new SoapClient($wsdl);
    }

    public function callService($method, $params){
        try{
            return $this->client->__soapCall($method, [$params]);
        }catch (SoapFault $e){
            throw new Exception("SOAP Error: " . $e->getMessage());
        }
    }
}