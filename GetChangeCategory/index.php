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
$limit = 5000;

$IBLOCK_LOGGING = 19;
$SECTION_LOGGING = 21321;

/*Добавление новых разделов на сайт*/
$sections = [];
$resultSeqSections = [];
$sectionsArr = [];
$sectionsArrTMP = [];

$seq = '';
$flagSeq = true;

$cibs = new CIBlockSection;

if ($command === 'find') {
    $json = ['selector' => ['class_name' => 'cat.nom', 'is_folder' => true], 'limit' => $limit];
    $sectionsArr = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["cat"] . '/', '_find', $json, $user_login, $user_password);
} elseif ($command === 'change') {
    $since = file_get_contents('sectionSeq.txt');
    $keySince = !empty($since) ? '&since=' . $since : '';
    $limitSince = !empty($limit) ? '&limit=' . $limit : '';
    $json = ['selector' => ['class_name' => 'cat.nom', 'is_folder' => true]];
    $resultSeqSections = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["cat"] . '/', '_changes?filter=_selector&include_docs=true' . $limitSince . $keySince, $json, $user_login, $user_password);
    if ($since !== $resultSeqSections['last_seq'] && !empty($resultSeqSections['last_seq'])) {
        foreach ($resultSeqSections["results"] as $resultSeqSectionsItem) {
            $sectionsArr["docs"][] = $resultSeqSectionsItem->doc;
        }

        $seq = $resultSeqSections['last_seq'];
        //file_put_contents('sectionSeq.txt', $resultSeqSections['last_seq']);
    }
}

$sectionsArr = $sectionsArr["docs"];

if (!empty($sectionsArr)) {
    $arFilter = ['IBLOCK_ID' => $IBLOCK_ID];
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

        if ($service === "Услуга") {
            /*if ($log_error) {
                addLogToIB(
                    $sectionsArrItem,
                    'Ошибка: Раздел является услугой!',
                    true,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }*/
            continue;
        }

        if (!empty($sections[$guid])) {
            $arFieldsSection = array(
                "ACTIVE" => $publish ? "Y" : "N",
                "IBLOCK_SECTION_ID" => $sections[$guidParent],
                //"IBLOCK_SECTION_ID" => !empty($sections[$guidParent]) ? $sections[$guidParent] : false,
                "IBLOCK_ID" => $IBLOCK_ID,
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
            $sectionsArrTMP[$guid]["ACTIVE"] = $publish ? "Y" : "N";
            $sectionsArrTMP[$guid]["XML_ID"] = $guid;
            $sectionsArrTMP[$guid]["parent"]["NAME"] = $nameParent;
            $sectionsArrTMP[$guid]["parent"]["XML_ID"] = $guidParent;
        }
    }

    if (!empty($sectionsArrTMP)) {
        foreach ($sectionsArrTMP as $sectionsArrTMPItem) {
            $res = addSection($sectionsArrTMPItem, $sections, $sectionsArrTMP, $IBLOCK_ID, $IBLOCK_LOGGING, $SECTION_LOGGING);
            if (!$res) {
                if ($log_error) {
                    addLogToIB(
                        $sectionsArrTMPItem,
                        'Ошибка: Категория не добавлена на сайте!',
                        true,
                        $IBLOCK_LOGGING,
                        $SECTION_LOGGING
                    );
                }
                $flagSeq = false;
            }
        }
    } /*else {
        if ($log_error) {
            addLogToIB(
                $sectionsArrTMP,
                'Ошибка: В обмене отсутствуют новые элементы!',
                true,
                $IBLOCK_LOGGING,
                $SECTION_LOGGING
            );
        }
    }*/
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

if ($flagSeq) {
    file_put_contents('sectionSeq.txt', $seq);
}