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
$SECTION_LOGGING = 21319;

$services = [];
$servicesSite = [];

$arSort = ["ID" => 'asc'];
$arFilter = ["IBLOCK_ID" => $SERVICE_IBLOCK_ID, "SHOW_HISTORY" => 'Y'];
$arSelect = ["ID", "XML_ID", "CODE", "NAME", "PROPERTY_CML2_ARTICLE"];
$siteServicesGetList = CIBlockElement::GetList($arSort, $arFilter, false, false, $arSelect);
while ($siteServicesGetListItem = $siteServicesGetList->GetNext()) {
    $servicesSite[] = $siteServicesGetListItem["XML_ID"];
}

if (!empty($servicesSite)) {
    $json = ['keys' => $servicesSite];
    $priceCurrentArr = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["ireg"] . '/', '_design/price/_view/price?group=true', $json, $user_login, $user_password);
    $priceCurrentArr = $priceCurrentArr["rows"];

    if (!empty($priceCurrentArr)) {
        foreach ($priceCurrentArr as $priceCurrentArrItem) {
            $price = $priceCurrentArrItem->value->price;
            $services[$priceCurrentArrItem->key] = !empty($price) ? $price : 0;
        }

        $arSort = ["ID" => 'asc'];
        $arFilter = ["IBLOCK_ID" => $SERVICE_IBLOCK_ID, "SHOW_HISTORY" => 'Y'];
        $arSelect = ["ID", "XML_ID", "CODE", "NAME", "PROPERTY_CML2_ARTICLE"];
        $siteServicesGetList = CIBlockElement::GetList($arSort, $arFilter, false, false, $arSelect);
        while ($siteServicesGetListItem = $siteServicesGetList->GetNext()) {
            if (!empty($services[$siteServicesGetListItem["XML_ID"]])) {
                $PRICE_TYPE_ID = 1;
                $arFields = array(
                    "PRODUCT_ID" => $siteServicesGetListItem["ID"],
                    "CATALOG_GROUP_ID" => $PRICE_TYPE_ID,
                    "PRICE" => $services[$siteServicesGetListItem["XML_ID"]],
                    "CURRENCY" => "RUB",
                );
                $resPrice = CPrice::GetList(
                    [],
                    [
                        "PRODUCT_ID" => $siteServicesGetListItem["ID"],
                        "CATALOG_GROUP_ID" => $PRICE_TYPE_ID
                    ]
                );

                $obPrice = new CPrice();

                if ($arrPrice = $resPrice->Fetch()) {
                    $res = $obPrice->Update($arrPrice["ID"], $arFields);
                    if ($res) {
                        if ($log_success) {
                            addLogToIB(
                                $siteServicesGetListItem,
                                'Успешно: Обновлена цена: ' . $res,
                                false,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                    } else {
                        if ($log_error) {
                            addLogToIB(
                                $siteServicesGetListItem,
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
                                $siteServicesGetListItem,
                                'Успешно: Добавлена цена: ' . $res,
                                false,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                    } else {
                        if ($log_error) {
                            addLogToIB(
                                $siteServicesGetListItem,
                                (string)$obPrice->LAST_ERROR,
                                true,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                    }
                }

                $arFields = [
                    "ID" => $siteServicesGetListItem["ID"],
                    "QUANTITY" => 0
                ];

                $existProduct = \Bitrix\Catalog\Model\Product::getCacheItem($arFields['ID'], true);
                if (!empty($existProduct)) {
                    $res = \Bitrix\Catalog\Model\Product::update(intval($arFields['ID']), $arFields);
                } else {
                    $res = \Bitrix\Catalog\Model\Product::add($arFields);
                }
            } else {
                $PRICE_TYPE_ID = 1;
                $arFields = array(
                    "PRODUCT_ID" => $siteServicesGetListItem["ID"],
                    "CATALOG_GROUP_ID" => $PRICE_TYPE_ID,
                    "PRICE" => 0,
                    "CURRENCY" => "RUB",
                );
                $resPrice = CPrice::GetList(
                    [],
                    [
                        "PRODUCT_ID" => $siteServicesGetListItem["ID"],
                        "CATALOG_GROUP_ID" => $PRICE_TYPE_ID
                    ]
                );

                $obPrice = new CPrice();

                if ($arrPrice = $resPrice->Fetch()) {
                    $res = $obPrice->Update($arrPrice["ID"], $arFields);
                    if ($res) {
                        if ($log_success) {
                            addLogToIB(
                                $siteServicesGetListItem,
                                'Успешно: Обновлена цена: ' . $res,
                                false,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                    } else {
                        if ($log_error) {
                            addLogToIB(
                                $siteServicesGetListItem,
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
                                $siteServicesGetListItem,
                                'Успешно: Добавлена цена: ' . $res,
                                false,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                    } else {
                        if ($log_error) {
                            addLogToIB(
                                $siteServicesGetListItem,
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
            $servicesSite,
            'Ошибка: Отсутствуют элементы в ИБ Услуги!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}*/