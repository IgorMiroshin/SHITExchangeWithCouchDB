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
$limit = 500;

$IBLOCK_LOGGING = 19;
$SECTION_LOGGING = 21318;

$services = [];
$servicesArr = [];
$siteServicesArr = [];

$blockElement = new CIBlockElement;

if ($command === 'find') {
    $json = ['selector' => ['class_name' => 'cat.nom', "service" => true]];
    $services = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["cat"] . '/', '_find', $json, $user_login, $user_password);
} elseif ($command === 'change') {
    $since = file_get_contents('productSeq.txt');
    $keySince = !empty($since) ? '&since=' . $since : '';
    $limitSince = !empty($limit) ? '&limit=' . $limit : '';
    $json = ['selector' => ['class_name' => 'cat.nom', "service" => true], 'limit' => $limit];
    $resultSeqService = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["cat"] . '/', '_changes?filter=_selector&include_docs=true' . $limitSince . $keySince, $json, $user_login, $user_password);
    if ($since !== $resultSeqService['last_seq'] && !empty($resultSeqService['last_seq'])) {
        foreach ($resultSeqService["results"] as $resultSeqServiceItem) {
            $services["docs"][] = $resultSeqServiceItem->doc;
        }
        file_put_contents('productSeq.txt', $resultSeqService['last_seq']);
    }
}

$services = $services["docs"];

