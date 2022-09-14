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
$SECTION_LOGGING = 21334;

$limit = 50000;

$productsRelated = [];
$siteProductArr = [];
$productArr = [];

$json = ['selector' => ['class_name' => 'cat.related_nom'], "limit" => $limit];
$productsRelated = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["prop"] . '/', '_find', $json, $user_login, $user_password);
$productsRelated = $productsRelated["docs"];

if (!empty($productsRelated)) {
    $arSort = ["ID" => 'asc'];
    $arFilter = ["IBLOCK_ID" => $IBLOCK_ID, "SHOW_HISTORY" => "Y", "ACTIVE" => "Y"];
    $arSelect = ["ID", "XML_ID", "CODE", "NAME", "PROPERTY_CML2_ARTICLE"];
    $siteProductGetList = CIBlockElement::GetList($arSort, $arFilter, false, false, $arSelect);
    while ($siteProductGetListItem = $siteProductGetList->GetNext()) {
        $siteProductArr[$siteProductGetListItem["XML_ID"]] = $siteProductGetListItem["ID"];
    }

    foreach ($productsRelated as $productsItem) {
        $guid = $productsItem->guid;
        $relatedNomArray = $productsItem->related_nom;
        foreach ($relatedNomArray as $relatedNom) {
            $guidRelated = $relatedNom->nom->ref;
            if (!empty($siteProductArr[$guidRelated])) {
                $productArr[$guid][] = $siteProductArr[$guidRelated];
            }
        }
    }

    $arSort = ["ID" => 'asc'];
    $arFilter = ["IBLOCK_ID" => $IBLOCK_ID, "SHOW_HISTORY" => 'Y'];
    $arSelect = ["ID", "XML_ID", "CODE", "NAME", "PROPERTY_CML2_ARTICLE"];
    $siteProductGetList = CIBlockElement::GetList($arSort, $arFilter, false, false, $arSelect);
    while ($siteProductGetListItem = $siteProductGetList->GetNext()) {
        $item = $productArr[$siteProductGetListItem["XML_ID"]];
        if (!empty($item)) {
            CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["RELATED_PRODUCTS" => $item]);
            if ($log_success) {
                addLogToIB(
                    $siteProductGetListItem,
                    'Успех: Обновлены Сопутствующих товары',
                    false,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
        } else {
            CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["RELATED_PRODUCTS" => false]);
        }
    }
} /*else {
    if ($log_error) {
        addLogToIB(
            $productsRelated,
            'Ошибка: В обмене отсутствуют элементы!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}*/