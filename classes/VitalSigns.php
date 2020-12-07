<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */

require_once "httpPutTrait.php";
require_once "RepeatingForms.php";

use REDCap;
use Exception;

class VitalSigns {

    use httpPutTrait;

    private $pid, $record_id, $study_id, $event_id, $instrument, $fhir = array(), $smaData, $header;
    private $idSystem, $idUse, $fields;

    private $loinc_url = 'http ://loinc.org';
    private $vitalSigns = array(
        "weight"    => array("field_name"=>"enc_weight", "loinc_code"=>"29463-7", "unit"=>"lbs", "code"=>"[lb_av]", "display"=>"Body Weight"),
        "rr"        => array("field_name"=>"enc_respiratory_rate", "loinc_code"=>"9279-1", "unit"=>"/min", "code"=>"/min", "display"=>"Respiratory Rate"),
        "pulse"     => array("field_name"=>"enc_pulse", "loinc_code"=>"8867-4", "unit"=>"/min", "code"=>"/min", "display"=>"Pulse"),
        "temp"      => array("field_name"=>"enc_temperature", "loinc_code"=>"8310-5", "unit"=>"Cel or [degF]", "code"=>"[degF]", "display"=>"Body Temperature"),
        "height"    => array("field_name"=>"enc_height", "loinc_code"=>"8302-2", "unit"=>"inches","code"=>"[in_i]", "display"=>"Height"),
        "o2"        => array("field_name"=>"enc_o2", "loinc_code"=>"59408-5", "unit"=>"%", "code"=>"%", "display"=>"Oxygen Saturation"),
        "bmi"       => array("field_name"=>"enc_bmi", "loinc_code"=>"39156-5", "unit"=>"kg/m2", "code"=>"kg/m2", "display"=>"BMI"),
        "bps"       => array("field_name"=>"enc_bp_systolic", "loinc_code"=>"8480-6", "unit"=>"mm[Hg]", "code"=>"mm[Hg]", "display"=>"BP Systolic"),
        "bpd"       => array("field_name"=>"enc_bp_diastolic", "loinc_code"=>"8462-4", "unit"=>"mm[Hg]", "code"=>"mm[Hg]", "display"=>"BP Diastolic")
    );

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

