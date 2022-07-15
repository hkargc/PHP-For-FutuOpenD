<?php
/**
 * 同步模式
 */
include(__DIR__ . '/lib/class.futu.php');
$futu = new futu('127.0.0.1', 11111, '888888');
$list = $futu->Qot_GetStaticInfo(3);
print_r($list);