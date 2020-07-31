<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */

require_once "httpPutTrait.php";
require_once "RepeatingForms.php";

use REDCap;
use Exception;

/*
{
  "resourceType": "Encounter",
  "id": "enc1-stan1-1",
  "text": {
    "status": "generated",
    "div": "<div xmlns=\"http://www.w3.org/1999/xhtml\">Encounter with patient @example</div>"
  },
  "status": "in-progress",
  "subject": {
    "reference": "urn:Patient/stan-1"
  },
  "period": {
    "end": "2017-01-09T15:30:11",
    "start": "2017-01-09T14:25:15"
  }
}
 */

class Encounter {

    use httpPutTrait;

    private $pid, $record_id, $event_id, $instrument, $fhir = array(), $smaData, $header;
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

        // Retrieve the fields on the instrument
        $this->fields = REDCap::getFieldNames($this->instrument);

        // Determine the URL and header for the API call
        $this->url = $this->smaData['url'] . '/Encounter/';
        $this->header = array("Content-Type:application/json");
    }

    public function sendEncounterData() {
        global $module;

        // If an instrument is not specified for Conditions (Diagnosis), skip processing.
        if (is_null($this->instrument) || empty($this->instrument)) {
            return true;
        }

        // Retrieve patient data for this record
        $encounters = $this->getEncounterData();
        $module->emDebug("This is the condition data: " . json_encode($encounters));

        $sentInstances = array();
        foreach($encounters[$this->record_id] as $instance => $encountersInfo) {

            // Package the data into FHIR format
            list($url, $body) = $this->packageEncounterData($encountersInfo);

            // Send to CureSMA
            $module->emDebug("URL: " . $url);
            $module->emDebug("Header: " . json_encode($this->header));
            $module->emDebug("Body: " . $body);

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


    private function getEncounterData() {
        global $module;

        // Retrieve all diagnosis entries for this record
        try {
            $filter = "[enc_sent_to_curesma(1)] = '0'";
            $module->emDebug("In getEncounterData: filter $filter");
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $rf->loadData($this->record_id, $this->event_id, $filter);
            $encounters = $rf->getAllInstances($this->record_id, $this->event_id);
            $module->emDebug("Retrieved data: " . json_encode($encounters));
        } catch (Exception $ex) {
            $encounters = null;
            $module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
        }

        return $encounters;
    }

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

    private function packageEncounterData($encountersInfo) {

        global $module;

        // Retrieve data for this condition
        $encID = $encountersInfo['enc_id'];
        $encInpatient = $encountersInfo['enc_hospitalized'];

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
        $module->emDebug("Package for encounter: $body");

        return array($url, $body);
    }

}
