<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
require_once $_SERVER["DOCUMENT_ROOT"] . "/local/exchange/config.php";
/** @var array $configExchange */
/** @var $user_login */
/** @var $user_password */
/** @var $CouchDbIp */
/** @var $IBLOCK_ID */
/** @var $log_success */
/** @var $log_error */

CModule::IncludeModule("iblock");

$IBLOCK_LOGGING = 19;
$SECTION_LOGGING = 21333;

$limit = 50000;

$productsAnalog = [];
$siteProductArr = [];
$productArr = [];
$analogGuidArr = [];

$json = ['selector' => ['class_name' => 'ireg.analog_nom'], "limit" => $limit];
$productsAnalog = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["prop"] . '/', '_find', $json, $user_login, $user_password);
$productsAnalog = $productsAnalog["docs"];

if (!empty($productsAnalog)) {
    $arSort = ["ID" => 'asc'];
    $arFilter = ["IBLOCK_ID" => $IBLOCK_ID, "SHOW_HISTORY" => "Y", "ACTIVE" => "Y"];
    $arSelect = ["ID", "XML_ID", "CODE", "NAME", "PROPERTY_CML2_ARTICLE"];
    $siteProductGetList = CIBlockElement::GetList($arSort, $arFilter, false, false, $arSelect);
    while ($siteProductGetListItem = $siteProductGetList->GetNext()) {
        $siteProductArr[$siteProductGetListItem["XML_ID"]] = $siteProductGetListItem["ID"];
    }

    foreach ($productsAnalog as $key => $productsItem) {
        $guid = $productsItem->nom->ref;
        $groupAnalogsGuid = $productsItem->group_analogs->ref;
        $analogGuidArr[$guid] = $groupAnalogsGuid;
        if (!empty($siteProductArr[$guid])) {
            $productArr[$groupAnalogsGuid][] = $siteProductArr[$guid];
        }
    }

    $arSort = ["ID" => 'asc'];
    $arFilter = ["IBLOCK_ID" => $IBLOCK_ID, "SHOW_HISTORY" => 'Y'];
    $arSelect = ["ID", "XML_ID", "CODE", "NAME", "PROPERTY_CML2_ARTICLE"];
    $siteProductGetList = CIBlockElement::GetList($arSort, $arFilter, false, false, $arSelect);
    while ($siteProductGetListItem = $siteProductGetList->GetNext()) {
        if (!empty($analogGuidArr[$siteProductGetListItem["XML_ID"]])) {
            $arrAnalog = $productArr[$analogGuidArr[$siteProductGetListItem["XML_ID"]]];
            $key = array_search($siteProductGetListItem["ID"], $arrAnalog, true);
            unset($arrAnalog[$key]);
            CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["ANALOG_PRODUCTS" => $arrAnalog]);
            if ($log_success) {
                addLogToIB(
                    $siteProductGetListItem,
                    'Успех: Обновлены Аналоги товара',
                    false,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
        } else {
            CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["ANALOG_PRODUCTS" => false]);
        }
    }
} /*else {
    if ($log_error) {
        addLogToIB(
            $productsAnalog,
            'Ошибка: В обмене отсутствуют элементы!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}*/