if (!empty($services)) {
    $arFilter = ['IBLOCK_ID' => $SERVICE_IBLOCK_ID];
    $rsSections = CIBlockSection::GetList(['ID' => 'ASC'], $arFilter, false, ["ID", "XML_ID", "NAME"]);
    while ($arSection = $rsSections->GetNext()) {
        if (!empty($arSection["XML_ID"])) {
            $sections[$arSection["XML_ID"]] = $arSection["ID"];
        }
    }

    foreach ($services as $key => $servicesItem) {
        $guid = $servicesItem->guid;
        $article = $servicesItem->article;
        $publish = $servicesItem->publish_on_site;
        $archival = $servicesItem->archival;
        $service = $servicesItem->service;
        $category = $servicesItem->parent;

        if (empty($article)) {
            if ($log_error) {
                addLogToIB(
                    $servicesItem,
                    '????????????: ???????????? ???????????????? ????????????????!',
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
                    $servicesItem,
                    '????????????: ?????????????????? ???? ?????????????????? ???? ??????????!',
                    true,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
            continue;
        }

        if (!$service) {
            if ($log_error) {
                addLogToIB(
                    $servicesItem,
                    '????????????: ?????????????? ???? ???????????????? ??????????????!',
                    true,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
            continue;
        }

        $params = array(
            "max_len" => "128", // ???????????????? ???????????????????? ?????? ???? 128 ????????????????
            "change_case" => "L", // ?????????? ?????????????????????????? ?? ?????????????? ????????????????
            "replace_space" => "_", // ???????????? ?????????????? ???? ???????????? ??????????????????????????
            "replace_other" => "_", // ???????????? ?????????? ?????????????? ???? ???????????? ??????????????????????????
            "delete_repeat_replace" => "true", // ?????????????? ?????????????????????????? ???????????? ??????????????????????????
            "use_google" => "false", // ?????????????????? ?????????????????????????? google
        );
        $code = CUtil::translit(trim($article) . '_' . trim($servicesItem->name), "ru", $params);
        $servicesArr[$guid]["CODE"] = $code;
        $servicesArr[$guid]["XML_ID"] = $guid;
        $servicesArr[$guid]["ACTIVE"] = $archival || !$publish ? "N" : "Y";
        $servicesArr[$guid]["NAME"] = $servicesItem->name;
        $servicesArr[$guid]["SECTION_ID"] = $sections[$category->ref];
        $servicesArr[$guid]["DETAIL_TEXT"] = !empty($servicesItem->dop_info_nom) ? $servicesItem->dop_info_nom : '';

        $measure = !empty($servicesItem->base_unit->presentation) ? $servicesItem->base_unit->presentation : '';
        $additionalMeasureName = !empty($servicesItem->report_unit->ref->presentation) ? $servicesItem->report_unit->ref->presentation : '';
        $additionalMeasureValue = !empty($servicesItem->report_unit->coefficient) ? $servicesItem->report_unit->coefficient : '';

        if ($measure === '????') {
            $measureCode = 4;
        } elseif ($measure === '??????????') {
            $measureCode = 6;
        } elseif ($measure === '??????') {
            $measureCode = 13;
        } elseif ($measure === '??.') {
            $measureCode = 2;
        } elseif ($measure === '????????') {
            $measureCode = 7;
        } elseif ($measure === '??') {
            $measureCode = 1;
        } elseif ($measure === '?? /??' || $measure === '??????. ??') {
            $measureCode = 8;
        } elseif ($measure === '??2') {
            $measureCode = 12;
        } elseif ($measure === '??3') {
            $measureCode = 16;
        } elseif ($measure === '????????') {
            $measureCode = 18;
        } elseif ($measure === '??????') {
            $measureCode = 17;
        } elseif ($measure === '??????') {
            $measureCode = 9;
        } elseif ($measure === '??????') {
            $measureCode = 10;
        } elseif ($measure === '??') {
            $measureCode = 20;
        } elseif ($measure === '????.' || $measure === '????????') {
            $measureCode = 11;
        } elseif ($measure === '??????. ????') {
            $measureCode = 21;
        } else {
            $measureCode = 5;
        }

        $servicesArr[$guid]["PROPERTY"] = [
            "CML2_ARTICLE" => $article,
            "MEASURE" => $measureCode
        ];

        if ($measure !== $additionalMeasureName && $additionalMeasureValue !== 1) {
            $servicesArr[$guid]["PROPERTY"] += [
                "COEFFICIENT_NAME" => $additionalMeasureName,
                "COEFFICIENT_VALUE" => $additionalMeasureValue,
            ];
        }
    }

    if (!empty($servicesArr)) {
        $arSort = ["ID" => 'asc'];
        $arFilter = ["IBLOCK_ID" => $SERVICE_IBLOCK_ID, "SHOW_HISTORY" => 'Y'];
        $arSelect = ["ID", "XML_ID", "CODE", "NAME", "PROPERTY_CML2_ARTICLE"];
        $siteServicesGetList = CIBlockElement::GetList($arSort, $arFilter, false, false, $arSelect);
        while ($siteServicesGetListItem = $siteServicesGetList->GetNext()) {
            $siteServicesArr[$siteServicesGetListItem["XML_ID"]] = $siteServicesGetListItem["ID"];
        }

        foreach ($servicesArr as $servicesArrItem) {
            if (empty($siteServicesArr[$servicesArrItem["XML_ID"]])) {
                $arLoadProductArray = [
                    "MODIFIED_BY" => "1",
                    "ACTIVE" => $servicesArrItem["ACTIVE"],
                    "XML_ID" => $servicesArrItem["XML_ID"],
                    "IBLOCK_SECTION_ID" => $servicesArrItem["SECTION_ID"],
                    'IBLOCK_ID' => $SERVICE_IBLOCK_ID,
                    "NAME" => $servicesArrItem["NAME"],
                    "CODE" => $servicesArrItem["CODE"],
                    "DETAIL_TEXT" => $servicesArrItem["DETAIL_TEXT"]
                ];
                $resUpdate = $blockElement->Add($arLoadProductArray);
                if ($resUpdate) {
                    if ($log_success) {
                        addLogToIB(
                            $servicesArrItem,
                            '??????????????: ?????????????????? ????????????: ' . $resUpdate,
                            false,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                    CIBlockElement::SetPropertyValuesEx($resUpdate, $SERVICE_IBLOCK_ID, ["CML2_ARTICLE" => $servicesArrItem["PROPERTY"]["CML2_ARTICLE"]]);

                    $arFields = ['MEASURE' => $servicesArrItem["PROPERTY"]["MEASURE"]];
                    CCatalogProduct::Update($resUpdate, $arFields);
                } else {
                    if ($log_error) {
                        addLogToIB(
                            $servicesArrItem,
                            (string)$blockElement->LAST_ERROR,
                            true,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                }
            } else {
                $arLoadServicesArray = [
                    "MODIFIED_BY" => "1",
                    "ACTIVE" => $servicesArrItem["ACTIVE"],
                    "IBLOCK_SECTION" => $servicesArrItem["SECTION_ID"],
                    'IBLOCK_ID' => $SERVICE_IBLOCK_ID,
                    "NAME" => $servicesArrItem["NAME"],
                    "DETAIL_TEXT" => $servicesArrItem["DETAIL_TEXT"],
                ];
                $resUpdate = $blockElement->Update($siteServicesArr[$servicesArrItem["XML_ID"]], $arLoadServicesArray);
                if ($resUpdate) {
                    if ($log_success) {
                        addLogToIB(
                            $servicesArrItem,
                            '??????????????: ?????????????????? ????????????: ' . $siteServicesArr[$servicesArrItem["XML_ID"]],
                            false,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                    CIBlockElement::SetPropertyValuesEx($siteServicesArr[$servicesArrItem["XML_ID"]], $SERVICE_IBLOCK_ID, ["CML2_ARTICLE" => $servicesArrItem["PROPERTY"]["CML2_ARTICLE"]]);

                    $arFields = ['MEASURE' => $servicesArrItem["PROPERTY"]["MEASURE"]];
                    CCatalogProduct::Update($siteServicesArr[$servicesArrItem["XML_ID"]], $arFields);
                } else {
                    if ($log_error) {
                        addLogToIB(
                            $servicesArrItem,
                            (string)$blockElement->LAST_ERROR,
                            true,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                }
            }
        }
    } else {
        if ($log_error) {
            addLogToIB(
                $servicesArr,
                '????????????: ?? ???????????? ?????????????????????? ????????????????!',
                true,
                $IBLOCK_LOGGING,
                $SECTION_LOGGING
            );
        }
    }
} /*else {
    if ($log_error) {
        addLogToIB(
            $services,
            '????????????: ?? ???????????? ?????????????????????? ????????????????!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}*/