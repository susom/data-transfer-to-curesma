<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */

trait httpPutTrait
{

    function  sendPutRequest($url, $headers, $message, $smaData) {
        global $module;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_SSLENGINE_DEFAULT, 1);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
        curl_setopt($ch, CURLOPT_SSLCERT, $smaData['certFile']);
        curl_setopt($ch, CURLOPT_SSLKEY, $smaData['certKey']);
        curl_setopt($ch, CURLOPT_KEYPASSWD, $smaData['password']);

        // Retrieve all returned information
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $module->emDebug("HTTP Code: " . $http_code);
        $module->emDebug("Response: " . json_encode($response));
        $module->emDebug("Info: " . json_encode($info));
        $module->emDebug("Error:" . $error);

        if ($http_code == 200) {
            return array(true, null);
        } else {
            return array(false, $error);
        }
    }

}
