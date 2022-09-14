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

$step = !empty($_GET["step"]) ? (integer)$_GET["step"] : 1;
$substep = !empty($_GET["substep"]) ? (integer)$_GET["substep"] : 1;
$command = !empty($_GET["command"]) ? $_GET["command"] : 'change';
$limit = 500;
$skip = ($substep - 1) * $limit;

$resultSeqCount = [];
$countArr = [];
$productsChangeArr = [];
$currentCountArr = [];
$products = [];

if ($command === 'find') {
    $json = ["limit" => $limit, "skip" => $skip];
    $countArr = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["areg"] . '/', '_find/', $json, $user_login, $user_password);
} elseif ($command === 'change') {
    $since = file_get_contents('countSeq.txt');
    $keySince = !empty($since) ? "&since=" . $since : "";
    $limitSince = !empty($limit) ? '&limit=' . $limit : '';
    $json = [];
    $resultSeqCount = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["areg"] . '/', '_changes?include_docs=true' . $limitSince . $keySince, $json, $user_login, $user_password);
    if ($since !== $resultSeqCount['last_seq'] && !empty($resultSeqCount['last_seq'])) {
        foreach ($resultSeqCount["results"] as $resultSeqCountItem) {
            $countArr["docs"][] = $resultSeqCountItem->doc;
        }
        file_put_contents('countSeq.txt', $resultSeqCount['last_seq']);
    }
}

$countArr = $countArr["docs"];

if (!empty($countArr)) {
    foreach ($countArr as $countArrItem) {
        $guid = $countArrItem->nom->ref;
        //$series = $countArrItem->series_nom->ref;
        //$quality = $countArrItem->quality->ref;
        $quantity = $countArrItem->quantity;
        //$productArr[] = $guid . '|' . $series . '|' . $quality;
        if (!empty($guid)) {
            $productsChangeArr[] = $guid;
        }
    }

    $productsChangeArr = array_values(array_unique($productsChangeArr));

    if (!empty($productsChangeArr)) {
        $json = ['keys' => $productsChangeArr];
        $currentCountArr = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["areg"] . '/', '_design/goods/_view/last?group=true', $json, $user_login, $user_password);
        $currentCountArr = $currentCountArr["rows"];

        if (!empty($currentCountArr)) {
            foreach ($currentCountArr as $currentCountArrItem) {
                $guid = $currentCountArrItem->key;
                $quantity = $currentCountArrItem->value / 1000;
                $products[$guid] = !empty($quantity) ? $quantity : 0;
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
                    if ($log_success) {
                        addLogToIB(
                            $siteProductGetListItem,
                            'Успех: Обновлен остаток товара. Количество: ' . $products[$siteProductGetListItem["XML_ID"]],
                            true,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                }
            }
        } else {
            if ($log_error) {
                addLogToIB(
                    $currentCountArr,
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

    if (count($countArr) >= $limit && $command === 'find') {
        $substep++;
        GoLinkExchange('https://' . SITE_SERVER_NAME . '/local/exchange/PartialImportProduct/GetChangeProductQuantity/index.php?step=' . $step . '&substep=' . $substep . '&command=' . $command);
    }
} /*else {
    if ($log_error) {
        addLogToIB(
            $countArr,
            'Ошибка: В обмене отсутствуют элементы!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}*/