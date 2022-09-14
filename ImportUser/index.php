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

$IBLOCK_LOGGING = 19;
$SECTION_LOGGING = 21327;

$step = !empty($_GET["step"]) ? (integer)$_GET["step"] : 1;
$command = !empty($_GET["command"]) ? $_GET["command"] : 'change';
$limit = 50000;
$skip = ($step - 1) * $limit;

$users = [];
$usersArray = [];
$resultSeqUsers = [];

if ($command === 'find') {
    $json = ['selector' => ['class_name' => 'cat.info_card'], 'limit' => $limit, "skip" => $skip];
    $users = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["cat"] . '/', '_find', $json, $user_login, $user_password);
} elseif ($command === 'change') {
    $since = file_get_contents('userSeq.txt');
    $keySince = !empty($since) ? '&since=' . $since : '';
    $limitSince = !empty($limit) ? '&limit=' . $limit : '';
    $json = ['selector' => ['class_name' => 'cat.info_card']];
    $resultSeqUsers = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["cat"] . '/', '_changes?filter=_selector&include_docs=true' . $limitSince . $keySince, $json, $user_login, $user_password);
    if ($since !== $resultSeqUsers['last_seq'] && !empty($resultSeqUsers['last_seq'])) {
        foreach ($resultSeqUsers["results"] as $resultSeqUsersItem) {
            $users["docs"][] = $resultSeqUsersItem->doc;
        }
        file_put_contents('userSeq.txt', $resultSeqUsers['last_seq']);
    }
}

$users = $users["docs"];

if (!empty($users)) {
    $order = ['sort' => 'asc'];
    $tmp = 'sort';
    $rsUsers = CUser::GetList($order, $tmp);
    while ($arrUsers = $rsUsers->GetNext()) {
        $usersArray[$arrUsers["LOGIN"]] = $arrUsers["ID"];
    }

    $user = new CUser;
    foreach ($users as $key => $usersItem) {
        if (!empty($usersArray[$usersItem->id_card])) {
            $arFields = [
                "NAME" => preg_replace('/\d/', '', $usersItem->name),
                "EMAIL" => $usersItem->email,
                "GROUP_ID" => [5],
                "PHONE_NUMBER" => '+' . $usersItem->phone,
                "PERSONAL_PHONE" => '+' . $usersItem->phone,
                "UF_TYPE_CARD" => $usersItem->kind_card->ref,
                "UF_VLADELEC" => $usersItem->card_holder->ref,
                "UF_DISCOUNT_CARD_VALUE" => $usersItem->discount,
                "XML_ID" => $usersItem->guid,
                //"LOGIN" => preg_replace('~\D+~', '', $usersItem->id_card),
                //"UF_NUMBER_CARD" => $usersItem->id_card,
            ];
            $userID = $usersArray[$usersItem->id_card];
            $ID = $user->Update($userID, $arFields);
            if (intval($ID) > 0) {
                if ($log_success) {
                    addLogToIB(
                        $arFields,
                        'Успех: Успешно обновлен пользователь с ID:' . $userID,
                        false,
                        $IBLOCK_LOGGING,
                        $SECTION_LOGGING
                    );
                }
            } else {
                if ($log_error) {
                    addLogToIB(
                        $arFields,
                        (string)$user->LAST_ERROR,
                        true,
                        $IBLOCK_LOGGING,
                        $SECTION_LOGGING
                    );
                }
            }
        } else {
            $tel = preg_replace('/\D*/', '', $usersItem->phone);
            if (substr($tel, 0, 1) === 8) {
                $tel[0] = 7;
            }
            $new_password = randString(8);

            $arFields = [
                "NAME" => preg_replace('/\d/', '', $usersItem->name),
                "LAST_NAME" => '',
                "EMAIL" => $usersItem->email,
                "LOGIN" => preg_replace('~\D+~', '', $usersItem->id_card),
                "XML_ID" => $usersItem->guid,
                "LID" => "ru",
                "PHONE_NUMBER" => '+' . $tel,
                "PERSONAL_PHONE" => '+' . $tel,
                "ACTIVE" => "Y",
                "GROUP_ID" => [5],
                "PASSWORD" => $new_password,
                "CONFIRM_PASSWORD" => $new_password,
                "UF_NUMBER_CARD" => $usersItem->id_card,
                "UF_TYPE_CARD" => $usersItem->kind_card->ref,
                "UF_VLADELEC" => $usersItem->card_holder->ref,
                "UF_DISCOUNT_CARD_VALUE" => $usersItem->discount
            ];

            $userID = $user->Add($arFields);
            if (intval($userID) > 0) {
                if ($log_success) {
                    addLogToIB(
                        $arFields,
                        'Успех: Успешно добавлен пользователь с ID:' . $userID,
                        false,
                        $IBLOCK_LOGGING,
                        $SECTION_LOGGING
                    );
                }
            } else {
                if ($log_error) {
                    addLogToIB(
                        $arFields,
                        (string)$user->LAST_ERROR,
                        true,
                        $IBLOCK_LOGGING,
                        $SECTION_LOGGING
                    );
                }
            }
        }
    }

    if (count($users) >= $limit && $command === 'find') {
        $step++;
        GoLinkExchange('https://' . SITE_SERVER_NAME . '/local/exchange/ImportUser/index.php?step=' . $step . '&command=' . $command);
    }
} /*else {
    if ($log_error) {
        addLogToIB(
            $users,
            'Ошибка: В обмене отсутствуют элементы!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}*/