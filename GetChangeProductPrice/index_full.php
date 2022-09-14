<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
require_once $_SERVER["DOCUMENT_ROOT"] . "/local/exchange/config.php";
/** @var array $configExchange */
/** @var $user_login */
/** @var $user_password */
/** @var $CouchDbIp */
/** @var $IBLOCK_ID */
/** @var $SERVICE_IBLOCK_ID */
/** @var $log_success */
/** @var $log_error */

CModule::IncludeModule("iblock");
Cmodule::IncludeModule('catalog');

$IBLOCK_LOGGING = 19;
$SECTION_LOGGING = 21323;

$productsSite = [];
$products = [];

$priceCurrentArr = [];

$productsArr = [];

$arSort = ["ID" => 'asc'];
$arFilter = ["IBLOCK_ID" => $IBLOCK_ID, "SHOW_HISTORY" => 'Y'];
$arSelect = ["ID", "XML_ID", "CODE", "NAME", "PROPERTY_CML2_ARTICLE"];
$siteProductGetList = CIBlockElement::GetList($arSort, $arFilter, false, false, $arSelect);
while ($siteProductGetListItem = $siteProductGetList->GetNext()) {
    $productsSite[] = $siteProductGetListItem["XML_ID"];
}

if (!empty($productsSite)) {
    $json = ['keys' => $productsSite];
    $priceCurrentArr = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["ireg"] . '/', '_design/price/_view/price?group=true', $json, $user_login, $user_password);
    $priceCurrentArr = $priceCurrentArr["rows"];

    if (!empty($priceCurrentArr)) {
        foreach ($priceCurrentArr as $priceCurrentArrItem) {
            $price = $priceCurrentArrItem->value->price;
            $products[$priceCurrentArrItem->key] = !empty($price) ? $price : 0;
        }

        $arSort = ["ID" => 'asc'];
        $arFilter = ["IBLOCK_ID" => $IBLOCK_ID, "SHOW_HISTORY" => 'Y'];
        $arSelect = ["ID", "XML_ID", "NAME", "PROPERTY_CML2_ARTICLE"];
        $siteProductGetList = CIBlockElement::GetList($arSort, $arFilter, false, false, $arSelect);
        while ($siteProductGetListItem = $siteProductGetList->GetNext()) {
            if (!empty($products[$siteProductGetListItem["XML_ID"]])) {
                $PRICE_TYPE_ID = 1;
                $arFields = array(
                    "PRODUCT_ID" => $siteProductGetListItem["ID"],
                    "CATALOG_GROUP_ID" => $PRICE_TYPE_ID,
                    "PRICE" => $products[$siteProductGetListItem["XML_ID"]],
                    "CURRENCY" => "RUB",
                );
                $resPrice = CPrice::GetList(
                    [],
                    [
                        "PRODUCT_ID" => $siteProductGetListItem["ID"],
                        "CATALOG_GROUP_ID" => $PRICE_TYPE_ID
                    ]
                );

                $obPrice = new CPrice();

                if ($arrPrice = $resPrice->Fetch()) {
                    $res = $obPrice->Update($arrPrice["ID"], $arFields);
                    if ($res) {
                        if ($log_success) {
                            addLogToIB(
                                $siteProductGetListItem,
                                'Успешно: Обновлена цена: ' . $res,
                                false,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                    } else {
                        if ($log_error) {
                            addLogToIB(
                                $siteProductGetListItem,
                                (string)$obPrice->LAST_ERROR,
                                true,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                    }
                } else {
                    $res = $obPrice->Add($arFields);
                    if ($res) {
                        if ($log_success) {
                            addLogToIB(
                                $siteProductGetListItem,
                                'Успешно: Добавлена цена: ' . $res,
                                false,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                    } else {
                        if ($log_error) {
                            addLogToIB(
                                $siteProductGetListItem,
                                (string)$obPrice->LAST_ERROR,
                                true,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                    }
                }
            } else {
                $PRICE_TYPE_ID = 1;
                $arFields = array(
                    "PRODUCT_ID" => $siteProductGetListItem["ID"],
                    "CATALOG_GROUP_ID" => $PRICE_TYPE_ID,
                    "PRICE" => 0,
                    "CURRENCY" => "RUB",
                );
                $resPrice = CPrice::GetList(
                    [],
                    [
                        "PRODUCT_ID" => $siteProductGetListItem["ID"],
                        "CATALOG_GROUP_ID" => $PRICE_TYPE_ID
                    ]
                );

                $obPrice = new CPrice();

                if ($arrPrice = $resPrice->Fetch()) {
                    $res = $obPrice->Update($arrPrice["ID"], $arFields);
                    if ($res) {
                        if ($log_success) {
                            addLogToIB(
                                $siteProductGetListItem,
                                'Успешно: Обновлена цена: ' . $res,
                                false,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                    } else {
                        if ($log_error) {
                            addLogToIB(
                                $siteProductGetListItem,
                                (string)$obPrice->LAST_ERROR,
                                true,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                    }
                } else {
                    $res = $obPrice->Add($arFields);
                    if ($res) {
                        addLogToIB(
                            $siteProductGetListItem,
                            'Успешно: Добавлена цена: ' . $res,
                            false,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    } else {
                        if ($log_error) {
                            addLogToIB(
                                $siteProductGetListItem,
                                (string)$obPrice->LAST_ERROR,
                                true,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                    }
                }
            }
        }
    } else {
        if ($log_error) {
            addLogToIB(
                $priceCurrentArr,
                'Ошибка: В обмене отсутствуют элементы!',
                true,
                $IBLOCK_LOGGING,
                $SECTION_LOGGING
            );
        }
    }
} /*else {
    if ($log_error) {
        addLogToIB(
            $productsSite,
            'Ошибка: Отсутствуют элементы в ИБ Услуги!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}*/