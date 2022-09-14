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
$SECTION_LOGGING = 21331;

$productsDate = [];
$productArr = [];

$limit = 50000;

$blockElement = new CIBlockElement;

$json = ['selector' => ['class_name' => 'doc.purchase_order'], "limit" => $limit];
$productsDate = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["prop"] . '/', '_find', $json, $user_login, $user_password);
$productsDate = $productsDate["docs"];

if (!empty($productsDate)) {
    foreach ($productsDate as $key => $productsItem) {
        $guid = $productsItem->nom->ref;
        $date = $productsItem->date;
        if (strtotime($date) > strtotime(date('d.m.Y'))) {
            if (!empty($productArr[$guid])) {
                if (strtotime($productArr[$guid]) > strtotime($date)) {
                    $productArr[$guid] = date('d.m.Y', strtotime($date));
                }
            } else {
                $productArr[$guid] = date('d.m.Y', strtotime($date));
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
            CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["DATE_DELIVERY" => $item]);
            if ($log_success) {
                addLogToIB(
                    $siteProductGetListItem,
                    'Успех: Обновлена дата поставки товара',
                    false,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
        } else {
            CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["DATE_DELIVERY" => false]);
        }
    }
} /*else {
    if ($log_error) {
        addLogToIB(
            $productsDate,
            'Ошибка: В обмене отсутствуют элементы!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}*/