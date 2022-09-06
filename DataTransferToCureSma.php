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

    private $resources;

    public function __construct() {
		parent::__construct();
        require_once $this->getModulePath() . "classes/Patient.php";
        require_once $this->getModulePath() . "classes/Condition.php";
        require_once $this->getModulePath() . "classes/Observation.php";
        require_once $this->getModulePath() . "classes/Encounter.php";
        require_once $this->getModulePath() . "classes/Medication.php";
        require_once $this->getModulePath() . "classes/MedicationStatement.php";
        require_once $this->getModulePath() . "classes/Procedures.php";
        require_once $this->getModulePath() . "classes/VitalSigns.php";
        require_once $this->getModulePath() . "classes/Allergies.php";
    }

    /**
     * Return the list of FHIR resources that are available.
     *
     * @return mixed
     */
    public function retrieveResources() {
        $resources = array(
            "demo"      => "Patient",
            "dx"        => "Conditions",
            "lab"       => "Observations",
            "enc"       => "Encounters",
            "med"       => "Medications",
            "px"        => "Procedures",
            "vitals"    => "VitalSigns",
            "all"       => "Allergies");

        return $resources;
    }

    /**
     * CRON JOBS
     *
     * Since REDCap works better with more, smaller crons instead of one big cron, I'll submit data
     * for each resource independently.  The Procedures and Vitals are going together since the
     * Encounters get added when either are submitted so might as well submit them all together. All these
     * crons will run on Saturday and just start them depending on what hour it is. Leaving labs until
     * the end since they will probably take the longest.
     *      Demographics                        -  8 am
     *      Procedure, Vitals and Encounters    -  9 am
     *      Diagnosis                           - 10 am
     *      Medications                         - 11 am
     *      Allergies                           - 12 pm
     *      Labs                                -  1 pm
     */

    public function sendToCureSMA() {

        // Retrieve day and time it is now to see if the crons should run
        $dayOfWeek = trim(date("l"));
        $hourOfDay = trim(date("H"));
        $this->emDebug("Hour of Day: " . $hourOfDay);
        $this->emDebug("Day of Week: " . $dayOfWeek);

        if ($dayOfWeek == 'Saturday') {
            if ($hourOfDay == 8) {
                $this->emDebug("Running cron for demo");
                $this->cronToSubmitDataToCureSMA("demo");
                $this->emDebug("Finished running cron for demo");
            } else if ($hourOfDay == 9) {
                $this->emDebug("Running cron for px,vitals");
                $this->cronToSubmitDataToCureSMA("px,vitals");
                $this->emDebug("Finished running cron for px,vitals");
            } else if ($hourOfDay == 10) {
                $this->emDebug("Running cron for dx");
                $this->cronToSubmitDataToCureSMA("dx");
                $this->emDebug("Finished running cron for dx");
            } else if ($hourOfDay == 11) {
                $this->emDebug("Running cron for med");
                $this->cronToSubmitDataToCureSMA("med");
                $this->emDebug("Finished running cron for med");
            } else if ($hourOfDay == 12) {
                $this->emDebug("Running cron for all");
                $this->cronToSubmitDataToCureSMA("all");
                $this->emDebug("Finished running cron for all");
            } else if ($hourOfDay == 13) {
                $this->emDebug("Running cron for lab");
                $this->cronToSubmitDataToCureSMA("lab");
                $this->emDebug("Finished running cron for lab");
            }
        }
    }


    /**
     * This function will be called by the cron job.
     *
     * @param $resourcesToSend
     * @return void
     */
    public function cronToSubmitDataToCureSMA($resourcesToSend) {

        $this->emDebug("Starting DataTransfer To CureSMA for resources $resourcesToSend");

        // Save pid so we can replace it after we are done processing
        $originalPid = $_GET['pid'];

        // There should only be one project with this enabled
        foreach($this->getProjectsWithModuleEnabled() as $localProjectId) {
            $_GET['pid'] = $localProjectId;
            $this->emDebug("Working on PID: " . $localProjectId);
            $status = $this->submitCureSmaData($resourcesToSend, $localProjectId);
        }

        // Put back original pid
        $_GET['pid'] = $originalPid;
        $this->emDebug("Leaving DataTransfer To CureSMA for resources $resourcesToSend");
        return;
    }

    /**
     * This function can be called by a cron to start submitting data to CureSMA
     * This function will retrieve the certificates to the CureSMA database and create temporary
     * files in the REDCap temp space. These files are needed to submit data.
     *
     * Once the data is submitted, the temporary files are deleted.
     */
    /**
     * @param $resourcesToSend
     * @param $pid
     * @return bool $status
     */
    public function submitCureSmaData($resourcesToSend, $pid) {

        // Get the certificates so we can submit data
        list($smaData, $smaParams) = $this->getConnectionParameters();
        if (empty($smaData) or empty($smaParams)) {
            $this->emError("Could not retrieve connection parameters to CureSMA for resources " . $resourcesToSend);
            return false;
        }

        // Find records that are participanting in the CureSMA registry
        $records = $this->getParticipatingRecords($pid);
        //$this->emDebug("Participants: " . json_encode($records));

        // If Procedures or Vital Signs are selected, ensure that Encounters is also selected
        // because Encounters is required for those resources
        if (((strpos($resourcesToSend, 'px') !== false) or (strpos($resourcesToSend, 'vitals') !== false))
            and (strpos($resourcesToSend, 'enc') === false)) {
            // Add encounter
            $resourcesToSend .= ",enc";
        }

        // Submit data for each record participating
        foreach($records as $record_id => $record_data) {
            foreach($record_data as $event_id => $event_data) {
                $study_id = $event_data['default_curesma_id'];
                $status = $this->submitRecordData($pid, $record_id, $study_id, $smaData, $smaParams, $resourcesToSend);
           }
        }

        // Delete certificate files
        $this->deleteCertFiles(array($smaData['certFile'], $smaData['certKey']));
        $this->emDebug("Returned from sendPutRequest with return status $status");

        return $status;
    }

    /**
     * Find records who are enrolled in the CureSMA registry.
     *
     * @param $pid
     * @return mixed
     */
    function getParticipatingRecords($pid) {

        $filter = "[enrolled_curesma(1)] = '1'";
        $recordField = $this->getRecordIdField();
        $records = REDCap::getData($pid, 'array', null, array($recordField, 'default_curesma_id'), null, null, null, null, null, $filter);

        return $records;
    }

    /**
     * Run through all the resources and see which ones should be sent.
     *
     * Run through each FHIR resource for each patient and retrieve the entries that have not been
     * submitted yet and submit them.
     *
     * @param $project_id
     * @param $record_id
     * @param $study_id
     * @param $smaData
     * @param $smaParams
     */
    function submitRecordData($project_id, $record_id, $study_id, $smaData, $smaParams, $resourcesToSend) {

        try {
            // Send Patient data
            if (strpos($resourcesToSend, 'demo') !== false) {
                $this->emDebug("Submitting patient data for record $record_id");
                $pat = new Patient($this, $project_id, $record_id, $study_id, $smaData, $smaParams);
                $status = $pat->sendPatientData();
                $this->emDebug("Return from submitting patient data $status");
            }

            // Send diagnostic code data
            if (strpos($resourcesToSend, 'dx') !== false) {
                $this->emDebug("Submitting diagnostic code data for record $record_id");
                $condition = new Condition($this, $project_id, $record_id, $study_id, $smaData, $smaParams);
                $status = $condition->sendConditionData();
                $this->emDebug("Return from submitting diagnostic code data $status for record $record_id");
            }

            // Send lab value data
            if (strpos($resourcesToSend, 'lab') !== false) {
                $this->emDebug("Submitting lab data for record $record_id");
                $lab = new Observation($this, $project_id, $record_id, $study_id, $smaData, $smaParams);
                $status = $lab->sendObservationData();
                $this->emDebug("Return from submitting lab data $status");
            }

            // Send encounter value data
            if (strpos($resourcesToSend, 'enc') !== false) {
                $this->emDebug("Submitting encounter data for record $record_id");
                $lab = new Encounter($this, $project_id, $record_id, $study_id, $smaData, $smaParams);
                $status = $lab->sendEncounterData();
                $this->emDebug("Return from submitting encounter data $status");
            }

            // Send Medication value data
            if (strpos($resourcesToSend, 'med') !== false) {
                $this->emDebug("Submitting medication data for record $record_id");
                $med = new Medication($this, $project_id, $record_id, $study_id, $smaData, $smaParams);
                $status = $med->sendMedicationData();
                $this->emDebug("Return from submitting medication data $status");

                // Send Medication Statement value data
                $this->emDebug("Submitting MedicationStatement data for record $record_id");
                $med = new MedicationStatement($this, $project_id, $record_id, $study_id, $smaData, $smaParams);
                $status = $med->sendMedicationStatementData();
                $this->emDebug("Return from submitting MedicationStatement data $status");
            }

            // Send Procedure codes (must be after Encounters since this references the encounter when the
            // procedure takes place)
            if (strpos($resourcesToSend, 'px') !== false) {
                $this->emDebug("Submitting procedure data for record $record_id");
                $px = new Procedures($this, $project_id, $record_id, $study_id, $smaData, $smaParams);
                $status = $px->sendProcedureData();
                $this->emDebug("Return from submitting Procedure data $status");
            }

            // Send Vital Signs value data (must be after Encounters since this references the encounter that
            // the vital sign data was taken)
            if (strpos($resourcesToSend, 'vitals') !== false) {
                $this->emDebug("Submitting vital sign data for record $record_id");
                $vs = new VitalSigns($this, $project_id, $record_id, $study_id, $smaData, $smaParams);
                $status = $vs->sendVitalSignData();
                $this->emDebug("Return from submitting vital sign data $status");
            }

            // Send AllergyIntolerance data
            if (strpos($resourcesToSend, 'all') !== false) {
                $this->emDebug("Submitting allergies data for record $record_id");
                $allergy = new Allergies($this, $project_id, $record_id, $study_id, $smaData, $smaParams);
                $status = $allergy->sendAllergyData();
                $this->emDebug("Return from submitting allergies data $status");
            }


        } catch (Exception $ex) {
            $this->emError("Caught exception for project $project_id. Exception: " . $ex);
        }

        return $status;
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
            return [[], []];
        } else {
            $this->emDebug("Successfully created the cert data file $smaCertFile");
        }

        // Retrieve the certificate key, write it to a file so we can use it in the curl call
        $key = $this->getSystemSetting('cert-key-data');
        $smaKeyFile = APP_PATH_TEMP . "smaCertKey.pem";
        $keyStatus = file_put_contents($smaKeyFile, $key);
        if (!$keyStatus) {
            $this->emError("Could not create file $smaKeyFile");
            return [[], []];
        } else {
            $this->emDebug("Successfully created the cert key file $smaKeyFile");
        }

        // Retrieve the password for the certificate and the url of the API to CureSMA
        $smaPassword = $this->getSystemSetting('cert-password');
        $smaUrl = $this->getSystemSetting('curesma-url');
        $smaData = array(
                        'url'       => $smaUrl,
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
