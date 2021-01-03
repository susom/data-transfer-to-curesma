<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */

require_once "httpPutTrait.php";
require_once "RepeatingForms.php";

use REDCap;
use Exception;

/**
 * The class will find all Condition (diagnosis) codes for each registered patient and package up each
 * code and submit the resource to CureSMA. The diagnosis codes that are brought over from STARR are only
 * problem_list codes since that is what the other institutions are bringing over.  If additional codes are
 * desired, the query in the redcap_starr_data_query table will need to be updated.
 *
 * The amount of data being sent to CureSMA is a minimal set.  We are only packaging up the ICD10 code, whether
 * or not the condition is still active and the starting date of this condition.  Additional data may be
 * stored in the REDCap project but may not be sent to CureSMA.
 *
 * Class Condition
 * @package Stanford\DataTransferToCureSma
 */

class Condition {

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
        $this->instrument = $module->getProjectSetting('diagnosis-form');
        $this->event_id = $module->getProjectSetting('diagnosis-event');
        $this->event_name = REDCap::getEventNames(true, false, $this->event_id);

        // Retrieve the fields on the instrument
        $this->fields = REDCap::getFieldNames($this->instrument);

        // Determine the URL and header for the API call
        $this->url = $this->smaData['url'] . '/Condition/';
        $this->header = array("Content-Type:application/json");
    }

    /**
     * Ensure the Condition resource is selected to be used.  If not, don't send any data. Otherwise,
     * retrieve the new conditions to send, package them up into the current FHIR v3 format and send
     * to CureSMA.  If the send was successful, mark the condition as having been sent and save the record.
     *
     * @return bool|mixed - true when data was successfully sent to CureSMA
     */
    public function sendConditionData() {
        global $module;

        // If an instrument is not specified for Conditions (Diagnosis), skip processing.
        if (is_null($this->instrument) || empty($this->instrument)) {
            return true;
        }

        // Retrieve patient data for this record
        $conditions = $this->getConditionData();
        //$module->emDebug("This is the condition data: " . json_encode($conditions));

        $sentInstances = array();
        foreach($conditions[$this->record_id][$this->event_id] as $instance => $conditionInfo) {

            // Package the data into FHIR format
            $dx_id =  'dx-' . $this->record_id . '-' . $instance;
            list($url, $body) = $this->packageConditionData($conditionInfo, $dx_id);

            // Send to CureSMA
            //$module->emDebug("URL: " . $url);
            //$module->emDebug("Header: " . json_encode($this->header));
            //$module->emDebug("Body: " . $body);

            list($status, $error) = $this->sendPutRequest($url, $this->header, $body, $this->smaData);
            if (!$status) {
                $module->emError("Error sending data for project $this->pid, record $this->record_id, Condition " . json_encode($conditionInfo) . " instance $instance. Error $error");
            } else {

                // Set the checkbox to say the data was sent to CureSMA
                $this->saveConditionStatus($instance, $conditionInfo, $dx_id);
            }
        }

        return $status;
    }

    /**
     * Retrieve the condition codes that have not been sent to CureSMA yet.
     *
     * @return array|bool|null
     */
    private function getConditionData() {
        global $module;

        // Retrieve all diagnosis entries for this record
        try {
            $filter = "[" . $this->event_name . "][dx_sent_to_curesma(1)] = '0'";
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $rf->loadData($this->record_id, $this->event_id, $filter);
            $conditions = $rf->getAllInstances($this->record_id, $this->event_id);
        } catch (Exception $ex) {
            $conditions = null;
            $module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
        }

        return $conditions;
    }

    /**
     * Save the fact that this condition was already submitted to CureSMA.
     *
     * @param $instance_id - Instance of the repeatable form that holds this condition description
     * @param $conditionInfo - Condition description data
     */
    private function saveConditionStatus($instance_id, $conditionInfo, $dx_id) {
        global $module;

        // Retrieve all diagnosis entries for this record
        try {
            $conditionInfo['dx_sent_to_curesma'] = array('1' => '1');
            $conditionInfo['dx_date_data_curesma'] = date('Y-m-d H:i:s');
            $conditionInfo['dx_id'] = $dx_id;
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $status = $rf->saveInstance($this->record_id, $conditionInfo, $instance_id, $this->event_id);
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
     * This routine will package the condition to send to CureSMA.  If additional information is added
     * to the message, it shoud be added here.
     *
     * @param $conditionInfo - Information about this condition
     * @return array - URL used to send this resource and the body (package) of the message
     */
    private function packageConditionData($conditionInfo, $dx_id) {

        // Retrieve data for this condition
        $conditionID = $dx_id;
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
            "reference" => "urn:Patient/$this->study_id"
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

        $body = json_encode($diagnosis, JSON_UNESCAPED_SLASHES);

        return array($url, $body);
    }

}
