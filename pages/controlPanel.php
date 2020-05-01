<?php
namespace Stanford\DataTransferToCureSma;
/** @var \Stanford\DataTransferToCureSma\DataTransferToCureSma $module */


?>

<html>
<header>
    <title>Submit Data To CureSMA</title>
</header>
<body>
    <h4>To submit new data to CureSMA, please select the button below:</h4>
    <form method="post">
        <!--<form action="< ?php echo $page; ?>" method="post">  -->
        <button type="submit" value="submitData" onclick="<?php $module->submitCureSmaData(); ?>">Submit Data To CureSMA</button>
    </form>
</body>
</html>
