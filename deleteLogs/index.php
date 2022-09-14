<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
$dir = $_SERVER["DOCUMENT_ROOT"] . '/local/exchange';
$files = searchFiles($dir, '*Log.{txt}', null);

echo "<pre>";
var_dump($files);
echo "</pre>";

foreach ($files as $file) {
    unlink($dir . '/' . $file);
}