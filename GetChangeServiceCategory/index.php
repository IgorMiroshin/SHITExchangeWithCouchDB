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

$step = !empty($_GET["step"]) ? (integer)$_GET["step"] : 1;
$substep = !empty($_GET["substep"]) ? (integer)$_GET["substep"] : 1;
$command = !empty($_GET["command"]) ? $_GET["command"] : 'change';
$limit = 500;

$IBLOCK_LOGGING = 19;
$SECTION_LOGGING = 21317;

/*Добавление новых разделов на сайт*/
$sections = [];
$resultSeqSections = [];
$sectionsArr = [];
$sectionsArrTMP = [];

$cibs = new CIBlockSection;

if ($command === 'find') {
    $json = [
        'selector' => [
            'class_name' => 'cat.nom',
            'is_folder' => true,
            "nom_kind" => ["ref" => "668580ac-83c9-11e1-9985-e0cb4ec1e754"]
        ],
        'limit' => $limit
    ];
    $sectionsArr = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["cat"] . '/', '_find', $json, $user_login, $user_password);
} elseif ($command === 'change') {
    $since = file_get_contents('sectionSeq.txt');
    $keySince = !empty($since) ? '&since=' . $since : '';
    $limitSince = !empty($limit) ? '&limit=' . $limit : '';
    $json = [
        'selector' => [
            'class_name' => 'cat.nom',
            'is_folder' => true,
            "nom_kind" => ["ref" => "668580ac-83c9-11e1-9985-e0cb4ec1e754"]
        ],
    ];
    $resultSeqSections = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["cat"] . '/', '_changes?filter=_selector&include_docs=true' . $limitSince . $keySince, $json, $user_login, $user_password);
    if ($since !== $resultSeqSections['last_seq'] && !empty($resultSeqSections['last_seq'])) {
        foreach ($resultSeqSections["results"] as $resultSeqSectionsItem) {
            $sectionsArr["docs"][] = $resultSeqSectionsItem->doc;
        }
        file_put_contents('sectionSeq.txt', $resultSeqSections['last_seq']);
    }
}

$sectionsArr = $sectionsArr["docs"];

if (!empty($sectionsArr)) {
    $arFilter = ['IBLOCK_ID' => $SERVICE_IBLOCK_ID];
    $rsSections = CIBlockSection::GetList(['ID' => 'ASC'], $arFilter, false, ["ID", "XML_ID", "NAME"]);
    while ($arSection = $rsSections->GetNext()) {
        $sections[$arSection["XML_ID"]] = $arSection["ID"];
    }

    foreach ($sectionsArr as $key => $sectionsArrItem) {
        $guid = $sectionsArrItem->guid;
        $name = $sectionsArrItem->name;
        $guidParent = $sectionsArrItem->parent->ref;
        $nameParent = $sectionsArrItem->parent->presentation;
        $publish = $sectionsArrItem->publish_on_site;
        $service = $sectionsArrItem->nom_kind->presentation;

        if ($service !== "Услуга") {
            if ($log_error) {
                addLogToIB(
                    $sectionsArrItem,
                    'Ошибка: Раздел не является услугой!',
                    true,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
            continue;
        }

        if ($publish) {
            if (!empty($sections[$guid])) {
                $arFieldsSection = array(
                    "ACTIVE" => "Y",
                    "IBLOCK_SECTION_ID" => !empty($sections[$guidParent]) ? $sections[$guidParent] : false,
                    "IBLOCK_ID" => $SERVICE_IBLOCK_ID,
                    "NAME" => $name,
                );
                $resUpdateSection = $cibs->Update($sections[$guid], $arFieldsSection);
                if ($resUpdateSection) {
                    if ($log_success) {
                        addLogToIB(
                            $sectionsArrItem,
                            'Успешно: Обновлен Раздел: ' . $sections[$guid],
                            false,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                } else {
                    if ($log_error) {
                        addLogToIB(
                            $sectionsArrItem,
                            (string)$cibs->LAST_ERROR,
                            true,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                }
            } else {
                $sectionsArrTMP[$guid]["NAME"] = $name;
                $sectionsArrTMP[$guid]["XML_ID"] = $guid;
                $sectionsArrTMP[$guid]["parent"]["NAME"] = $nameParent;
                $sectionsArrTMP[$guid]["parent"]["XML_ID"] = $guidParent;
            }
        } else {
            if (!empty($sections[$guid])) {
                $arFieldsSection = array(
                    "ACTIVE" => "N",
                );
                $resUpdateSection = $cibs->Update($sections[$guid], $arFieldsSection);
                if ($resUpdateSection) {
                    if ($log_success) {
                        addLogToIB(
                            $sectionsArrItem,
                            'Успешно: Обновлен Раздел: ' . $sections[$guid],
                            false,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                } else {
                    if ($log_error) {
                        addLogToIB(
                            $sectionsArrItem,
                            (string)$cibs->LAST_ERROR,
                            true,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                }
            }
        }
    }

    if (!empty($sectionsArrTMP)) {
        foreach ($sectionsArrTMP as $sectionsArrTMPItem) {
            addSection($sectionsArrTMPItem, $sections, $sectionsArrTMP, $SERVICE_IBLOCK_ID, $IBLOCK_LOGGING, $SECTION_LOGGING);
        }
    } else {
        if ($log_error) {
            addLogToIB(
                $sectionsArrTMP,
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
            $sectionsArr,
            'Ошибка: В обмене отсутствуют элементы!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}*/