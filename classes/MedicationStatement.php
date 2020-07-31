<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */

require_once "httpPutTrait.php";
require_once "RepeatingForms.php";

use REDCap;
use Exception;


/**
 * Class MedicationStatement
 * @package Stanford\DataTransferToCureSma
 */
class MedicationStatement {

    use httpPutTrait;

    private $pid, $record_id, $event_id, $instrument, $fhir = array(), $smaData, $header, $study_id;
    private $idSystem, $idUse, $fields, $rf;

    public function __construct($pid, $record_id, $study_id, $smaData, $fhirValues) {
        global $module;

        $this->pid              = $pid;
        $this->record_id        = $record_id;
        $this->smaData          = $smaData;
        $this->fhir             = $fhirValues;
        $this->study_id         = $study_id;

        // These are the patient specific parameters for FHIR format
        $this->instrument = $module->getProjectSetting('medication-form');
        $this->event_id = $module->getProjectSetting('medication-event');

        // Retrieve the fields on the instrument
        $this->fields = REDCap::getFieldNames($this->instrument);

        // Determine the URL and header for the API call
        $this->url = $this->smaData['url'] . '/MedicationStatement/';
        $this->header = array("Content-Type:application/json");

    }

    public function sendMedicationStatementData() {
        global $module;

        // If an instrument is not specified for Conditions (Diagnosis), skip processing.
        if (is_null($this->instrument) || empty($this->instrument)) {
            return true;
        }

        // Retrieve patient data for this record
        $medications = $this->getMedicationStatementData();
        $module->emDebug("This is the medication data: " . json_encode($medications));

        $sentInstances = array();
        foreach($medications[$this->record_id] as $instance => $medicationInfo) {

            // Package the data into FHIR format
            list($url, $body) = $this->packageMedicationStatementData($medicationInfo);

            // Send to CureSMA
            $module->emDebug("Medication URL: " . $url);
            $module->emDebug("Medication Header: " . json_encode($this->header));
            $module->emDebug("Medication Body: " . $body);

            list($status, $error) = $this->sendPutRequest($url, $this->header, $body, $this->smaData);
            if (!$status) {
                $module->emError("Error sending data for project $this->pid, record $this->record_id, Medication " . json_encode($medicationInfo) . " instance $instance. Error $error");
            } else {

                // Set the checkbox to say the data was sent to CureSMA
                $this->saveMedicationStatementStatus($instance, $medicationInfo);
            }
        }

        return $status;
    }


    private function getMedicationStatementData() {
        global $module;

        // Retrieve all medication entries for this record that have not been sent and has a medication reference
        try {
            $filter = "[med_sent_to_curesma(1)] = '0' and [med_list_id] != ''";
            $this->rf = new RepeatingForms($this->pid, $this->instrument);
            $this->rf->loadData($this->record_id, $this->event_id, $filter);
            $medications = $this->rf->getAllInstances($this->record_id, $this->event_id);
            $module->emDebug("Medication list to send: " . json_encode($medications));

        } catch (Exception $ex) {
            $medications = null;
            $module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
        }

        return $medications;
    }

    private function saveMedicationStatementStatus($instance_id, $medicationInfo) {
        global $module;

        // Retrieve all diagnosis entries for this record
        try {
            $medicationInfo['med_sent_to_curesma'] = array('1' => '1');
            $medicationInfo['med_date_data_curesma'] = date('Y-m-d H:i:s');
            $status = $this->rf->saveInstance($this->record_id, $medicationInfo, $instance_id, $this->event_id);
            if (!$status) {
                $module->emError("Could not save data for instance $instance_id, project $this->pid, instrument $this->instrument");
            } else {
                $module->emDebug("Sucessfully saved data for instance $instance_id, instrument $this->instrument, project $this->pid");
            }
        } catch (Exception $ex) {
            $module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
        }
    }

    private function packageMedicationStatementData($medicationInfo) {

        // Retrieve data for this medication
        $medicationID = $medicationInfo['med_id'];

        // Add the id of this condition to the URL
        $url = $this->url . $medicationID;

        // Find the status of this medication: One of active, completed, entered-in-error, intended
        if (empty($medicationInfo['med_end_date'])) {
            $medicationStatus = 'active';
        } else {
            $medicationStatus = 'completed';
        }

        // Set the verification status: active, completed, entered-in-error, intended
        // The options from STARR are: 1, 2, 5, 7, 9, 10, 11, <null>
        $verificationStatus = 'confirmed';

        // This is the person who is matched to this condition
        $subject = array(
            "reference" => "urn:Patient/$this->study_id"
        );

        // reference to Medication resource
        $medRef = array(
            "reference"      => "urn:Medication/" . $medicationInfo['med_list_id'],
        );

        // Put in some text descriptions
        $text = array(
            "status"    => "generated",
            "div"       => "<div xmlns='http:www.w3.org/1999/xhtml'><p>"
                            . $medicationInfo['med_stanford_description'] . "</p></div>"
        );

        // Medication take: options 'y', 'n', 'unk', 'na'
        if ($medicationInfo['med_administered'] == '1') {
            $taken = 'y';
        } else {
            $taken = 'unk';
        }

        // Package the complete Condition resource
        $medication = array(
            "resourceType"          => "MedicationStatement",
            "id"                    => $medicationID,
            "text"                  => $text,
            "status"                => $medicationStatus,
            "medicationReference"   => $medRef,
            "effectiveDateTime"     => $medicationInfo['med_start_date'],
            "dateAsserted"          => $medicationInfo['med_order_date'],
            "subject"               => $subject,
            "taken"                 => $taken
            //"dosage"                => $dosage
        );

        $body = json_encode($medication, JSON_UNESCAPED_SLASHES);

        return array($url, $body);
    }

}
