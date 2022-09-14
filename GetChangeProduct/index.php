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

$step = !empty($_GET["step"]) ? (integer)$_GET["step"] : 1;
$substep = !empty($_GET["substep"]) ? (integer)$_GET["substep"] : 1;
$command = !empty($_GET["command"]) ? $_GET["command"] : 'change';
$limit = 5000;
$skip = ($substep - 1) * $limit;

$IBLOCK_LOGGING = 19;
$SECTION_LOGGING = 21322;

$products = [];
$productArr = [];
$propertyArr = [];
$siteProductArr = [];

$seq = '';
$flagSeq = true;

$blockElement = new CIBlockElement;
$cibpe = new CIBlockPropertyEnum;

if ($command === 'find') {
    $json = ['selector' => ['class_name' => 'cat.nom'], "limit" => $limit, "skip" => $skip];
    $products = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["cat"] . '/', '_find', $json, $user_login, $user_password);
} elseif ($command === 'change') {
    $since = file_get_contents('productSeq.txt');
    $keySince = !empty($since) ? '&since=' . $since : '';
    $limitSince = !empty($limit) ? '&limit=' . $limit : '';
    $json = ['selector' => ['class_name' => 'cat.nom']];
    $resultSeqProduct = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["cat"] . '/', '_changes?filter=_selector&include_docs=true' . $limitSince . $keySince, $json, $user_login, $user_password);
    if ($since !== $resultSeqProduct['last_seq'] && !empty($resultSeqProduct['last_seq'])) {
        foreach ($resultSeqProduct["results"] as $resultSeqProductItem) {
            $products["docs"][] = $resultSeqProductItem->doc;
        }
        $seq = $resultSeqProduct['last_seq'];
        //file_put_contents('productSeq.txt', $resultSeqProduct['last_seq']);
    }
}

$products = $products["docs"];

