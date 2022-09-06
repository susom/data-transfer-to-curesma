<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */

use Piping;
use REDCap;
use Project;
use Exception;


$pid = isset($_GET['pid']) && !empty($_GET['pid']) ? $_GET['pid'] : null;
$action = isset($_POST['action']) && !empty($_POST['action']) ? $_POST['action'] : null;
$resources = isset($_POST['resources']) && !empty($_POST['resources']) ? $_POST['resources'] : null;

$module->emDebug("Pid: " . $pid . ", action: " . $action . ", resource list: " . $resources);

if (($action === "sendData") && !empty($resources)) {
    $status = $module->submitCureSmaData($resources, $pid);
    print $status;
    return;
} else if (($action === "sendData") && empty($resources)) {
    $module->emDebug("No resources were selected to send CureSMA --- returning");
    print 2;
    return;
}

// Retrieve list of available resources to send to CureSMA
$resourceList = $module->retrieveResources();


?>

<!DOCTYPE html>
<html lang="en">
    <header>
        <title>Select resources to send to CureSMA</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=yes">
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous"/>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.6-rc.0/css/select2.min.css"/>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.7.14/css/bootstrap-datetimepicker.min.css">

        <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.6-rc.0/js/select2.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.15.1/moment.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/js/bootstrap.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.7.14/js/bootstrap-datetimepicker.min.js"></script>

    </header>

    <body>
        <div class="container">
            <div class="row p-1">
                <h3>Select resources to send to CureSMA</h3>
            </div>

            <div class='row mt-2'>
                <p class='ml-3'>
                    [<button class="btn btn-link active" type="button" onClick="unselectAll()">Unselect All</button>]
                    [<button class="btn btn-link active" type="button" onClick="selectAll()">Select All</button>]
                </p>
            </div>
            <div class="row p-1 ml-3 text-danger">
                <h6>*Please note: When Procedures or Vital Signs are selected, Encounters will automatically be sent</h6>
            </div>

            <div class="row p-1" id="resourceNames">
                 <?php
                 foreach ($resourceList as $key => $resource) {
                     ?>
                     <div class='col-md-3'>
                        <label for='<?php echo $resource; ?>'>
                            <input style='vertical-align:middle;' type='checkbox' id='<?php echo $key; ?>' name='<?php echo $key; ?>'>
                            <span style='word-break: break-all; vertical-align:middle'><?php echo $resource; ?></span>
                        </label>
                    </div>
                    <?php
                }
                ?>
            </div>

            <div>
                <input hidden id="redcap_csrf_token" value="<?php echo $module->getCSRFToken(); ?>" />
                <input class="btn btn-primary btn-block mt-5" type="button" onclick="send()" value="Send resources to CureSMA"></input>
            </div>

            <div style="padding: 20px">
                <label id="statusSend" />
            </div>

        </div>  <!-- end container -->
    </body>
</html>


<script>

    function selectAll() {
        var form = document.getElementById('resourceNames');
        $(form).find('input:checkbox').each(function() {
            $(this).prop('checked', true);
        });
    }

    function unselectAll() {
        var form = document.getElementById('resourceNames');
        $(form).find('input:checkbox').each(function() {
            $(this).removeAttr('checked');
        });
    }

    function send() {

        var selected;
        var selections = $('input[type=checkbox]:checked');

        for (var ncnt=0; ncnt < selections.length; ncnt++) {
            if (ncnt == 0) {
                selected = selections[ncnt].name;
            } else {
                selected = selected + "," + selections[ncnt].name;
            }
        }

        // Retrieve the token for this page
        var token = document.getElementById('redcap_csrf_token').value;

        DataTransferToCureSMA.sendResources(selected, token);
    }

    var DataTransferToCureSMA = DataTransferToCureSMA || {};

    // Make the API call back to the server to send the data
    DataTransferToCureSMA.sendResources = function(selected, token) {

        // Display a busy cursor
        $("body").css("cursor", "progress");

        $.ajax({
            type: "POST",
            data: {
                "action"        : "sendData",
                "resources"     : selected,
                "redcap_csrf_token" : token
            },
            success:function(status) {

                // Display results of load
                if (status === "1") {
                    $("#statusSend").text("Completed request").css({"color": "red"});
                } else if (status === '2') {
                    $("#statusSend").text("Please select resources to send to CureSMA").css({"color": "red"});
                } else {
                    $("#statusSend").text("Problem sending data to CureSMA. Please contact the REDCap team").css({"color": "red"});
                }

                // Return the cursor back to normal
                $("body").css("cursor", "default");
            }
        }).done(function (status) {
            console.log("Done: " + status);
        }).fail(function (jqXHR, textStatus, errorThrown) {
            console.log("Failed: sendData");
        });

    };

</script>
