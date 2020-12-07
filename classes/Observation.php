<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */

require_once "httpPutTrait.php";
require_once "RepeatingForms.php";

use REDCap;
use Exception;

class Observation {

    use httpPutTrait;

    private $pid, $record_id, $study_id, $event_id, $event_name, $instrument, $fhir = array(), $smaData, $header;
    private $idSystem, $idUse, $fields;

    public function __construct($pid, $record_id, $study_id, $smaData, $fhirValues) {

        global $module;
        $this->pid              = $pid;
        $this->record_id        = $record_id;
        $this->smaData          = $smaData;
        $this->fhir             = $fhirValues;
        $this->study_id         = $study_id;

        // These are the patient specific parameters for FHIR format
        $this->instrument = $module->getProjectSetting('lab-form');
        $this->event_id = $module->getProjectSetting('lab-event');
        $this->event_name = REDCap::getEventNames(true, false, $this->event_id);

        // Retrieve the fields on the instrument
        $this->fields = REDCap::getFieldNames($this->instrument);

        // Determine the URL and header for the API call
        $this->url = $this->smaData['url'] . '/Observation/';
        $this->header = array("Content-Type:application/json");
    }

    public function sendObservationData() {

        global $module;

        // If an instrument is not specified for Observations (Labs), skip processing.
        if (is_null($this->instrument) || empty($this->instrument)) {
            return true;
        }

        // Retrieve patient data for this record
        $observation = $this->getObservationData();
        //$module->emDebug("Observation data to send: " . json_encode($observation));

        foreach ($observation[$this->record_id][$this->event_id] as $instance_id => $observationInfo) {

            // Package the data into FHIR format
            list($url, $body) = $this->packageObservationData($observationInfo);

            // Send to CureSMA
            //$module->emDebug("URL: " . $url);
            //$module->emDebug("Header: " . json_encode($this->header));
            //$module->emDebug("Body: " . $body);

            list($status, $error) = $this->sendPutRequest($url, $this->header, $body, $this->smaData);
            if (!$status) {
                $module->emError("Error sending data for project $this->pid, record $this->record_id, Observation " . json_encode($observationInfo) . " instance $instance_id. Error $error");
            } else {
                // If the resource was successfully sent, update the database to show the data was sent
                $this->saveObservationStatus($instance_id, $observationInfo);
            }
        }

        return $status;
    }

    private function getObservationData() {
        global $module;

        // Retrieve all diagnosis entries for this record
        try {
            $filter = '[' . $this->event_name . '][lab_sent_to_curesma(1)] = "0"';
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $rf->loadData($this->record_id, $this->event_id, $filter);
            $labs = $rf->getAllInstances($this->record_id, $this->event_id);
        } catch (Exception $ex) {
            $labs = null;
            $module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
        }

        return $labs;
    }

    private function saveObservationStatus($instance_id, $observationInfo) {
        global $module;

        // Retrieve all diagnosis entries for this record
        try {
            $observationInfo['lab_sent_to_curesma'] = array('1' => '1');
            $observationInfo['lab_date_data_curesma'] = date('Y-m-d H:i:s');
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $status = $rf->saveInstance($this->record_id, $observationInfo, $instance_id, $this->event_id);
            if (!$status) {
                $module->emError("Could not save data for instance $instance_id, project $this->pid, instrument $this->instrument");
            } else {
                $module->emDebug("Sucessfully saved data for instance $instance_id, instrument $this->instrument, project $this->pid");
            }
        } catch (Exception $ex) {
            $module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
        }
    }


