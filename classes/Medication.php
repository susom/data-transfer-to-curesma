<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */

require_once "httpPutTrait.php";
require_once "RepeatingForms.php";

use REDCap;
use Exception;
use Project;

/**
 * The Medication resource operates differently than the rest of the FHIR resources.  Each medication
 * needs to be identified and sent separately from the patient.  Then, when the patient is prescribed
 * the medication, the MedicationStatement refers to this Medication resource in the patient chart.
 *
 * In order to accommodate this difference of keeping a separate list of medications, another REDCap
 * project is used to store the medication list. This Medication List project is specified in the
 * System Settings in the EM Configuration file.
 *
 * REDCap to STARR link will bring over all the medications for a patient. Before sending the medication
 * prescription to CureSMA, we check the Medication List project (pid=20187) to see if this medication
 * has already been submitted to CureSMA. If not, it will send the Medication resource to CureSMA and then
 * put the medication ID (which references the Medication resource) in the patient record so a Medication
 * Statement resource can be sent.
 *
 * If the Medication resource has already been sent to CureSMA, we just update the patient record to
 * enter the Medication list ID.
 *
 * Class Medication
 * @package Stanford\DataTransferToCureSma
 */

class Medication {

    use httpPutTrait;

    private $pid, $record_id, $event_id, $instrument, $fhir = array(), $smaData, $header;
    private $idSystem, $idUse, $fields, $study_id, $patient_medications;
    private $next_record_num, $med_list_pid, $med_list_event_id, $med_list_fields;

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

        // Find the Medication List project
        // We need to find the fields that are in the project and the event so we can save data to it
        $this->med_list_pid = $module->getSystemSetting('medication-project');
        try {
            $med_list_Proj = new Project($this->med_list_pid, true);
            $this->med_list_fields = array_keys($med_list_Proj->metadata);
            $form_complete = array_pop($this->med_list_fields);
            $this->med_list_event_id = array_keys($med_list_Proj->eventInfo)[0];
        } catch (Exception $ex) {
            $module->emError("Could not retrieve the data dictionary for project $this->med_list_pid");
        }

