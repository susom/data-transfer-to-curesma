<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */

require_once "httpPutTrait.php";

use REDCap;

class Patient {

    use httpPutTrait;

    private $pid, $record_id, $event_id, $instrument, $fhir = array(), $smaData, $header, $study_id;
    private $idSystem, $idUse, $fields, $raceInfo, $ethnicityInfo;
    private $module;

    public function __construct($module, $pid, $record_id, $study_id, $smaData, $fhirValues) {

        $this->module           = $module;
        $this->pid              = $pid;
        $this->record_id        = $record_id;
        $this->smaData          = $smaData;
        $this->fhir             = $fhirValues;
        $this->study_id         = $study_id;

        // Retrieve the instrument that holds the demographics data
        $this->instrument = $this->module->getProjectSetting('demographic-form', $this->pid);
        $this->event_id = $this->module->getProjectSetting('demographic-event', $this->pid);

        // Retrieve the fields on this instrument
        $this->fields = $this->module->getFieldNames($this->instrument, $this->pid);
        //$this->fields = REDCap::getFieldNames($this->instrument);
        $this->module->emDebug("Field names: " . json_encode($this->fields));

        // Determine the URL and header for the API call
        $this->url = $this->smaData['url'] . '/Patient/' . $this->study_id;
        $this->header = array("Content-Type:application/json");

        // These are the patient specific parameters for FHIR format
        $this->idSystem = "urn:ietf:rfc:3986";
        $this->idUse = "usual";

        // These is the race info for the standards we are using
        $this->raceInfo = array(
            "url" => "http://hl7.org/fhir/us/core/ValueSet/omb-race-category",
            "coding" => array(
                    // STARR coding = array(code, display name)
                    "Native American"   => array(
                                                "code"      => "1002-5",
                                                "display"   => "American Indian or Alaska Native",
                                                "system"    => "urn:oid:2.16.840.1.113883.6.238"
                                            ),
                    "Asian"             => array(
                                                "code"      =>  "2028-9",
                                                "display"   =>  "Asian",
                                                "system"    => "urn:oid:2.16.840.1.113883.6.238"
                                            ),
                    "Black"             => array(
                                                "code"      => "2054-5",
                                                "display"   => "Black or African American",
                                                "system"    => "urn:oid:2.16.840.1.113883.6.238"
                                            ),
                    "Pacific Islander"  => array(
                                                "code"      => "2076-8",
                                                "display"   => "Native Hawaiian or Other Pacific Islander",
                                                "system"    => "urn:oid:2.16.840.1.113883.6.238"
                                            ),
                    "White"             => array(
                                                "code"      => "2106-3",
                                                "display"   => "White",
                                                "system"    => "urn:oid:2.16.840.1.113883.6.238"
                                            ),
                    // This code is still supported in HL7 but deprecated but STARR still uses it.
                    "Other"             => array(
                                                "code"      => "2131-1",
                                                "display"   => "Other Race",
                                                "system" => "urn:oid:2.16.840.1.113883.6.238"
                                            ),
                    "Unknown"           => array(
                                                "code"      => "UNK",
                                                "display"   => "Unknown",
                                                "system" => "http://terminology.hl7.org/CodeSystem/v3-NullFlavor"
                                            )
            )
        );

        // These is the ethnicity info for the standards we are using
        $this->ethnicityInfo = array(
            "url" => "http://hl7.org/fhir/us/core/ValueSet/omb-ethnicity-category",
            "coding" => array(
                "Non-Hispanic"      => array
                                        (
                                            "code"      => "2186-5",
                                            "display"   => "Non Hispanic or Latino",
                                            "system"    => "urn:oid:2.16.840.1.113883.6.238"
                                        ),
                "Hispanic/Latino"   => array
                                        (
                                            "code"      => "2135-2",
                                            "display"   => "Hispanic or Latino",
                                            "system"    => "urn:oid:2.16.840.1.113883.6.238"
                                        ),
                // There is no code for unknown ethnicity
                "Unknown"           => array
                                        (
                                            "code"      => "UNK",
                                            "display"   => "Unknown",
                                            "system" => "urn:oid:2.16.840.1.113883.6.238"
                                        )
            )
        );

    }

    public function sendPatientData() {

        // If a demographics form is not specified, skip processing of patients
        if (is_null($this->instrument) || empty($this->instrument)) {
            return true;
        }

        // Retrieve patient data for this record
        $person = $this->getPatientData();
        if (empty($person)) {
            return true;
        }


        // Package the data into FHIR format
        $body = $this->packagePatientData($person);

        // Send to CureSMA
        //$this->module->emDebug("URL: " . $this->url);
        //$this->module->emDebug("Header: " . json_encode($this->header));
        //$this->module->emDebug("Body: " . $body);

        list($status, $error) = $this->sendPutRequest($this->url, $this->header, $body, $this->smaData);
        if (!$status) {
            $this->module->emError("Error sending data for project $this->pid, record $this->record_id. Error $error");
        } else {
            $this->savePatientStatus();
        }

        return $status;
    }

