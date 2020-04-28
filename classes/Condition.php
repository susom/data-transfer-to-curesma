<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */

require_once "httpPutTrait.php";
require_once "RepeatingForms.php";

use REDCap;
use Exception;
use Stanford\Utilities\RepeatingForms;

class Condition {

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
        $this->instrument = $this->module->getProjectSetting('diagnosis-form');

        // Retrieve the fields on the instrument
        $this->fields = REDCap::getFieldNames($this->instrument);

        // Determine the URL and header for the API call
        $this->url = $this->smaData['url'] . '/Condition/';
        $this->header = array("Content-Type:application/json");
    }

    public function sendConditionData() {

        // If an instrument is not specified for Conditions (Diagnosis), skip processing.
        if (is_null($this->instrument) || empty($this->instrument)) {
            return true;
        }

        // Retrieve patient data for this record
        $conditions = $this->getConditionData();

        foreach($conditions[$this->record_id] as $instance => $conditionInfo) {

            // Package the data into FHIR format
            list($url, $body) = $this->packageConditionData($conditionInfo);
            //$body = $this->getData($conditionID);

            // Send to CureSMA
            $this->module->emDebug("URL: " . $url);
            $this->module->emDebug("Header: " . json_encode($this->header));
            $this->module->emDebug("Body: " . $body);

            list($status, $error) = $this->sendPutRequest($url, $this->header, $body, $this->smaData);
            if (!$status) {
                $this->module->emError("Error sending data for project $this->pid, record $this->record_id, Condition " . json_encode($conditionInfo) . " instance $instance. Error $error");
            }
        }

        return $status;
    }


    private function getConditionData() {

        // Retrieve all diagnosis entries for this record
        try {
            $filter = "[dx_sent_to_curesma(1)] = ''";
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $rf->loadData($this->record_id, $this->event_id, $filter);
            $conditions = $rf->getAllInstances($this->record_id, $this->event_id);
            $this->module->emDebug("All instances of conditions: " . json_encode($conditions));
        } catch (Exception $ex) {
            $conditions = null;
            $this->module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
        }

        return $conditions;
    }

    private function packageConditionData($conditionInfo) {

        // Retrieve data for this condition
        $conditionID = $conditionInfo['dx_id'];
        $conditionCode = $conditionInfo['dx_code'];
        $conditionDescription = $conditionInfo['dx_description'];
        $conditionStartDate = $conditionInfo['dx_start_date'];
        $conditionResolvedDate = $conditionInfo['dx_resolved_date'];
        $conditionVerified = $conditionInfo['dx_code_verified'];

        // Add the id of this condition to the URL
        $url = $this->url . $conditionID;

        // Find the status of this condition: One of active, relapse, remission, resolved
        if (empty($conditionResolvedDate)) {
            $condStatus = 'active';
        } else {
            $condStatus = 'resolved';
        }

        // Set the verification status: One of unconfirmed, provisional, differential, confirmed, refuted, entered-in-error.
        // The options from STARR are: 1, 2, 5, 7, 9, 10, 11, <null>
        $verificationStatus = 'confirmed';

        // This is the person who is matched to this condition
        $subject = array(
            "reference" => "urn:Patient/$this->record_id"
        );

        // Package the category of resource. These are all diagnoses.
        $category = array(
            "coding" => array(
                array(
                    "system"    => "http://snomed.info/sct",
                    "code"      => "439401001",
                    "display"   => "Diagnosis"
                )
            )
        );

        // Package the ICD10 code of the condition.
        $code = array(
            "coding" => array(
                array(
                    "system"    => "http://hl7.org/fhir/sid/icd-10-cm",
                    "code"      => $conditionCode,
                    "display"   => $conditionDescription
                )
            )
        );

        // Package the complete Condition resource
        $diagnosis = array(
            "resourceType"          => "Condition",
            "id"                    => $conditionID,
            "clinicalStatus"        => $condStatus,
            "verificationStatus"    => $verificationStatus,
            "category"              => $category,
            "code"                  => $code,
            "subject"               => $subject,
            "onsetDateTime"         => $conditionStartDate,
            "abatementDateTime"     => $conditionResolvedDate
        );

        $body = json_encode($diagnosis);

        return array($url, $body);
    }

}
