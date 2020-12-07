<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */

require_once "httpPutTrait.php";
require_once "RepeatingForms.php";

use REDCap;
use Exception;

/**
 * The Encounter class packages encounters information to send to CureSMA.  Very little data from each encounter is sent but CureSMA may want
 * additional data such as vitals.  We are capturing in-person and telemedicine encounters and bypassing encounters that are not treatment
 * related, such as phone calls for refills, phone calls for appointment changes, etc. or therapy treatments, such as physical or occupational
 * therapies. In addition to sending the date of the encounter and the encounter type, we are trying to capture whether it is a hospitalization
 * versus a hospital encounter. Hospital encounter include imaging scans, outpatient procedures, etc.
 *
 * Some of the encounters that we send may have to be fine-tuned based on desires from the SMA group and the CureSMA organization.
 *
 * Class Encounter
 * @package Stanford\DataTransferToCureSma
 */

class Encounter {

    use httpPutTrait;

    private $pid, $record_id, $event_id, $event_name, $instrument, $fhir = array(), $smaData, $header;
    private $idSystem, $idUse, $fields, $study_id;

    public function __construct($pid, $record_id, $study_id, $smaData, $fhirValues) {

        global $module;

        $this->pid              = $pid;
        $this->record_id        = $record_id;
        $this->smaData          = $smaData;
        $this->fhir             = $fhirValues;
        $this->study_id         = $study_id;

        // These are the patient specific parameters for FHIR format
        $this->instrument = $module->getProjectSetting('encounter-form');
        $this->event_id = $module->getProjectSetting('encounter-event');
        $this->event_name = REDCap::getEventNames(true, false, $this->event_id);

        // Retrieve the fields on the instrument
        $this->fields = REDCap::getFieldNames($this->instrument);

        // Determine the URL and header for the API call
        $this->url = $this->smaData['url'] . '/Encounter/';
        $this->header = array("Content-Type:application/json");
    }

    /**
     * This function will find encounters that have not been submitted yet, package them up and send them to CureSMA.  If each packet
     * was successfully sent, the status for that encounter will be set with a timestamp.
     *
     * @return bool|mixed
     */
    public function sendEncounterData() {
        global $module;

        // If an instrument is not specified for Conditions (Diagnosis), skip processing.
        if (is_null($this->instrument) || empty($this->instrument)) {
            return true;
        }

        // Retrieve patient data for this record
        $encounters = $this->getEncounterData();
        //$module->emDebug("This is the encounter data: " . json_encode($encounters));

        $sentInstances = array();
        foreach($encounters[$this->record_id][$this->event_id] as $instance => $encountersInfo) {

            // Package the data into FHIR format
            list($url, $body) = $this->packageEncounterData($encountersInfo);

            // Send to CureSMA
            //$module->emDebug("URL: " . $url);
            //$module->emDebug("Header: " . json_encode($this->header));
            //$module->emDebug("Body: " . $body);

            list($status, $error) = $this->sendPutRequest($url, $this->header, $body, $this->smaData);
            if (!$status) {
                $module->emError("Error sending data for project $this->pid, record $this->record_id, Encounter " . json_encode($encountersInfo) . " instance $instance. Error $error");
            } else {

                // Set the checkbox to say the data was sent to CureSMA
                $this->saveEncounterStatus($instance, $encountersInfo);
            }
        }

        return $status;
    }


    /**
     * Finds the encounters that have not yet been set to CureSMA.
     *
     * @return array - list of encounters to send
     */
    private function getEncounterData() {
        global $module;

        // Retrieve all diagnosis entries for this record
        try {
            $filter = "[" . $this->event_name . "][enc_sent_to_curesma(1)] = '0'";
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $rf->loadData($this->record_id, $this->event_id, $filter);
            $encounters = $rf->getAllInstances($this->record_id, $this->event_id);
        } catch (Exception $ex) {
            $encounters = null;
            $module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
        }

        return $encounters;
    }

    /**
     * Save the fact that we successfully sent the data to CureSMA and the timestamp when the data was sent.
     *
     * @param $instance_id
     * @param $encountersInfo
     */
    private function saveEncounterStatus($instance_id, $encountersInfo) {
        global $module;

        // Retrieve all diagnosis entries for this record
        try {
            $encountersInfo['enc_sent_to_curesma'] = array('1' => '1');
            $encountersInfo['enc_date_data_curesma'] = date('Y-m-d H:i:s');
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $status = $rf->saveInstance($this->record_id, $encountersInfo, $instance_id, $this->event_id);
            if (!$status) {
                $module->emError("Could not save data for instance $instance_id, project $this->pid, instrument $this->instrument");
            } else {
                $module->emDebug("Sucessfully saved data for instance $instance_id, instrument $this->instrument, project $this->pid");
            }
        } catch (Exception $ex) {
            $module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
        }
    }

    /**
     * Package up the encounter data.  We are currently sending the start time or admit time, discharge time,
     * encounter type and a description of what the encounter was for.
     *
     * @param $encountersInfo
     * @return array
     */
    private function packageEncounterData($encountersInfo) {

        global $module;

        // Retrieve data for this condition
        $encID = $encountersInfo['enc_id'];

        // Add the id of this condition to the URL
        $url = $this->url . $encID;

        // Retrieve the start and end date of this encounter
        if (empty($encountersInfo["enc_end_datetime"])) {
            $encDate = array("start" => $encountersInfo["enc_start_datetime"]);
        } else {
            $encDate = array("start" => $encountersInfo["enc_start_datetime"],
                             "end" => $encountersInfo["enc_end_datetime"]);
        }
        $encStatus = $encountersInfo["enc_status"];

        // This is the generated test as to the reason for the appointment or hospitalization
        $reason = array("status"    => "generated",
                        "div"       => "<div>" . $encountersInfo["enc_reason"] . "</div>");

        // Set the patient this belongs to
        $subject        = array(
            "reference"     => "urn:Patient/$this->study_id"
        );

        // Package the encounter data for this resource.
        $category = array(
            "resourceType"  => "Encounter",
            "id"            => "$encID",
            "status"        => $encStatus,
            "text"          => $reason,
            "subject"       => $subject,
            "period"        => $encDate
        );

        $body = json_encode($category, JSON_UNESCAPED_SLASHES);
        //$module->emDebug("Package for encounter: $body");

        return array($url, $body);
    }

}
