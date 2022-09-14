<?php
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

$step = !empty($_GET["step"]) ? (integer)$_GET["step"] : 1;
$substep = !empty($_GET["substep"]) ? (integer)$_GET["substep"] : 1;
$command = !empty($_GET["command"]) ? $_GET["command"] : 'change';
$limit = 500;

$IBLOCK_LOGGING = 19;
$SECTION_LOGGING = 21323;

$priceArr = [];
$resultSeqPrice = [];
$productsChangeArr = [];
$products = [];
$productsSite = [];

if ($command === 'find') {
    $priceArr = GetPriceCOUCHD($CouchDbIp, '/' . $configExchange["ireg"] . '/', '_design/price/_view/price_many', $user_login, $user_password);
} elseif ($command === 'change') {
    $since = file_get_contents('priceSeq.txt');
    $keySince = !empty($since) ? "&since=" . $since : "";
    $limitSince = !empty($limit) ? "&limit=" . $limit : "";
    $resultSeqPrice = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["ireg"] . '/', '_changes?include_docs=true' . $limitSince . $keySince, [], $user_login, $user_password);//&limit= . $limit
    if ($since !== $resultSeqPrice['last_seq'] && !empty($resultSeqPrice['last_seq'])) {
        foreach ($resultSeqPrice["results"] as $resultSeqPriceItem) {
            $priceArr["docs"][] = $resultSeqPriceItem->doc;
        }
        file_put_contents('priceSeq.txt', $resultSeqPrice['last_seq']);
    }
}

$priceArr = $priceArr["docs"];

if (!empty($priceArr)) {
    $arSort = ["ID" => 'asc'];
    $arFilter = ["IBLOCK_ID" => $IBLOCK_ID, "SHOW_HISTORY" => 'Y'];
    $arSelect = ["ID", "XML_ID", "CODE", "NAME", "PROPERTY_CML2_ARTICLE"];
    $siteProductsGetList = CIBlockElement::GetList($arSort, $arFilter, false, false, $arSelect);
    while ($siteProductsGetListItem = $siteProductsGetList->GetNext()) {
        $productsSite[] = $siteProductsGetListItem["XML_ID"];
    }

    foreach ($priceArr as $priceArrItem) {
        $guid = $priceArrItem->nom->ref;
        if (!empty($guid)) {
            if ($log_error) {
                addLogToIB(
                    $priceArrItem,
                    'Ошибка: Пустое значение Внешний код!',
                    true,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
            continue;
        }

        if (in_array($guid, $productsSite)) {
            $productsChangeArr[] = $guid;
        } else {
            if ($log_error) {
                addLogToIB(
                    $priceArrItem,
                    'Ошибка: Элемент отсутствует в ИБ Каталог товаров!',
                    true,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
        }
    }

    $productsChangeArr = array_values(array_unique($productsChangeArr));

    if (!empty($productsChangeArr)) {
        $json = ['keys' => $productsChangeArr];
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
    } else {
        if ($log_error) {
            addLogToIB(
                $productsChangeArr,
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
            $priceArr,
            'Ошибка: В обмене отсутствуют элементы!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}*/
