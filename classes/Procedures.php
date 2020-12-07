<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */

require_once "httpPutTrait.php";
require_once "RepeatingForms.php";

use REDCap;
use Exception;

/**
 * The class will find all Procedures codes for each registered patient and package up each
 * code and submit the resource to CureSMA. The procedure codes are brought over from STARR and  include
 * all procedures in shc_procedure.
 *
 * The amount of data being sent to CureSMA is a minimal set.  We are only packaging up the ICD10 code, whether
 * or not the condition is still active and the starting date of this condition.  Additional data may be
 * stored in the REDCap project but may not be sent to CureSMA.
 *
 * Class Procedures
 * @package Stanford\DataTransferToCureSma
 */

class Procedures {

    use httpPutTrait;

    private $pid, $record_id, $event_id, $event_name, $instrument, $fhir = array(), $smaData, $header;
    private $idSystem, $idUse, $fields, $study_id, $enc_instrument, $enc_event_id, $enc_event_name;

    public function __construct($pid, $record_id, $study_id, $smaData, $fhirValues) {
        global $module;

        $this->pid              = $pid;
        $this->record_id        = $record_id;
        $this->smaData          = $smaData;
        $this->fhir             = $fhirValues;
        $this->study_id         = $study_id;

        // These are the patient specific parameters for FHIR format
        $this->instrument = $module->getProjectSetting('procedure-form');
        $this->event_id = $module->getProjectSetting('procedure-event');
        $this->event_name = REDCap::getEventNames(true, false, $this->event_id);

        // Retrieve the fields on the instrument
        $this->fields = REDCap::getFieldNames($this->instrument);

        // Determine the URL and header for the API call
        $this->url = $this->smaData['url'] . '/Procedure/';
        $this->header = array("Content-Type:application/json");

        // We will need to link the procedure to an encounter so retrieve the encounter instrument and event
        $this->enc_instrument = $module->getProjectSetting('encounter-form');
        $this->enc_event_id = $module->getProjectSetting('encounter-event');
        $this->enc_event_name = REDCap::getEventNames(true, false, $this->enc_event_id);
    }

    /**
     * Ensure the Procedure resource is selected to be used.  If not, don't send any data. Otherwise,
     * retrieve the new procedures to send, package them up into the current FHIR v3 format and send
     * to CureSMA.  If the send was successful, mark the condition as having been sent and save the record.
     *
     * @return bool|mixed - true when data was successfully sent to CureSMA
     */
    public function sendProcedureData() {
        global $module;

        // If an instrument is not specified for Procedure, skip processing.
        if (is_null($this->instrument) || empty($this->instrument)) {
            return true;
        }

        // Retrieve patient data for this record
        $procedures = $this->getProcedureData();

        // Retrieve encounter data so we can find which ones corresponds to the procedure
        $encounters = $this->getEncounterData();

        $sentInstances = array();
        foreach($procedures[$this->record_id][$this->event_id] as $instance => $procedureInfo) {

            // Find the encounter corresponding to this procedure
            $enc_id = $this->findEncForProcedure($encounters, $procedureInfo['proc_date']);

            // Package the data into FHIR format
            list($url, $body) = $this->packageProcedureData($procedureInfo, $enc_id);

            // Send to CureSMA
            //$module->emDebug("URL: " . $url);
            //$module->emDebug("Header: " . json_encode($this->header));
            //$module->emDebug("Body: " . $body);

            list($status, $error) = $this->sendPutRequest($url, $this->header, $body, $this->smaData);
            if (!$status) {
                $module->emError("Error sending data for project $this->pid, record $this->record_id, Procedure " . json_encode($procedureInfo) . " instance $instance. Error $error");
            } else {

                // Set the checkbox to say the data was sent to CureSMA
                $status = $this->saveProcedureStatus($instance, $enc_id);
            }
        }

        return $status;
    }

    /**
     * Retrieve the CPT codes that have not been sent to CureSMA yet.
     *
     * @return array|bool|null
     */
    private function getProcedureData() {

        global $module;

        // Retrieve all procedure entries for this record
        try {
            $filter = "[" . $this->enc_event_name . "][proc_sent_to_curesma(1)] = '0'";
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $rf->loadData($this->record_id, $this->event_id, $filter);
            $procedures = $rf->getAllInstances($this->record_id, $this->event_id);
        } catch (Exception $ex) {
            $procedures = null;
            $module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
        }

        return $procedures;
    }


