<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */

require_once "httpPutTrait.php";
require_once "RepeatingForms.php";

use REDCap;
use Exception;
use Stanford\Utilities\RepeatingForms;

class Observation {

    use httpPutTrait;

    private $pid, $record_id, $event_id, $instrument, $fhir = array(), $smaData, $header;
    private $idSystem, $idUse, $module, $fields;

    public function __construct($pid, $record_id, $event_id, $smaData, $fhirValues, $module) {

        $this->pid              = $pid;
        $this->record_id        = $record_id;
        $this->event_id         = $event_id;
        $this->smaData          = $smaData;
        $this->fhir             = $fhirValues;
        $this->module           = $module;

        // These are the patient specific parameters for FHIR format
        $this->instrument = $this->module->getProjectSetting('lab-form');
        $this->module->emDebug("Lab form: " . $this->instrument);

        // Retrieve the fields on the instrument
        $this->fields = REDCap::getFieldNames($this->instrument);

        // Determine the URL and header for the API call
        $this->url = $this->smaData['url'] . '/Observation/';
        $this->header = array("Content-Type:application/json");
    }

    public function sendObservationData() {

        // If an instrument is not specified for Observations (Labs), skip processing.
        if (is_null($this->instrument) || empty($this->instrument)) {
            return true;
        }

        // Retrieve patient data for this record
        $observation = $this->getObservationData();

        foreach ($observation[$this->record_id] as $instance => $observationInfo) {

            // Package the data into FHIR format
            list($url, $body) = $this->packageObservationData($observationInfo);

            // Send to CureSMA
            $this->module->emDebug("URL: " . $url);
            $this->module->emDebug("Header: " . json_encode($this->header));
            $this->module->emDebug("Body: " . $body);

            list($status, $error) = $this->sendPutRequest($url, $this->header, $body, $this->smaData);
            if (!$status) {
                $this->module->emError("Error sending data for project $this->pid, record $this->record_id, Observation " . json_encode($observationInfo) . " instance $instance. Error $error");
            }
        }

        return $status;
    }

    private function getObservationData() {

        // Retrieve all diagnosis entries for this record
        try {
            $filter = '[lab_sent_to_curesma(1)] = "0"';
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $rf->loadData($this->record_id, $this->event_id, $filter);
            $labs = $rf->getAllInstances($this->record_id, $this->event_id);
            $this->module->emDebug("All instances of labs: " . json_encode($labs));
        } catch (Exception $ex) {
            $labs = null;
            $this->module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
        }

        return $labs;
    }

    private function packageObservationData($labs) {

        // Retrieve the data for this lab result
        $labId = $labs['lab_id'];
        $labDateTime = $labs['lab_date_time'];
        $labLoincCode = $labs['lab_loinc'];
        $labDescription = $labs['lab_loinc_description'];
        $labResult = $labs['lab_result'];
        $labResultStatus = $labs['lab_result_status'];
        $labUnits = $labs['lab_result_units'];
        $labRefLow = $labs['lab_ref_low'];
        $labRefHigh = $labs['lab_ref_high'];

        // Add the id to the URL
        $url = $this->url  . $labId;
        $labUrl = "http://terminology.hl7.org/CodeSystem/observation-category";
        $unitUrl = "http://unitsofmeasure.org";

        // Category is always labs
        $categoryCoding = array(
            array(
                "coding"  => array(
                    array(
                        "system"    => $labUrl,
                        "code"      => "laboratory",
                        "display"   => "Laboratory"
                    )
                )
            )
        );

        // Fill in the loinc info for this lab
        $codeCoding     = array(
            "coding"        => array(
                array(
                    "system"    => "http://loinc.org",
                    "code"      => $labLoincCode,
                    "display"   => $labDescription
                )
            )
        );

        // Fill in the subject that this lab belongs to
        $subject        = array(
            "reference"     => "urn:Patient/$this->record_id"
        );

        // Fill in the lab result values
        $valueQuantity  = array(
            "value"     => $labResult,
            "unit"      => $labUnits,
            "system"    => $unitUrl,
            "code"      => $labUnits
        );

        $ref = array();
        if ($labRefLow != '') {
            $lowRef = array(
                "value"     => $labRefLow,
                "unit"      => $labUnits,
                "system"    => $unitUrl,
                "code"      => $labUnits
            );
            $ref['low'] = $lowRef;
        }
        if ($labRefHigh != '') {
            $highRef = array(
                "value"     => $labRefHigh,
                "unit"      => $labUnits,
                "system"    => $unitUrl,
                "code"      => $labUnits
            );
            $ref['high'] = $highRef;
        }
        if (!empty($ref)) {
            $referenceRange = array($ref);
        } else {
            $referenceRange = array();
        }

        $lab = array(
            "resourceType"      => "Observation",
            "id"                => $labId,
            "status"            => $labResultStatus,
            "category"          => $categoryCoding,
            "code"              => $codeCoding,
            "subject"           => $subject,
            "effectiveDateTime" => $labDateTime,
            "valueQuantity"     => $valueQuantity,
            "referenceRange"    => $referenceRange
        );

        $body = json_encode($lab);

        return array($url, $body);
    }

}
