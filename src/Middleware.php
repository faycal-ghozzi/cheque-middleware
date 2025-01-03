<?php

namespace PECCMiddleware;

use Exception;

use PECCMiddleware\Service\SoapService;

class Middleware
{
    private $soapConsultService;
    private $soapReserveService;
    private $dbConnection;

    public function __construct()
    {
        $this->soapConsultService = new SoapService('http://172.20.66.20:9095/WS_WORKFLOW/services?wsdl');
        $this->soapReserveService = new SoapService('http://172.20.66.51:9045/BTL_MONETIQUE/services?wsdl');
        // $this->dbConnection = new Database();
    }

    public function consultCheck($session_id, $source, $check_number, $rib, $cle_securite)
    {

        // if (Utils::isT24Down()) {
        //     return $this->consultFromBackup($rib, $check_number, $_ENV['T24BKP_PHONE_NUM']);
        // }

        $soapConsultServiceResponse = $this->soapConsultService->callService('WSWORKFLOWCHQ', $this->prepareConsultParams($rib));
        return $this->processConsultResponse($soapConsultServiceResponse);
    }

    private function prepareConsultParams($rib){
        return [
            "WebRequestCommon" => [
                "userName" => 'WAFASS01',
                "password" => 'MZ+BtlW@2502',
                "company" => 'TN0021001',
            ],
            "WSWORKFLOWCHQType" => [
                "enquiryInputCollection" => [
                    ["columnName" => "NUM.COMPTE", "criteriaValue" => substr($rib, 8, -2), "operand" => "EQ"]
                ]
            ]
        ];
    }

    private function processConsultResponse($response){
        
        $responseEval = Utils::evalResponse($response);
        $successIndicator = $responseEval['successIndicator'];
        $messages = $responseEval['messages'];


        switch ($successIndicator){
            case 'Success':
                return [
                    "http_response_code" => 200,
                    "success" => true,
                    "result" => "1",
                    "message" => "avec OTP",
                    "phone_number" => $response->WSWORKFLOWCHQType->gWSWORKFLOWCHQDetailType->mWSWORKFLOWCHQDetailType->PHONE,
                ];
            case 'T24Error':
                switch ($messages[2]){
                    case 'ERREUR COMPTE':
                        return [
                            'http_response_code' => 404,
                        ];
                    }
                break;
            default:
                return [
                    'http_response_code' => 400,
                ];
        }
        // ISSUECHEQUES
    }

    public function reserveCheck($session_id, $amount_in_millimes, $source, $check_number, $rib, $cle_securite)
    {
    //     if ($this->isT24Down()) {
    //         return $this->reserveFromBackup($rib, $amount_in_millimes, $_ENV['T24BKP_CURRENT_SOLDE']);
    //     }

        $soapReserveServiceResponse = $this->soapReserveService->callService('SOLDECLIENT', $this->prepareReserveParams($rib));
        return $this->processReserveResponse($soapReserveServiceResponse, $amount_in_millimes);
    }

    private function prepareReserveParams($rib){
        return [
            "WebRequestCommon" => [
                "userName" => 'FARAH.LA',
                "password" => 'ZIZOU@2025',
                "company" => 'TN0021008',
            ],
            "TRGENQACCOUNTSOLDE2Type" => [
                "enquiryInputCollection" => [
                    ["columnName" => "RRN", "criteriaValue" => "123456789", "operand" => "EQ"],
                    ["columnName" => "TR.DATE", "criteriaValue" => date('Ymd'), "operand" => "EQ"],
                    ["columnName" => "TR.HEURE", "criteriaValue" => date('His'), "operand" => "EQ"],
                    ["columnName" => "ACCOUNT", "criteriaValue" => substr($rib, 8, -2), "operand" => "EQ"]
                ]
            ]
        ];
    }

    private function processReserveResponse($response, $amount_in_millimes){

        $responseEval = Utils::evalResponse($response);
        $successIndicator = $responseEval['successIndicator'];
        $messages = $responseEval['messages'];

        $soldeDisponible = floatval($response->TRGENQACCOUNTSOLDE2Type->gTRGENQACCOUNTSOLDE2DetailType->mTRGENQACCOUNTSOLDE2DetailType->SOLDE)*1000;

        switch ($successIndicator){
            case 'Success':
                if ($soldeDisponible >= $amount_in_millimes) {
                    return [
                        "http_response_code" => 200,
                        "success" => true,
                        "message" => "Réservation du montant du chèque confirmée (test)",
                    ];
                }else{
                    return [
                        "http_response_code" => 412,
                        "success" => false,
                        "message" => "Provision insuffisante pour la réservation (test)",
                    ];
                }           
            case 'T24Error':
                switch ($messages[2]){
                    case 'ERREUR COMPTE':
                        return 404;
                    }
                break;
            default:
                return 400;
        }
    }

    public function initiateAssociateRIB($rib, $username){
        /*
            Fill up this func
        */
        return true;
    }

    public function confirmAssociateRIB($session_id, $otp){
        /*
        Fill up this func
        */
        return true;
    }

    public function dissociateRIB($pecc_id, $rib){
        /*
        Fill up this func
        */
        return true;
    }

}