<?php
namespace Stanford\DataTransferToCureSma;

require_once "emLoggerTrait.php";

use \Exception;
use \REDCap;

/**
 * This External Module will automate the process of submitting data for consented SMA patients to CureSMA.
 * Each time this module is run, the list of consented patients will be retrieved but querying for the patients
 * with the [enrolled_curesma] checkbox selected.  Once the list of patients is found, each patient will be
 * cycled through for the following resources: Patient (demographics), Condition (Diagnosis codes - currently
 * only submitting conditions on the problem_list), Encounter, Observation (labs), Medication and MedicationStatement.
 *
 * Each resource is saved on a repeating form in the project (except Medication which will be described below).
 * Each repeating form has a checkbox to indicate if the resource has already been sent to CureSMA and when it
 * was sent.  Once sent, it will not be sent again.
 *
 * The Medication resource is handled differently than the rest of the resources.  The data for the other
 * resources are self-contained on each repeating form.  The medication resource is based on the whole
 * patient population.  A Medication resource should only be created once and the MedciationStatment links
 * the Medication resource to the patient. So, the Medication resource will retrieve the list of
 * medications that have not be sent to CureSMA yet, and create a Medication resource is a companion
 * REDCap project pid=20187.  Once the Medication resource is created, the Medication identifier will
 * be added to each patient medication record so a MedicationStatement can be created.
 *
 * This module is enabled for the Stanford SMA Registry pid 19901.
 *
 * Class DataTransferToCureSma
 * @package Stanford\DataTransferToCureSma
 */

class DataTransferToCureSma extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public function __construct() {
		parent::__construct();
        require_once $this->getModulePath() . "classes/Patient.php";
        require_once $this->getModulePath() . "classes/Condition.php";
        require_once $this->getModulePath() . "classes/Observation.php";
        require_once $this->getModulePath() . "classes/Encounter.php";
        require_once $this->getModulePath() . "classes/Medication.php";
        require_once $this->getModulePath() . "classes/MedicationStatement.php";
        require_once $this->getModulePath() . "classes/VitalSigns.php";
        require_once $this->getModulePath() . "classes/Procedures.php";
    }

    /**
     * This function can be called by a cron or by a webpage to start submitting data to CureSMA
     * This function will retrieve the certificates to the CureSMA database and create temporary
     * files in the REDCap temp space. These files are needed to submit data.
     *
     * Once the data is submitted, the temporary files are deleted.
     */
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

    /**
     * Find records who are enrolled in the CureSMA registry.
     *
     * @param $pid
     * @return mixed
     */
    function getParticipatingRecords($pid) {

        $filter = "[enrolled_curesma(1)] = '1'";
        $recordField = REDCap::getRecordIdField();
        $records = REDCap::getData($pid, 'array', null, array($recordField, 'default_curesma_id'), null, null, null, null, null, $filter);
        $this->emDebug("Retrieved records: " . json_encode($records));

        return $records;
    }

    /**
     * Run through each FHIR resource for each patient and retrieve the entries that have not been
     * submitted yet and submit them.
     *
     * @param $project_id
     * @param $record_id
     * @param $study_id
     * @param $smaData
     * @param $smaParams
     */
    function submitRecordData($project_id, $record_id, $study_id, $smaData, $smaParams) {

        try {

            // Send Patient data
            $this->emDebug("Submitting patient data for record $record_id");
            $pat = new Patient($project_id, $record_id, $study_id, $smaData, $smaParams);
            $status = $pat->sendPatientData();
            $this->emDebug("Return from submitting patient data $status");

            // Send diagnostic code data
            $this->emDebug("Submitting diagnostic code data for record $record_id");
            $condition = new Condition($project_id, $record_id, $study_id, $smaData, $smaParams);
            $status = $condition->sendConditionData();
            $this->emDebug("Return from submitting diagnostic code data $status");

            /*
            // Send lab value data
            $this->emDebug("Submitting lab data for record $record_id");
            $lab = new Observation($project_id, $record_id, $study_id, $smaData, $smaParams);
            $status = $lab->sendObservationData();
            $this->emDebug("Return from submitting lab data $status");
            */

            // Send encounter value data
            $this->emDebug("Submitting encounter data for record $record_id");
            $lab = new Encounter($project_id, $record_id, $study_id, $smaData, $smaParams);
            $status = $lab->sendEncounterData();
            $this->emDebug("Return from submitting encounter data $status");

            /*
            // Send Medication value data
            $this->emDebug("Submitting medication data for record $record_id");
            $med = new Medication($project_id, $record_id, $study_id, $smaData, $smaParams);
            $status = $med->sendMedicationData();
            $this->emDebug("Return from submitting medication data $status");

            // Send Medication Statement value data
            $this->emDebug("Submitting MedicationStatement data for record $record_id");
            $med = new MedicationStatement($project_id, $record_id, $study_id, $smaData, $smaParams);
            $status = $med->sendMedicationStatementData();
            $this->emDebug("Return from submitting MedicationStatement data $status");
            */

            // Send Vital Signs value data
            $this->emDebug("Submitting vital sign data for record $record_id");
            $vs = new VitalSigns($project_id, $record_id, $study_id, $smaData, $smaParams);
            $status = $vs->sendVitalSignData();
            $this->emDebug("Return from submitting vital sign data $status");

            // Send Procedure codes
            $this->emDebug("Submitting procedure data for record $record_id");
            $vs = new Procedures($project_id, $record_id, $study_id, $smaData, $smaParams);
            $status = $vs->sendProcedureData();
            $this->emDebug("Return from submitting Procedure data $status");

        } catch (Exception $ex) {
            $this->emError("Caught exception for project $project_id. Exception: " . $ex);
        }

    }

    /**
     * This routine will retrieve the connection parameters to the CureSMA endpoint. Each of the resources
     * will use these parameters to connect.
     *
     * @return array[]
     */
    function getConnectionParameters() {

        /*
        // This is here temporarily to read in the certificate file and store it in the system settings
        $smaCertFile = $this->getModulePath() . $this->getSystemSetting('cert-file');
        $certFile = file_get_contents($smaCertFile);
        //$this->emDebug("This is the cert file: " . $certFile);
        $this->setSystemSetting('cert-file-data', $certFile);

        $smaKeyFile = $this->getModulePath() . $this->getSystemSetting('cert-key');
        $certKey = file_get_contents($smaKeyFile);
        //$this->emDebug("This is the cert file: " . $certKey);
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

    /**
     * This routine will delete the temporary files created to hold the connection certificates.
     *
     * @param $filesToDelete
     */
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
