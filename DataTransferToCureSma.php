<?php
namespace Stanford\DataTransferToCureSma;

require_once "emLoggerTrait.php";

use \Exception;
use \REDCap;

class DataTransferToCureSma extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public function __construct() {
		parent::__construct();
        require_once $this->getModulePath() . "classes/Patient.php";
        require_once $this->getModulePath() . "classes/Condition.php";
        require_once $this->getModulePath() . "classes/Observation.php";
    }

    // This function can be called by a cron or by a webpage to start submitting data to CureSMA
    public function submitCureSmaData() {
        global $pid;

        // Get the certificates so we can submit data
        list($smaData, $smaParams) = $this->getConnectionParameters();

        // Find records that are participanting in the CureSMA registry
        $records = $this->getParticipatingRecords($pid);

        // Submit data for each record participating
        foreach($records as $record_id => $record_data) {
            foreach($record_data as $event_id => $event_data) {
                $study_id = $event_data['default_curesma_id'];
                $this->emDebug("This is the study id: $study_id");
                $this->submitRecordData($pid, $record_id, $study_id, $smaData, $smaParams);
            }
        }

        // Delete certificate files
        $status = $this->deleteCertFiles(array($smaData['certFile'], $smaData['certKey']));
        $this->emDebug("Returned from sendPutRequest with return status $status");

    }

    function getParticipatingRecords($pid) {

        $filter = "[enrolled_curesma(1)] = '1'";
        $recordField = REDCap::getRecordIdField();
        $records = REDCap::getData($pid, 'array', null, array($recordField, 'default_curesma_id'), null, null, null, null, null, $filter);
        $this->emDebug("Retrieved records: " . json_encode($records));

        return $records;
    }

    function submitRecordData($project_id, $record_id, $study_id, $smaData, $smaParams) {

        try {

            // Save Patient data
            $pat = new Patient($project_id, $record_id, $study_id, $smaData, $smaParams, $this);
            $status = $pat->sendPatientData();

            // Save diagnostic code data
            $condition = new Condition($project_id, $record_id, $study_id, $smaData, $smaParams, $this);
            $status = $condition->sendConditionData();

            // Save lab value data
            $lab = new Observation($project_id, $record_id, $study_id, $smaData, $smaParams, $this);
            $status = $lab->sendObservationData();

        } catch (Exception $ex) {
            $this->emError("Caught exception for project $project_id. Exception: " . $ex);
        }

    }

    function getConnectionParameters() {

        /*
        // This is here temporarily to read in the certificate file and store it in the system settings
        $smaCertFile = $this->getModulePath() . $this->getSystemSetting('cert-file');
        $certFile = file_get_contents($smaCertFile);
        $this->emDebug("This is the cert file: " . $certFile);
        $this->setSystemSetting('cert-file-data', $certFile);

        $smaKeyFile = $this->getModulePath() . $this->getSystemSetting('cert-key');
        $certKey = file_get_contents($smaKeyFile);
        $this->emDebug("This is the cert file: " . $certKey);
        $this->setSystemSetting('cert-key-data', $certKey);
        */

        // Retrieve the authorization certificate and save it in a temp file so it can be used by curl
        $cert = $this->getSystemSetting('cert-file-data');
        $smaCertFile = APP_PATH_TEMP . "smaCertFile.pem";
        $fileStatus = file_put_contents($smaCertFile, $cert);
        if (!$fileStatus) {
            $this->emError("Could not create file $smaCertFile");
        }

        // Retrieve the certificate key, write it to a file so we can use it in the curl call
        $key = $this->getSystemSetting('cert-key-data');
        $smaKeyFile = APP_PATH_TEMP . "smaCertKey.pem";
        $keyStatus = file_put_contents($smaKeyFile, $key);
        if (!$keyStatus) {
            $this->emError("Could not create file $smaKeyFile");
        }

        // Retrieve the password for the certificate and the url of the API to CureSMA
        $smaPassword = $this->getSystemSetting('cert-password');
        $smaUrl = $this->getSystemSetting('curesma-url');
        $smaData = array(
                        'url'        => $smaUrl,
                        'certFile'  => $smaCertFile,
                        'certKey'   => $smaKeyFile,
                        'password'  => $smaPassword
                        );

        // Get the submitting organization
        $submitOrg = $this->getSystemSetting('submitting-org');

        $smaParams = array(
                        "assignor" => $submitOrg,
                        "system" => "http://terminology.hl7.org/CodeSystem/v2-0203"
                        );

        return array($smaData, $smaParams);
    }

    function deleteCertFiles($filesToDelete) {

        foreach($filesToDelete as $filename) {
            $status = unlink($filename);
            if ($status) {
                $this->emDebug("Successfully deleted file $filename");
            } else {
                $this->emError("Error occurred while deleting file $filename");
            }
        }
    }

}
