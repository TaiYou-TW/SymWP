<?php
$argv[1] = htmlentities($argv[1], ENT_COMPAT);
$argv[2] = htmlentities($argv[2], ENT_QUOTES);
$argv[3] = htmlentities($argv[3], ENT_NOQUOTES);
$argv[4] = htmlentities($argv[4], ENT_QUOTES);
$argv[5] = htmlentities($argv[5]);
$argv[6] = htmlentities($argv[6], ENT_QUOTES);
$argv[7] = htmlentities($argv[7], ENT_NOQUOTES);
?>

<div>
    <input id="a" value="<?php echo $argv[1]; ?>" /> <!-- safe -->
    <input id="b" value="<?php echo $argv[2]; ?>" /> <!-- safe -->
    <input id="c" value="<?php echo $argv[3]; ?>" /> <!-- vulnerable -->
    <input id=d value=<?php echo $argv[4]; ?> /> <!-- vulnerable -->
    <?php echo $argv[5]; ?> <!-- safe -->
    <?php echo $argv[6]; ?> <!-- vulnerable -->
    <img src="img/xxx.png" alt="<?php echo $argv[7]; ?>"> <!-- safe -->
    <img src="img/xxx.png" alt="<?php echo $argv[8]; ?>"> <!-- vulnerable -->
</div>