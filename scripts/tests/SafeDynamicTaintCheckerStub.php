<?php
$argv[1] = htmlentities($argv[1], ENT_QUOTES);
$argv[2] = htmlentities($argv[2], ENT_QUOTES);
$argv[3] = htmlentities($argv[3], ENT_QUOTES);
$argv[4] = htmlentities($argv[4], ENT_QUOTES);
$argv[5] = htmlentities($argv[5]);
$argv[6] = htmlentities($argv[6]);
?>

<div>
    <input id="a" value="<?php echo $argv[1]; ?>" /> <!-- safe -->
    <input id="b" value="<?php echo $argv[2]; ?>" /> <!-- safe -->
    <input id="c" value="<?php echo $argv[3]; ?>" /> <!-- safe -->
    <input id=d value="<?php echo $argv[4]; ?>" /> <!-- safe -->
    <?php echo $argv[5]; ?> <!-- safe -->
    <?php echo $argv[6]; ?> <!-- safe -->
</div>