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
$SECTION_LOGGING = 21322;

$products = [];
$productArr = [];
$propertyArr = [];
$siteProductArr = [];

$blockElement = new CIBlockElement;
$cibpe = new CIBlockPropertyEnum;

$json = ['selector' => ['class_name' => 'cat.nom',"guid"=>"b0edf033-ebd8-11e8-8223-5ef3fc5770b3"]];
$products = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["cat"] . '/', '_find', $json, $user_login, $user_password);

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

        if (empty($article)) {
            if ($log_error) {
                addLogToIB(
                    $productsItem,
                    'Ошибка: Пустое значение артикула!',
                    true,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
            continue;
        }

        if (empty($sections[$category->ref])) {
            if ($log_error) {
                addLogToIB(
                    $productsItem,
                    'Ошибка: Категория не добавлена на сайте!',
                    true,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
            continue;
        }

        if ($service) {
            if ($log_error) {
                addLogToIB(
                    $productsItem,
                    'Ошибка: Элемент является услугой!',
                    true,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
            continue;
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
        $productArr[$guid]["ACTIVE"] = $archival || !$publish ? "N" : "Y";
        $productArr[$guid]["NAME"] = $productsItem->name;
        $productArr[$guid]["SECTION_ID"] = $sections[$category->ref];
        $productArr[$guid]["DETAIL_TEXT"] = !empty($productsItem->dop_info_nom) ? $productsItem->dop_info_nom : '';

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
                        (string)$cibpe->LAST_ERROR,
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


} else {
    if ($log_error) {
        addLogToIB(
            $products,
            'Ошибка: В обмене отсутствуют элементы!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}