    private function packageObservationData($labs) {

        global $module;

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
        $labComponentId = $labs['lab_component_id'];

        // Add the id to the URL
        $url = $this->url  . $labId;
        $labUrl = "http://terminology.hl7.org/CodeSystem/observation-category";
        $unitUrl = "http://unitsofmeasure.org";

        // Category is always labs
        $categoryCoding = array(
            array(
                "coding"  => array(
                    array(
                        "system"    => htmlspecialchars($labUrl),
                        "code"      => "laboratory",
                        "display"   => "Laboratory"
                    )
                )
            )
        );

        // Fill in the loinc info for this lab
        if ($labLoincCode != '') {
            $codeCoding = array(
                "coding" => array(
                    array(
                        "system" => "http://loinc.org",
                        "code" => $labLoincCode,
                        "display" => $labDescription
                    )
                )
            );
        } else {
            // If we don't know what the loinc is, just send the Stanford component_id
            $codeCoding = array(
                "coding" => array(
                    array(
                        "system" => "https://www.stanford.edu",
                        "code" => $labComponentId,
                        "display" => $labDescription
                    )
                )
            );

        }

        // Fill in the subject that this lab belongs to
        $subject        = array(
            "reference"     => "urn:Patient/$this->study_id"
        );

        // INR labs have INR as units which is incorrect.  Since INR values are ratios, I am going to
        // clear out INR so that CureSMA will accept it
        if ($labUnits == 'INR') {
            $labUnits = '%';
        }

        // Fill in the lab result values.  Check to see if it is number or string
        $valueQuantity  = array(
            "unit"      => $labUnits,
            "system"    => $unitUrl,
            "code"      => $labUnits
        );
        list($labResult, $comparator) = $this->returnLabResult($labResult);
        if (is_numeric($labResult)) {
            $valueQuantity['value'] = $labResult;
        } else {
            $valueQuantity['valueString'] = $labResult;
        }
        if (!is_null($comparator)) {
            $valueQuantity['comparator'] = $comparator;
       }

        // Check the low reference value.  If it is number, place in value field otherwise put in valueString.
        $ref = array();
        if ($labRefLow != '') {
            $lowRef = array(
                "unit"      => $labUnits,
                "system"    => $unitUrl,
                "code"      => $labUnits
            );
            list($result, $comparator) = $this->returnLabResult($labRefLow);
            if (is_numeric($result)) {
                $lowRef['value'] = $result;
            } else {
                $lowRef['valueString'] = $result;
            }
            $ref['low'] = $lowRef;
        }

        // Check the high reference value.  If it is number, place in value field otherwise put in valueString.
        if ($labRefHigh != '') {
            $highRef = array(
                "unit"      => $labUnits,
                "system"    => $unitUrl,
                "code"      => $labUnits
            );
            list($result, $comparator) = $this->returnLabResult($labRefHigh);
            if (is_numeric($result)) {
                $highRef['value'] = $result;
            } else {
                $highRef['valueString'] = $result;
            }
            $ref['high'] = $highRef;
        }

        // If there are low or high reference values, package it up
        if (!empty($ref)) {
            $referenceRange = array($ref);
        } else {
            $referenceRange = array();
        }

        $lab = array(
            "resourceType"      => "Observation",
            "id"                => $labId,
            "status"            => $labResultStatus,
            "code"              => $codeCoding,
            "category"          => $categoryCoding,
            "subject"           => $subject,
            "effectiveDateTime" => $labDateTime,
            "valueQuantity"     => $valueQuantity,
            "referenceRange"    => $referenceRange
        );

        $body = json_encode($lab, JSON_UNESCAPED_SLASHES);

        return array($url, $body);
    }

    function returnLabResult($labValue) {

        global $module;

        $value = trim($labValue);

        // Check to see if there is a comparator in the result value: <, <=, >, >=
        // If the result has a comparator, we need to take it out and add a comparator entry in the resource
        if ((substr($value, 0, 1) == '<') or (substr($value, 0, 1) == '>')) {
            $comparator = $value[0];
            $labResult = substr($value, 1);
            //$module->emDebug("Comparator: " . $comparator . ", lab result: $labResult, orig: $value");
        } else if ((substr($value, 0, 2) == '<=') or (substr($value, 0, 2) == '>=')) {
            $comparator = substr($value, 0, 2);
            $labResult = substr($value, 2);
            //$module->emDebug("Comparator: " . $comparator . ", lab result: $labResult, orig: $value");
        } else {
            $labResult = $value;
            $comparator = null;
        }

        // Check to see if there is a division operator in the result.  If so, perform the division
        // and return the result.  These division operators may be part of the result value because
        // we had to convert units and changed these values from kg->g (for instance).
        if (($nloc = strpos($labResult, '/')) === false) {
            // No division character so no additional processing needed
            $numerator = null;
            $denominator = null;
        } else {
            // There is a division character so perform the operation
            $numerator = substr($labResult, 0, $nloc);
            $denominator = substr($labResult, $nloc+1);
            if (is_numeric($numerator) and is_numeric($denominator) and ($denominator != 0)) {
                $labResult = $numerator/$denominator;
            }
        }

        //$module->emDebug("Location of /: $nloc, numerator: $numerator, denominator: $denominator, final result: $labResult");
        return array($labResult,$comparator);
    }

}
