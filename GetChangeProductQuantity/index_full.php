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
Cmodule::IncludeModule('catalog');

$IBLOCK_LOGGING = 19;
$SECTION_LOGGING = 21337;

$siteProductArrInCouchDB = [];
$currentCountArr = [];
$products = [];
$productsArr = [];

$quantityProduct = new CCatalogProduct();
$blockElement = new CIBlockElement;

$siteProductGetList = CIBlockElement::GetList(["ID" => 'asc'], ["IBLOCK_ID" => $IBLOCK_ID, "SHOW_HISTORY" => 'Y'], false, false, ["ID", "XML_ID", "CODE", "NAME", "PROPERTY_CML2_ARTICLE"]);
while ($siteProductGetListItem = $siteProductGetList->GetNext()) {
    $siteProductArrInCouchDB[] = $siteProductGetListItem["XML_ID"];
}

$json = ['keys' => $siteProductArrInCouchDB];
$currentCountArr = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["areg"] . '/', '_design/goods/_view/last?group=true', $json, $user_login, $user_password);
$currentCountArr = $currentCountArr["rows"];

if (!empty($currentCountArr)) {

    foreach ($currentCountArr as $currentCountArrItem) {
        $products[$currentCountArrItem->key] = $currentCountArrItem->value / 1000;
        $productsArr[] = $currentCountArrItem->key;
    }

    $siteProductGetList = CIBlockElement::GetList(["ID" => 'asc'], ["IBLOCK_ID" => $IBLOCK_ID, "SHOW_HISTORY" => 'Y'], false, false, ["ID", "XML_ID", "NAME", "PROPERTY_CML2_ARTICLE", "PROPERTY_SERIES", "PROPERTY_BL_QUALITY"]);
    while ($siteProductGetListItem = $siteProductGetList->GetNext()) {
        if (!empty($products[$siteProductGetListItem["XML_ID"]])) {
            $arFields = [
                "ID" => $siteProductGetListItem["ID"],
                "QUANTITY" => $products[$siteProductGetListItem["XML_ID"]]
            ];

            $existProduct = \Bitrix\Catalog\Model\Product::getCacheItem($arFields['ID'], true);
            if (!empty($existProduct)) {
                $res = \Bitrix\Catalog\Model\Product::update(intval($arFields['ID']), $arFields);
            } else {
                $res = \Bitrix\Catalog\Model\Product::add($arFields);
            }

        } else {
            $arFields = [
                "ID" => $siteProductGetListItem["ID"],
                "QUANTITY" => 0
            ];

            $existProduct = \Bitrix\Catalog\Model\Product::getCacheItem($arFields['ID'], true);
            if (!empty($existProduct)) {
                $res = \Bitrix\Catalog\Model\Product::update(intval($arFields['ID']), $arFields);
            } else {
                $res = \Bitrix\Catalog\Model\Product::add($arFields);
            }
        }
    }
} /*else {
    if ($log_error) {
        addLogToIB(
            $currentCountArr,
            'Ошибка: В обмене отсутствуют элементы!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}*/