    /**
     * @return array - encounters stored in repeating forms
     */
    private function getEncounterData() {

        global $module;

        $enc_array = array();
        try {

            $rf = new RepeatingForms($this->pid, $this->enc_instrument);
            $rf->loadData($this->record_id, $this->enc_event_id);
            $encounters = $rf->getAllInstances($this->record_id, $this->enc_event_id);

            foreach($encounters[$this->record_id][$this->enc_event_id] as $instance_id => $encounter) {
                $date_only = substr($encounter['enc_start_datetime'], 0, strpos($encounter['enc_start_datetime'], ' '));
                $discharge_date_only = substr($encounter['enc_end_datetime'], 0, strpos($encounter['enc_end_datetime'], ' '));
                $enc_array[$encounter['enc_id']]['start_date'] = $date_only;
                $enc_array[$encounter['enc_id']]['end_date'] = $discharge_date_only;
            }

        } catch (Exception $ex) {
            $encounters = null;
            $module->emError("Exception when instantiating the Repeating Forms class [encounters] for project $this->pid instrument $this->instrument");
        }

        return $enc_array;
    }

    private function findEncForProcedure($encounters, $proc_date) {

        global $module;

        // Use only the date portion of the proc date and not time
        $proc_date_only = substr($proc_date, 0, strpos($proc_date, ' '));

        $this_enc_id = null;
        foreach ($encounters as $enc_id => $enc_dates) {

            // If the encounter date is empty, this was a one-day encounter so the procedure date has to match the encounter date
            if (is_null($enc_dates['end_date']) or empty($enc_dates['end_date'])) {
                if ($enc_dates['start_date'] == $proc_date_only) {
                    $this_enc_id = $enc_id;
                    break;
                }
            } else {
                // If the encounter end date is not empty, check to see if the procedure happened between the start and end dates
                if (($enc_dates['end_date'] >= $proc_date_only) and ($enc_dates['start_date'] <= $proc_date_only)) {
                    $this_enc_id = $enc_id;
                    break;
                }

            }
        }

        return $this_enc_id;
    }


    /**
     * Save the fact that this procedure was already submitted to CureSMA.
     *
     * @param $instance_id - Instance of the repeatable form that holds this procedure description
     * @param $enc_id - The encounter ID that corresponds to this procedure.
     */
    private function saveProcedureStatus($instance_id, $enc_id) {

        global $module;

        $procInfo = array();
        // Save the fact that we sent this procedure to CureSMA and store the enc_id in this procedure record
        try {
            $procInfo['proc_sent_to_curesma'] = array('1' => '1');
            $procInfo['proc_date_data_curesma'] = date('Y-m-d H:i:s');
            $procInfo['proc_enc_id'] = $enc_id;
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $status = $rf->saveInstance($this->record_id, $procInfo, $instance_id, $this->event_id);
            if (!$status) {
                $module->emError("Could not save data for instance $instance_id, project $this->pid, instrument $this->instrument");
            } else {
                $module->emDebug("Sucessfully saved data for instance $instance_id, instrument $this->instrument, project $this->pid");
            }
        } catch (Exception $ex) {
            $module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
            $status = false;
        }

        return $status;
    }

    /**
     * This routine will package the Procedure data to send to CureSMA.  If additional information is added
     * to the message, it should be added here.
     *
     * @param $procedureInfo - Information about this procedure
     * @return array - URL used to send this resource and the body (package) of the message
     */
    private function packageProcedureData($procedureInfo, $enc_id) {

        global $module;

        // Retrieve data for this condition
        $procedureID = $procedureInfo['proc_id'];
        $procedureCode = $procedureInfo['proc_code'];
        $procedureCodeType = $procedureInfo['proc_code_type'];
        $procedureDescription = $procedureInfo['proc_description'];
        $procedureDate = $procedureInfo['proc_date'];
        $procedureStatus = $procedureInfo['proc_status'];

        // Add the id of this condition to the URL
        $url = $this->url . $procedureID;

        // This is the person who is matched to this condition
        $subject = array(
            "reference" => "urn:Patient/$this->study_id"
        );

        // Package the category of resource. These are all procedures. The category type is either CPT or ICD10
        if ($procedureCodeType == 'CPT') {
            $org = "http://www.ama-assn.org/go/cpt";
        } else {
            $org = "https://www.cdc.gov/";
        }

        $code = array(
            "coding" => array(
                array(
                    "system" => $org,
                    "code" => $procedureCode,
                    "display" => $procedureDescription
                )
            )
        );

        if (empty($enc_id)) {
            $encounter = 'unk';
        } else {
            $encounter = $enc_id;
        }
        $ref_enc = array("reference" => "urn:Encounter/" . $encounter);

        // Package the complete Procedure resource
        $procedure = array(
            "resourceType"          => "Procedure",
            "id"                    => $procedureID,
            "status"                => $procedureStatus,
            "code"                  => $code,
            "subject"               => $subject,
            "performedDateTime"     => $procedureDate,
            "context"               => $ref_enc
        );


        $body = json_encode($procedure, JSON_UNESCAPED_SLASHES);

        return array($url, $body);
    }

}
