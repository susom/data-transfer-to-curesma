<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */

require_once "httpPutTrait.php";
require_once "RepeatingForms.php";

use Exception;

/**
 * The class will find all Allergy entries for each registered patient and package up each
 * entry and submit the resource to CureSMA. The allergy description is used since I'm not
 * sure what the Allergy code is.  I think it might be Stanford specific and it is not
 * required per the FHIR specification.
 *
 * The amount of data being sent to CureSMA is a minimal set.
 *
 * Class Allergies
 * @package Stanford\DataTransferToCureSma
 */

class Allergies {

    use httpPutTrait;

    private $pid, $record_id, $event_id, $instrument, $fhir = array(), $smaData, $header;
    private $idSystem, $idUse, $study_id;
    private $module;

    public function __construct($module, $pid, $record_id, $study_id, $smaData, $fhirValues) {

        $this->module           = $module;
        $this->pid              = $pid;
        $this->record_id        = $record_id;
        $this->smaData          = $smaData;
        $this->fhir             = $fhirValues;
        $this->study_id         = $study_id;

        // These are the patient specific parameters for FHIR format
        $this->instrument = $this->module->getProjectSetting('allergy-form', $this->pid);
        $this->event_id = $this->module->getProjectSetting('allergy-event', $this->pid);

        // Determine the URL and header for the API call
        $this->url = $this->smaData['url'] . '/AllergyIntolerance/';
        $this->header = array("Content-Type:application/json");
    }

    /**
     * Ensure the Allergy resource is selected to be used.  If not, don't send any data. Otherwise,
     * retrieve the new allergies to send, package them up into the current FHIR v3 format and send
     * to CureSMA.  If the send was successful, mark the allergy as having been sent and save the record.
     *
     * @return bool|mixed - true when data was successfully sent to CureSMA
     */
    public function sendAllergyData() {

        $status = true;
        // If an instrument is not specified for Allergies, skip processing.
        if (is_null($this->instrument) || empty($this->instrument)) {
            return true;
        }
        $this->module->emDebug("This is the instrument: " . $this->instrument);

        // Retrieve patient data for this record
        $allergies = $this->getAllergyData();

        foreach($allergies[$this->record_id][$this->event_id] as $instance => $allergyInfo) {

            // Package the data into FHIR format
            $allergy_id =  'all-' . $this->record_id . '-' . $instance;
            list($url, $body) = $this->packageAllergyData($allergyInfo, $allergy_id);

            // Send to CureSMA
            //$this->module->emDebug("URL: " . $url);
            //$this->module->emDebug("Header: " . json_encode($this->header));
            //$this->module->emDebug("Body: " . $body);

            list($status, $error) = $this->sendPutRequest($url, $this->header, $body, $this->smaData);
            if (!$status) {
                $this->module->emError("Error sending data for project $this->pid, record $this->record_id, Allergy " . json_encode($allergyInfo) . " instance $instance. Error $error");
            } else {

                // Set the checkbox to say the data was sent to CureSMA
                $this->saveAllergyStatus($instance, $allergyInfo, $allergy_id);
            }
        }

        return $status;
    }

    /**
     * Retrieve the allergy codes that have not been sent to CureSMA yet.
     *
     * @return array|bool|null
     */
    private function getAllergyData() {

        // Retrieve all diagnosis entries for this record
        try {
            $filter = "[all_sent_to_curesma(1)] = '0'";
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $rf->loadData($this->record_id, $this->event_id, $filter);
            $allergies = $rf->getAllInstances($this->record_id, $this->event_id);
        } catch (Exception $ex) {
            $allergies = null;
            $this->module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
        }

        return $allergies;
    }

    /**
     * Save the fact that this allergy was submitted to CureSMA.
     *
     * @param $instance_id - Instance of the repeatable form that holds this allergy description
     * @param $allergyInfo - Allergy description data
     */
    private function saveAllergyStatus($instance_id, $allergyInfo, $allergy_id) {

        // Retrieve all allergy entries for this record
        try {
            $allergyInfo['all_sent_to_curesma'] = array('1' => '1');
            $allergyInfo['all_date_data_curesma'] = date('Y-m-d H:i:s');
            $allergyInfo['all_id'] = $allergy_id;
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $status = $rf->saveInstance($this->record_id, $allergyInfo, $instance_id, $this->event_id);
            if (!$status) {
                $this->module->emError("Could not save data for allergy instance $instance_id, project $this->pid, instrument $this->instrument");
            } else {
                $this->module->emDebug("Sucessfully saved data for allergy instance $instance_id, instrument $this->instrument, project $this->pid");
            }
        } catch (Exception $ex) {
            $this->module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
        }
    }

    /**
     * This routine will package the allergies to send to CureSMA.  If additional information is added
     * to the message, it should be added here.
     *
     * @param allergyInfo - Information about this allergy
     * @param allergy_id - the specific identifer for this allergen for this patient
     * @return array - URL used to send this resource and the body (package) of the message
     */
    private function packageAllergyData($allergyInfo, $allergy_id) {

        // Retrieve data for this condition
        $allergyID = $allergy_id;
        $allergyDescription = $allergyInfo['all_description'];
        $allergyStartDate = $allergyInfo['all_date_noted'];
        $allergyStatus = $allergyInfo['all_status'];
        $allergyReaction = $allergyInfo['all_reaction'];

        // Add what they are allergic to
        $allergen = array("coding" => array(
                            array("display" => $allergyDescription)
                        )
        );

        // Add what the allergic reaction is.  When I bring over the data, I insert a semi-colon to
        // separate the reactions so we should split them and submit each as a
        // different code
        $coding = array();
        $coding["display"] = $allergyReaction;
        $manifestation['coding'][] = $coding;
        $reaction = array("manifestation" => array($manifestation));

        // Add the id of this condition to the URL
        $url = $this->url . $allergyID;

        // Find the status of this allergy: either Active or Deleted
        if ($allergyStatus == 'Active') {
            $allergyStatus = 'active';
        } else {
            $allergyStatus = 'inactive';
        }

        // Set the verification status: One of unconfirmed, confirmed, refuted, entered-in-error.
        $verificationStatus = 'confirmed';

        // This is the person who is matched to this condition
        $subject = array(
            "reference" => "urn:Patient/$this->study_id"
        );

        // Package the complete Condition resource
        $allergy = array(
            "resourceType"          => "AllergyIntolerance",
            "id"                    => $allergyID,
            "clinicalStatus"        => $allergyStatus,
            "verificationStatus"    => $verificationStatus,
            "type"                  => 'allergy',
            "code"                  => $allergen,
            "reaction"              => array($reaction),
            "patient"               => $subject,
            "onsetDateTime"         => $allergyStartDate
        );

        $body = json_encode($allergy, JSON_UNESCAPED_SLASHES);

        return array($url, $body);
    }

}