if (!empty($products)) {
    $arSort = ['ID' => 'ASC'];
    $arFilter = ["IBLOCK_ID" => $IBLOCK_ID];
    $arSelect = ["ID", "XML_ID", "NAME"];
    $rsSections = CIBlockSection::GetList($arSort, $arFilter, false, $arSelect);
    while ($arSection = $rsSections->GetNext()) {
        if (!empty($arSection["XML_ID"])) {
            $sections[$arSection["XML_ID"]] = $arSection["ID"];
        }
    }

    $arSort = ['ID' => 'ASC'];
    $arFilter = ["IBLOCK_ID" => $IBLOCK_ID, "CODE" => "BL_QUALITY"];
    $propertyQuality = CIBlockProperty::GetList($arSort, $arFilter)->Fetch();
    $arSort = ['ID' => 'ASC'];
    $arFilter = ["IBLOCK_ID" => $IBLOCK_ID, "CODE" => "BL_QUALITY"];
    $propertyGetList = CIBlockPropertyEnum::GetList($arSort, $arFilter);
    while ($propertyItem = $propertyGetList->GetNext()) {
        $propertyArr[$propertyItem["XML_ID"]] = $propertyItem["ID"];
    }

    foreach ($products as $key => $productsItem) {
        $guid = $productsItem->guid;
        $article = $productsItem->article;
        $publish = $productsItem->publish_on_site;
        $archival = $productsItem->archival;
        $service = $productsItem->service;
        $category = $productsItem->parent;
        $quality = $productsItem->quality;
        $measure = !empty($productsItem->base_unit->presentation) ? $productsItem->base_unit->presentation : '';
        $additionalMeasureName = !empty($productsItem->report_unit->ref->presentation) ? $productsItem->report_unit->ref->presentation : '';
        $additionalMeasureValue = !empty($productsItem->report_unit->coefficient) ? $productsItem->report_unit->coefficient : '';
        $active = !$archival && $publish;

        if (empty($article)) {
            /*if ($log_error) {
                addLogToIB(
                    $productsItem,
                    'Ошибка: Пустое значение артикула! Ревизия: ' . $seq,
                    true,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }*/
            continue;
        }

        if ($service) {
            /*if ($log_error) {
                addLogToIB(
                    $productsItem,
                    'Ошибка: Элемент является услугой! Ревизия: ' . $seq,
                    true,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }*/
            continue;
        }

        $sectionID = $sections[$category->ref];

        if (empty($sectionID)) {
            if ($log_error) {
                addLogToIB(
                    $productsItem,
                    'Ошибка: Категория не добавлена на сайте! Ревизия: ' . $seq,
                    true,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
            $sectionID = 21478;
            /*if ($active) {
                $flagSeq = false;
                continue;
            }*/
        }

        $params = array(
            "max_len" => "128", // обрезает символьный код до 128 символов
            "change_case" => "L", // буквы преобразуются к нижнему регистру
            "replace_space" => "_", // меняем пробелы на нижнее подчеркивание
            "replace_other" => "_", // меняем левые символы на нижнее подчеркивание
            "delete_repeat_replace" => "true", // удаляем повторяющиеся нижние подчеркивания
            "use_google" => "false", // отключаем использование google
        );
        $code = CUtil::translit(trim($article) . '_' . trim($productsItem->name), "ru", $params);
        $productArr[$guid]["CODE"] = $code;
        $productArr[$guid]["XML_ID"] = $guid;
        $productArr[$guid]["ACTIVE"] = $active ? "Y" : "N";
        $productArr[$guid]["NAME"] = $productsItem->name;
        $productArr[$guid]["SECTION_ID"] = $sectionID;
        $productArr[$guid]["DETAIL_TEXT"] = !empty($productsItem->dop_info_nom) ? $productsItem->dop_info_nom : '';

        if (!empty($quality)) {
            if (empty($propertyArr[$quality->ref])) {
                $qualityID = $cibpe->Add(['PROPERTY_ID' => $propertyQuality["ID"], 'VALUE' => $quality->presentation, "XML_ID" => $quality->ref]);
                if ($qualityID) {
                    if ($log_success) {
                        addLogToIB(
                            $productsItem,
                            "Успех: Добавлено новое свойство: " . $qualityID,
                            false,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                } else {
                    if ($log_error) {
                        addLogToIB(
                            $productsItem,
                            'Свойство Качество ' . $quality->presentation . ' не добавлено! Ревизия: ' . $seq,
                            true,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                }
                $propertyArr[$quality->ref] = $qualityID;
            } else {
                $qualityID = $propertyArr[$quality->ref];
            }
        } else {
            $qualityID = false;
        }

        if ($measure === 'кг') {
            $measureCode = 4;
        } elseif ($measure === 'компл') {
            $measureCode = 6;
        } elseif ($measure === 'кор') {
            $measureCode = 13;
        } elseif ($measure === 'л.') {
            $measureCode = 2;
        } elseif ($measure === 'лист') {
            $measureCode = 7;
        } elseif ($measure === 'м') {
            $measureCode = 1;
        } elseif ($measure === 'м /п' || $measure === 'пог. м') {
            $measureCode = 8;
        } elseif ($measure === 'м2') {
            $measureCode = 12;
        } elseif ($measure === 'м3') {
            $measureCode = 16;
        } elseif ($measure === 'мест') {
            $measureCode = 18;
        } elseif ($measure === 'меш') {
            $measureCode = 17;
        } elseif ($measure === 'пар') {
            $measureCode = 9;
        } elseif ($measure === 'рул') {
            $measureCode = 10;
        } elseif ($measure === 'т') {
            $measureCode = 20;
        } elseif ($measure === 'уп.' || $measure === 'упак') {
            $measureCode = 11;
        } elseif ($measure === 'тыс. шт') {
            $measureCode = 21;
        } else {
            $measureCode = 5;
        }

        $productArr[$guid]["PROPERTY"] = [
            "CML2_ARTICLE" => $article,
            "DEKOR" => $productsItem->decor,
            "BL_POD_ZAKAZ" => $productsItem->under_order ? 168251 : 226202,
            "BL_VYBYTIE" => $productsItem->disposal ? 168250 : false,
            "TRADEMARK" => $productsItem->trademark->presentation,
            "BL_QUALITY" => $qualityID,
            "MEASURE" => $measureCode
        ];

        if ($measure !== $additionalMeasureName && $additionalMeasureValue !== 1) {
            $productArr[$guid]["PROPERTY"] += [
                "COEFFICIENT_NAME" => $additionalMeasureName,
                "COEFFICIENT_VALUE" => $additionalMeasureValue,
            ];
        }
    }

    if (!empty($productArr)) {
        $arSort = ["ID" => 'asc'];
        $arFilter = ["IBLOCK_ID" => $IBLOCK_ID, "SHOW_HISTORY" => 'Y'];
        $arSelect = ["ID", "XML_ID", "CODE", "NAME", "PROPERTY_CML2_ARTICLE"];
        $siteProductGetList = CIBlockElement::GetList($arSort, $arFilter, false, false, $arSelect);
        while ($siteProductGetListItem = $siteProductGetList->GetNext()) {
            $siteProductArr[$siteProductGetListItem["XML_ID"]] = $siteProductGetListItem["ID"];
        }

        foreach ($productArr as $productArrItem) {
            if (empty($siteProductArr[$productArrItem["XML_ID"]])) {
                if ($productArrItem["ACTIVE"] === "N") {
                    continue;
                }
                $arLoadProductArray = [
                    "MODIFIED_BY" => "1",
                    "ACTIVE" => $productArrItem["ACTIVE"],
                    "XML_ID" => $productArrItem["XML_ID"],
                    "IBLOCK_SECTION_ID" => $productArrItem["SECTION_ID"],
                    'IBLOCK_ID' => $IBLOCK_ID,
                    "NAME" => $productArrItem["NAME"],
                    "CODE" => $productArrItem["CODE"],
                    "DETAIL_TEXT" => $productArrItem["DETAIL_TEXT"]
                ];
                $resUpdate = $blockElement->Add($arLoadProductArray);
                if ($resUpdate) {
                    if ($log_success) {
                        addLogToIB(
                            $productArrItem,
                            'Успешно: Добавлен Товар: ' . $resUpdate,
                            false,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                    CIBlockElement::SetPropertyValuesEx($resUpdate, $IBLOCK_ID, ["TRADEMARK" => $productArrItem["PROPERTY"]["TRADEMARK"]]);
                    CIBlockElement::SetPropertyValuesEx($resUpdate, $IBLOCK_ID, ["CML2_ARTICLE" => $productArrItem["PROPERTY"]["CML2_ARTICLE"]]);
                    CIBlockElement::SetPropertyValuesEx($resUpdate, $IBLOCK_ID, ["DEKOR" => $productArrItem["PROPERTY"]["DEKOR"]]);
                    CIBlockElement::SetPropertyValuesEx($resUpdate, $IBLOCK_ID, ["BL_QUALITY" => $productArrItem["PROPERTY"]["BL_QUALITY"]]);
                    CIBlockElement::SetPropertyValuesEx($resUpdate, $IBLOCK_ID, ["BL_VYBYTIE" => $productArrItem["PROPERTY"]["BL_VYBYTIE"]]);
                    CIBlockElement::SetPropertyValuesEx($resUpdate, $IBLOCK_ID, ["BL_POD_ZAKAZ" => $productArrItem["PROPERTY"]["BL_POD_ZAKAZ"]]);
                    CIBlockElement::SetPropertyValuesEx($resUpdate, $IBLOCK_ID, ["COEFFICIENT_NAME" => $productArrItem["PROPERTY"]["COEFFICIENT_NAME"]]);
                    CIBlockElement::SetPropertyValuesEx($resUpdate, $IBLOCK_ID, ["COEFFICIENT_VALUE" => $productArrItem["PROPERTY"]["COEFFICIENT_VALUE"]]);

                    $arFields = ['MEASURE' => $productArrItem["PROPERTY"]["MEASURE"]];
                    CCatalogProduct::Update($resUpdate, $arFields);
                } else {
                    if ($log_error) {
                        addLogToIB(
                            $productArrItem,
                            'Товар не добавлен! Ревизия: ' . $seq,
                            true,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                }
            } else {
                if ($productArrItem["ACTIVE"] === "N") {
                    CIBlockElement::Delete($siteProductArr[$productArrItem["XML_ID"]]);
                    continue;
                }
                $arLoadProductArray = array(
                    "MODIFIED_BY" => "1",
                    "ACTIVE" => $productArrItem["ACTIVE"],
                    "IBLOCK_SECTION" => $productArrItem["SECTION_ID"],
                    'IBLOCK_ID' => $IBLOCK_ID,
                    "NAME" => $productArrItem["NAME"],
                    "DETAIL_TEXT" => $productArrItem["DETAIL_TEXT"],
                );
                $resUpdate = $blockElement->Update($siteProductArr[$productArrItem["XML_ID"]], $arLoadProductArray);
                if ($resUpdate) {
                    if ($log_success) {
                        addLogToIB(
                            $productArrItem,
                            'Успешно: Обновлен Товар: ' . $siteProductArr[$productArrItem["XML_ID"]],
                            false,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                    CIBlockElement::SetPropertyValuesEx($siteProductArr[$productArrItem["XML_ID"]], $IBLOCK_ID, ["TRADEMARK" => $productArrItem["PROPERTY"]["TRADEMARK"]]);
                    CIBlockElement::SetPropertyValuesEx($siteProductArr[$productArrItem["XML_ID"]], $IBLOCK_ID, ["CML2_ARTICLE" => $productArrItem["PROPERTY"]["CML2_ARTICLE"]]);
                    CIBlockElement::SetPropertyValuesEx($siteProductArr[$productArrItem["XML_ID"]], $IBLOCK_ID, ["DEKOR" => $productArrItem["PROPERTY"]["DEKOR"]]);
                    CIBlockElement::SetPropertyValuesEx($siteProductArr[$productArrItem["XML_ID"]], $IBLOCK_ID, ["BL_QUALITY" => $productArrItem["PROPERTY"]["BL_QUALITY"]]);
                    CIBlockElement::SetPropertyValuesEx($siteProductArr[$productArrItem["XML_ID"]], $IBLOCK_ID, ["BL_VYBYTIE" => $productArrItem["PROPERTY"]["BL_VYBYTIE"]]);
                    CIBlockElement::SetPropertyValuesEx($siteProductArr[$productArrItem["XML_ID"]], $IBLOCK_ID, ["BL_POD_ZAKAZ" => $productArrItem["PROPERTY"]["BL_POD_ZAKAZ"]]);
                    CIBlockElement::SetPropertyValuesEx($siteProductArr[$productArrItem["XML_ID"]], $IBLOCK_ID, ["COEFFICIENT_NAME" => $productArrItem["PROPERTY"]["COEFFICIENT_NAME"]]);
                    CIBlockElement::SetPropertyValuesEx($siteProductArr[$productArrItem["XML_ID"]], $IBLOCK_ID, ["COEFFICIENT_VALUE" => $productArrItem["PROPERTY"]["COEFFICIENT_VALUE"]]);

                    $arFields = ['MEASURE' => $productArrItem["PROPERTY"]["MEASURE"]];
                    CCatalogProduct::Update($siteProductArr[$productArrItem["XML_ID"]], $arFields);
                } else {
                    if ($log_error) {
                        addLogToIB(
                            $productArrItem,
                            'Товар не обновлен! Ревизия: ' . $seq,
                            true,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                }
            }
        }
    } /*else {
        if ($log_error) {
            addLogToIB(
                $productArr,
                'Ошибка: В обмене отсутствуют новые элементы!',
                true,
                $IBLOCK_LOGGING,
                $SECTION_LOGGING
            );
        }
    }*/
    if (count($products) >= $limit && $command === 'find') {
        $substep++;
        GoLinkExchange('https://' . SITE_SERVER_NAME . '/local/exchange/PartialImportProduct/GetChangeProduct/index.php?step=' . $step . '&substep=' . $substep . '&command=' . $command);
    }
} /*else {
    if ($log_error) {
        addLogToIB(
            $products,
            'Ошибка: В обмене отсутствуют элементы!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}*/

if ($flagSeq) {
    file_put_contents('productSeq.txt', $seq);
}