        // Determine the URL and header for the API call
        $this->url = $this->smaData['url'] . '/Observation/';
        $this->header = array("Content-Type:application/json");
    }

    public function sendVitalSignData() {

        global $module;
        $status = true;

        // If an instrument is not specified for Observations (Labs), skip processing.
        if (is_null($this->instrument) || empty($this->instrument)) {
            return $status;
        }

        // Retrieve patient data for all instances for this patient
        $vitalSignData = $this->getVitalSignData();

        // Loop over each patient
        foreach ($vitalSignData[$this->record_id] as $event_id => $vitalSignInfo) {

            // Loop over each instance for this patient record
            foreach($vitalSignInfo as $instance_id => $vitalInfo) {

                // Package the Vital Sign data into FHIR format
                $status = $this->packageAndSendVitalSignData($this->record_id, $event_id, $instance_id, $vitalInfo);
                if ($status) {

                    // If the resource was successfully sent, update the database to show the data was sent
                    $status = $this->saveVitalSignStatus($instance_id, $vitalSignInfo);
                }
            }
        }

        return $status;
    }

    private function getVitalSignData() {
        global $module;

        // Retrieve all diagnosis entries for this record
        try {

            $filter = '[vitals_sent_to_curesma(1)] = "0"';
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $rf->loadData($this->record_id, $this->event_id, $filter);
            $vitals = $rf->getAllInstances($this->record_id, $this->event_id);
            //$module->emDebug("Vitals: " . json_encode($vitals));

        } catch (Exception $ex) {
            $vitals = null;
            $module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument for Vital Signs");
        }

        return $vitals;
    }


    private function packageAndSendVitalSignData($record_id, $event_id, $instance_id, $vitals)
    {
        global $module;
        $status = false;

        // All the category objects are the same - generic vital signs object
        $category = array(
            array("coding" =>
                array(
                    array(
                        "system" => "http://hl7.org/fhir/observation-category",
                        "code"  => "vital-signs",
                        "display" => "Vital Signs"
                    )
                )
            )
        );

        // Loop over each vital sign and see if there is data to send
        foreach ($this->vitalSigns as $vitalType => $vitalInfo) {

            // Retrieve the data for this vital.  If it is empty skip and go to the next vital.
            $this_vital = $vitals[$vitalInfo['field_name']];
            if (!empty($this_vital)) {

                // We have to convert some of the vitals before sending them
                $vitalValue =  $this->convertVital($vitalType, $this_vital);

                // The encounter for this person has the pattern of enc-<record_id>-<instance>
                // We will change this vital to be called vital-<record_id>-<instance>-<vital_name>
                $encId = $vitals['enc_id'];
                $vitalId = str_replace('enc', 'vital', $encId) . '-' . $vitalType;

                // Add the id to the URL
                $url = $this->url . $vitalId;
                $unitUrl = "http://unitsofmeasure.org";

                // Fill in the loinc info for this lab
                $codeCoding = array(
                    "coding" => array(
                        array(
                            "system"    => "http://loinc.org",
                            "code"      => $vitalInfo['loinc_code'],
                            "display"   => $vitalInfo['display']
                        )
                    )
                );


                // Fill in the subject that this lab belongs to
                $subject = array(
                    "reference" => "urn:Patient/$this->study_id"
                );

                // Fill in the encounter where these vitals were taken
                $context = array(
                    "reference" => "urn:Encounter/$encId"
                );

                // Fill in the lab result values
                $valueQuantity = array(
                    "value"     => $vitalValue,
                    "unit"      => $vitalInfo['unit'],
                    "system"    => $unitUrl,
                    "code"      => $vitalInfo['code']
                );

                $vitalsPkg = array(
                    "resourceType"      => "Observation",
                    "id"                => $vitalId,
                    "status"            => 'final',
                    "category"          => $category,
                    "code"              => $codeCoding,
                    "subject"           => $subject,
                    "context"           => $context,
                    "effectiveDateTime" => $vitals['enc_start_datetime'],
                    "valueQuantity"     => $valueQuantity
                );

                $body = json_encode($vitalsPkg, JSON_UNESCAPED_SLASHES);
                //$module->emDebug("Body of message to send: " . $body);

                //  Send the request for this vital
                list($status, $error) = $this->sendPutRequest($url, $this->header, $body, $this->smaData);
                if (!$status) {
                    $module->emError("Error sending Vital Sign data for project $this->pid, record $this->record_id, Vital Sign " . json_encode($vitals) . " instance $instance_id. Error $error");
                } else {
                    $status = true;
                }
            }
        }

        // If even one vital was send, set status in record that vitals were sent
        return $status;
    }

    private function convertVital($vitalType, $value) {
        global $module;

        if ($vitalType == "weight") {

            // Weight is coming in ounces and we want it in kg
            $convertedValue = round($value/35.274, 2);
            //$module->emDebug("In weight: initial value: $value, converted value $convertedValue");

        } else if ($vitalType == "height") {

            // Height is stored as an ugly text string of 5' 7"
            $feetLoc = strpos($value,"'");
            $inchLoc = strpos($value,'"');
            $feet = substr($value, 0, $feetLoc);
            $inch = substr($value, $feetLoc+2, ($inchLoc-$feetLoc-2));
            $convertedValue = round($feet*12 + $inch, 2);
            //$module->emDebug("Converted Height value: " . $convertedValue);

        } else {
            $convertedValue = $value;
        }

        return $convertedValue;
    }

    private function saveVitalSignStatus($instance_id, $vitalSignInfo) {
        global $module;

        $status = false;
        // Save the fact that we sent this vital sign to CureSMA
        try {
            $encountersInfo['vitals_sent_to_curesma'] = array('1' => '1');
            $encountersInfo['vitals_date_curesma'] = date('Y-m-d H:i:s');
            $rf = new RepeatingForms($this->pid, $this->instrument);
            $status = $rf->saveInstance($this->record_id, $encountersInfo, $instance_id, $this->event_id);
            if (!$status) {
                $module->emError("Could not save Vital data for instance $instance_id, project $this->pid, instrument $this->instrument");
                $status = false;
            } else {
                $module->emDebug("Sucessfully saved Vital data for instance $instance_id, instrument $this->instrument, project $this->pid");
                $status = true;
            }
        } catch (Exception $ex) {
            $module->emError("Exception when instantiating the Repeating Forms class for project $this->pid instrument $this->instrument");
            $status = false;
        }

        return $status;
    }

}
