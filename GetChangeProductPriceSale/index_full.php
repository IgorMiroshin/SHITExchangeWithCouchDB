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
$SECTION_LOGGING = 21336;

$limit = 50000;

$PriceSaleCurrentArr = [];
$products = [];
$productsSalesArr = [];

$json = ['selector' => ['class_name' => 'ireg.nom_discount'], "limit" => $limit];
$PriceSaleCurrentArr = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["disc"] . '/', '_all_docs?include_docs=true', $json, $user_login, $user_password);
$PriceSaleCurrentArr = $PriceSaleCurrentArr["rows"];

if (!empty($PriceSaleCurrentArr)) {
    foreach ($PriceSaleCurrentArr as $key => $PriceSaleCurrentArrItem) {
        $doc = $PriceSaleCurrentArrItem->doc;
        $nom = $doc->nom;
        $guid = $nom->ref;
        $condition_presentation = $doc->condition->presentation;

        $dateCurrent = strtotime(date('d.m.Y'));

        if (!empty($guid)) {
            if ($doc->recipient_discounts->ref === "00000000-0000-0000-0000-000000000000") {
                $start_date = date('d.m.Y', strtotime($doc->start_date));
                $expiration_date = date('d.m.Y', strtotime($doc->expiration_date));
                if (
                    ($expiration_date === "01.01.0001" && strtotime($start_date) <= $dateCurrent) ||
                    (strtotime($start_date) <= $dateCurrent && strtotime($expiration_date) >= $dateCurrent)
                ) {
                    $products[$guid][$condition_presentation]["condition_ref"] = $doc->condition->ref;
                    $products[$guid][$condition_presentation]["condition_presentation"] = $doc->condition->presentation;
                    $products[$guid][$condition_presentation]["discount_percent"] = $doc->discount_percent;
                    $products[$guid][$condition_presentation]["limit_discounts"] = $doc->limit_discounts;
                    $products[$guid][$condition_presentation]["value_conditions"] = !is_object($doc->value_conditions) ? $doc->value_conditions : false;
                    $products[$guid][$condition_presentation]["start_date"] = !empty($doc->start_date) ? $start_date : false;
                    $products[$guid][$condition_presentation]["expiration_date"] = !empty($doc->expiration_date) && $expiration_date !== "01.01.0001" ? $expiration_date : false;

                    $productsSalesArr[$guid][$condition_presentation][$key] = ["VALUE" => implode("_____", $products[$guid][$condition_presentation])];
                }
            }
        }
    }
    if (!empty($productsSalesArr)) {
        $arSort = ["ID" => 'asc'];
        $arFilter = ["IBLOCK_ID" => $IBLOCK_ID, "SHOW_HISTORY" => 'Y'];
        $arSelect = ["ID", "XML_ID", "CODE", "NAME", "PROPERTY_CML2_ARTICLE", "PROPERTY_DEKOR"];
        $siteProductGetList = CIBlockElement::GetList($arSort, $arFilter, false, false, $arSelect);
        while ($siteProductGetListItem = $siteProductGetList->GetNext()) {
            $discountArr = [];
            $discountArr = $productsSalesArr[$siteProductGetListItem["XML_ID"]];
            if (!empty($discountArr)) {
                if (!empty($discountArr["Акция"])) {
                    $discountArrItem = $discountArr["Акция"];
                    CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["STOCK_DISCOUNT_FULL" => $discountArrItem]);
                    if ($log_success) {
                        addLogToIB(
                            $discountArrItem,
                            'Успех: Добавлена Акция на товар с ID:' . $siteProductGetListItem["ID"],
                            false,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                    CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["DISCOUNT_TYPE" => "STOCK"]);
                } else {
                    CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["STOCK_DISCOUNT_FULL" => false]);
                }

                if (!empty($discountArr["Распродажа"])) {
                    $discountArrItem = $discountArr["Распродажа"];
                    CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["SALE_DISCOUNT_FULL" => $discountArrItem]);
                    if ($log_success) {
                        addLogToIB(
                            $discountArrItem,
                            'Успех: Добавлена Распродажа на товар с ID:' . $siteProductGetListItem["ID"],
                            false,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                    CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["DISCOUNT_TYPE" => "SALE"]);
                } else {
                    CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["SALE_DISCOUNT_FULL" => false]);
                }

                if (!empty($discountArr["Количество одного товара в документе превысило"])) {
                    $discountArrItem = $discountArr["Количество одного товара в документе превысило"];
                    CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["COUNT_DISCOUNT_FULL" => $discountArrItem]);
                    if ($log_success) {
                        addLogToIB(
                            $discountArrItem,
                            'Успех: Добавлена Скидка на количество на товар с ID:' . $siteProductGetListItem["ID"],
                            false,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                } else {
                    CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["COUNT_DISCOUNT_FULL" => false]);
                }
            } else {
                CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["STOCK_DISCOUNT_FULL" => false]);
                CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["SALE_DISCOUNT_FULL" => false]);
                CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["COUNT_DISCOUNT_FULL" => false]);
                CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, ["DISCOUNT_TYPE" => false]);
            }
        }
    } else {
        if ($log_error) {
            addLogToIB(
                $PriceSaleCurrentArr,
                'Ошибка: В обмене отсутствуют новые элементы!',
                true,
                $IBLOCK_LOGGING,
                $SECTION_LOGGING
            );
        }
    }
} /*else {
    if ($log_error) {
        addLogToIB(
            $PriceSaleCurrentArr,
            'Ошибка: В обмене отсутствуют элементы!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}*/