    private function getPatientData() {

        // Retrieve data for this record
        $filter = '[demo_sent_to_curesma(1)] = "0"';
        $person = REDCap::getData($this->pid, 'array', $this->record_id, $this->fields, $this->event_id,
                                    null, null,null, null, $filter);

        return $person;
    }

    private function savePatientStatus() {

        // Set the status that say we've sent the data to CureSMA already
        $statusFields[$this->record_id][$this->event_id]['demo_sent_to_curesma'] = array('1' => '1');
        $statusFields[$this->record_id][$this->event_id]['demo_date_sent_curesma'] = date('Y-m-d H:i:s');
        $status = REDCap::saveData($this->pid, 'array', $statusFields, 'normal', 'YMD');
        if (!empty($status['errors'])) {
            $this->module->emError("Error saving status for record $this->record_id. Message: " . json_encode($status));
        }
    }


    private function packagePatientData($person) {

        // Package the data into FHIR format
        $coding = array(
            "code"          => "MR",
            "display"       => "Medical Record",
            "system"        => $this->fhir["system"]
        );

        $type =
            array(
                "coding" => array(
                    $coding
                )
            );

        $identifier = array(
            "system"            => $this->idSystem,
            "type"              => $type,
            "use"               => $this->idUse,
            "assigner"          => array("reference" => $this->fhir["assignor"]),
            "value"             => $person[$this->record_id][$this->event_id]["mrn"]
        );

        $name = array(
            "text"      => $person[$this->record_id][$this->event_id]["first_name"] . " " . $person[$this->record_id][$this->event_id]["last_name"],
            "given"     => array($person[$this->record_id][$this->event_id]["first_name"]),
            "family"    => $person[$this->record_id][$this->event_id]["last_name"]
        );

        // This is what the FHIR json format is for race (under the extension label)
        //        {
        //            "url": "http://hl7.org/fhir/us/core/ValueSet/omb-race-category",
        //            "valueCodeableConcept": {
        //                "coding": [
        //                    {
        //                        "system": "urn:oid:2.16.840.1.113883.6.238",
        //                        "code": "2028-9",
        //                        "display": "Asian"
        //                    }
        //                ]
        //            }
        //        }

        $race = array();
        $raceUrl = $this->raceInfo["url"];
        $raceCoding = $this->raceInfo["coding"];
        $patientRace = $raceCoding[$person[$this->record_id][$this->event_id]["race"]];

        // If we found the coding for this person's race, put it in the correct FHIR format
        if (!is_null($patientRace) && !empty($patientRace)) {
            $race = array(
                "url"                   =>    $raceUrl,
                "valueCodeableConcept"  =>
                    array(
                        "coding" => array($patientRace)
                    )
            );
        }

        // This is what the FHIR json format is for ethnicity (under the extension label)
        //        {
        //            "url": "http://hl7.org/fhir/StructureDefinition/us-core-ethnicity",
        //            "valueCodeableConcept": {
        //                "coding": [
        //                    {
        //                        "system": "http://hl7.org/fhir/ValueSet/daf-ethnicity",
        //                        "version": "1.0.2",
        //                        "code": "UNK",
        //                        "display": "Unknown"
        //                    }
        //                ]
        //            }
        //        }

        $ethnicity = array();
        $ethnicityUrl = $this->ethnicityInfo["url"];
        $ethnicityCoding = $this->ethnicityInfo["coding"];
        $patientEthnicity = $ethnicityCoding[$person[$this->record_id][$this->event_id]["ethnicity"]];

        // If we found the coding for this person's ethnicity, put it in the correct FHIR format
        if (!is_null($patientEthnicity) && !empty($patientEthnicity)) {
            $ethnicity = array(
                "url"                   =>    $ethnicityUrl,
                "valueCodeableConcept"  =>
                    array(
                        "coding" => array($patientEthnicity)
                    )
            );
        }

        // Add the patient's address
        $address = array(
            "use"       => "home",
            "line"      => array(
                $person[$this->record_id][$this->event_id]["street"]
            ),
            "city"          => $person[$this->record_id][$this->event_id]["city"],
            "state"         => $person[$this->record_id][$this->event_id]["state_text"],
            "postalCode"    => $person[$this->record_id][$this->event_id]["zip"],
            "country"       => $person[$this->record_id][$this->event_id]["country_text"]
        );


        // Put together the Patient resource with all the elements we are sending to CureSMA
        $patient = array(
            "resourceType"          => "Patient",
            "active"                => "true",
            "id"                    => $this->study_id,
            "name"                  => array(
                $name
            ),
            "extension"             => array($race, $ethnicity),
            "gender"                => $person[$this->record_id][$this->event_id]["gender"],
            "birthDate"             => $person[$this->record_id][$this->event_id]["dob"],
            "identifier"            => array(
                $identifier
            ),
            "address"               => array (
                $address
            )
        );

        $body = json_encode($patient,JSON_UNESCAPED_SLASHES);

        return $body;
    }

}
