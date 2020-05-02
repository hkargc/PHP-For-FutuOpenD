<?php
/**
 * 同步模式
 */
include(__DIR__ . '/lib/class.futu.php');
$o = new futu('127.0.0.1', 5001, '20202020');
$l = $o->Qot_GetStaticInfo(3);
print_r($l);