        // Determine the URL and header for the API call
        $this->url = $this->smaData['url'] . '/Medication/';
        $this->header = array("Content-Type:application/json");
    }

    public function sendMedicationData() {
        global $module;

        // If an instrument is not specified for Conditions (Diagnosis), skip processing.
        if (is_null($this->instrument) || empty($this->instrument)) {
            return true;
        }

        // Retrieve patient data for this record
        $medications = $this->getMedicationData();

        $sentInstances = array();
        foreach($medications as $record_id => $medicationInfo) {

            // Package the data into FHIR format
            list($url, $body) = $this->packageMedicationData($record_id, $medicationInfo);

            // Send to CureSMA
            //$module->emDebug("Medication URL: " . $url);
            //$module->emDebug("Medication Header: " . json_encode($this->header));
            $module->emDebug("Medication Body: " . $body);

            list($status, $error) = $this->sendPutRequest($url, $this->header, $body, $this->smaData);
            if (!$status) {
                $module->emError("Error sending data for project $this->pid, record $this->record_id, Medication " . json_encode($medicationInfo) . ". Error $error");
            } else {

                // Set the checkbox to say the data was sent to CureSMA
                $module->emDebug("Successfully sent medication ID for project $this->pid, record $this->record_id, Medication " . json_encode($medicationInfo));
                $this->saveMedicationStatus($record_id, $medicationInfo);
            }
        }

        // Now that all the Medication resources are sent, we want to update each Medication record
        // and put the Medication resource ID in the record so the patient records can reference it
        $this->updatePatientRecords();

        return $status;
    }

    private function updatePatientRecords() {
        global $module;

        // Retrieve all the snomed ids that have been submitted.  We need to retrieve the list
        // again because we just added some
        $med_record = REDCap::getData($this->med_list_pid, 'json');
        $med_list = json_decode($med_record, true);

        // Reformat so that the snomed IDs are the keys
        $already_submitted_meds = array();
        foreach($med_list as $each_med) {
            $already_submitted_meds[$each_med['med_snomed_ct_code']] = $each_med;
        }

        // We've already retrieved the patient medication that we need to update with the
        // Medication resource ID and we still have the class reference so just update each instance
        foreach ($this->patient_medications[$this->record_id] as $instance_id => $medInfo) {
            $snomed_id = $medInfo['med_snomed_ct_code'];
            $med_list_data = $already_submitted_meds[$snomed_id];
            $medInfo['med_list_id'] = $med_list_data['med_list_id'];
            $module->emDebug("Data to save: " . json_encode($medInfo));
            try {
                $rf = new RepeatingForms($this->pid, $this->instrument);
                $status = $rf->saveInstance($this->record_id, $medInfo, $instance_id, $this->event_id);
                $module->emDebug("Status from save: $status");
                if (!$status) {
                    $module->emError("Unable to save med_list_id for record $this->record_id, instance $instance_id with status $status");
                }
            } catch (Exception $ex) {
                $module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument to save instance $instance_id");

            }

        }
    }

    private function getMedicationData() {
        global $module;

        try {

            // Retrieve all the medication from the patient chart for this record which have not yet been submitted
            //$filter = "[med_list_id] = '' and [med_snomed_ct_code] <> '' and [med_sent_to_curesma(1)] = '0'";
            $filter = "[med_list_id] = '' and [med_sent_to_curesma(1)] = '0'";
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $rf->loadData($this->record_id, $this->event_id, $filter);
            $this->patient_medications = $rf->getAllInstances($this->record_id, $this->event_id);

            // Retrieve all the snomed ids that have been submitted already
            $med_record = REDCap::getData($this->med_list_pid, 'json');
            $med_list = json_decode($med_record, true);

            // Reformat so that the snomed IDs are the keys
            $already_submitted_meds = array();
            $record_nums = array();
            foreach($med_list as $each_med) {
                $already_submitted_meds[$each_med['med_snomed_ct_code']] = $each_med;
                $record_nums[] = $each_med['record_id'];
            }

            // If there are no records in the project, start with record 1
            if (empty($record_nums)) {
                $this->next_record_num = 1;
            } else {
                $this->next_record_num = max($record_nums) + 1;
            }

            // The medication data we are going to send are only medications that have not previously been sent
            // See which of these medications have not been sent yet.
            $snomed_ids_to_be_added = array();
            foreach ($this->patient_medications[$this->record_id] as $instance_id => $medInfo) {

                // See if this medication has already been sent from the Medication List project
                if (empty($already_submitted_meds[$medInfo['med_snomed_ct_code']])) {

                    // This Medication resource has not been submitted yet, add to the list to submit
                    // We only want to add it once so keep track of the snomed ids and make sure we don't duplicate
                    if (!in_array($medInfo['med_snomed_ct_code'], $snomed_ids_to_be_added)) {
                        $next_record = $this->next_record_num++;
                        $medInfo['med_list_id'] = 'medlist-'. $next_record;

                        // Only save the data for the fields that are on the medication list form
                        $data_to_save = array_intersect_key($medInfo, array_flip($this->med_list_fields));
                        $snomed_ids_to_be_added[$next_record] = $data_to_save;
                        $module->emDebug("snomeds to be added: " . json_encode($data_to_save));
                    }
                }
            }

        } catch (Exception $ex) {
            $module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
        }

        return $snomed_ids_to_be_added;
    }


    private function saveMedicationStatus($record_id, $medicationInfo) {
        global $module;

        // Now that this resource was sent to CureSMA, save it in the Medication List project
        $medicationInfo['med_sent_to_curesma'] = array('1' => '1');
        $medicationInfo['med_date_sent_to_curesma'] = date('Y-m-d H:i:s');
        $saveData[$record_id][$this->med_list_event_id] = array_merge(array('record_id' => "$record_id"), $medicationInfo);
        $return = REDCap::saveData($this->med_list_pid, 'array', $saveData);
        $module->emDebug("Return from save medication: " . json_encode($return));
        if (!empty($return['errors'])) {
            $module->emError("Could not save data for record $record_id, project $this->med_list_pid");
        } else {
            $module->emDebug("Successfully added new medication for record $record_id, project $this->med_list_pid");
        }
    }

    private function packageMedicationData($record_id, $medicationInfo) {

        // Retrieve data for this medication. We need to create a new record with a medication id
        $medicationID = $medicationInfo['med_list_id'];
        $ndc_code = $medicationInfo["med_ndc_code"];
        $stanford_code = $medicationInfo["med_stanford_med_id"];
        $snomed_code = $medicationInfo["med_snomed_ct_code"];

        if (empty($ndc_code)) {
            $med_code = $stanford_code;
            $system = "https://www.stanford.edu/";
        } else {
            $med_code = $ndc_code;
            $system = "http://hl7.org/fhir/sid/ndc";
        }

        // Add the id of this condition to the URL
        $url = $this->url . $medicationID;

        // Package the coding of resource. These the NDC codes.
        $coding = array(
            "coding" => array(
                array(
                    "system"    => $system,
                    "code"      => $med_code,
                    "display"   => $medicationInfo["med_stanford_description"]
                )
            )
        );

        // Package the primary ingredient
        $ingredient = array(
                        array(
                            "itemCodeableConcept" => array(
                                "coding" => array(
                                    array(
                                        "system"    => "http://snomed.info/sct",
                                        "code"      => $snomed_code,
                                        "display"   => $medicationInfo["med_snomed_ct_description"]
                                    )
                                )
                            )
                        )
                    );

        // Package the complete Condition resource
        $medication = array(
            "resourceType"          => "Medication",
            "id"                    => $medicationID,
            "code"                  => $coding
        );
        if (!empty($snomed_code)) {
            $medication["ingredient"] = $ingredient;
        }

        // Check to see if we know if this a brand name medication
        if ($medicationInfo["med_brand_name"] == 1) {
            $medication["isBrand"] = "true";
        }

        // Check to see if we know if this is an OTC med or prescribed
        if ($medicationInfo["med_otc"] == 1) {
            $medication["isBrand"] = "true";
        }

        $body = json_encode($medication, JSON_UNESCAPED_SLASHES);

        return array($url, $body);
    }

}
