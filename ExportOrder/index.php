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

CModule::IncludeModule("sale");

$IBLOCK_LOGGING = 19;
$SECTION_LOGGING = 21329;

$order = [];
//Добавление новых заказов в БД
$orderId = $_GET['order'];

if (!empty($orderId)) {
    $file = 'order_' . $orderId . '.xml';
    $export = new CSaleExport();
    $arFilter = ["ID" => $orderId];
    $filter = ['filter' => $arFilter, 'limit' => '30'];
    $resExport = $export->export($filter);
    $xml = $resExport->getData()[0];
    $xml = str_replace('windows-1251', 'UTF-8', $xml);
    file_put_contents($file, $xml);
    $xml = simplexml_load_string(file_get_contents($file));
    if ($xml) {
        $xml = $xml->Документ;
        //$xml->addChild('order_id', $orderId);
        $xml->addChild('class_name', 'doc.buyers_order_www');
        $jsonGET = ['selector' => ['class_name' => 'doc.buyers_order_www', "nomer" => $orderId]];
        $resGet = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["doc"] . '/', '_find', $jsonGET, $user_login, $user_password);
        if (!empty($resGet["docs"])) {
            $id = $resGet["docs"][0]->_id;
            $rev = $resGet["docs"][0]->_rev;
            $xml->addChild("_rev", $rev);
        } else {
            for ($i = 1; $i <= 3; $i++) {
                $bytes = random_bytes(4);
                $hex .= bin2hex($bytes) . '-';
            }
            $id = 'doc.buyers_order_www|' . $hex . 'order-' . $xml->Номер;
        }
        $xml->addChild('_id', $id);
        $xmlCustom = [];
        $langParam = ["max_len" => '256', "replace_space" => "_", "replace_other" => "_"];

        foreach ((array)$xml as $key => $xmlItem) {
            if (is_array($xmlItem)) {
                echo 'test1';
            } elseif (is_object($xmlItem)) {
                if ($key === 'ЗначенияРеквизитов') {
                    $requisiteArr = (array)$xmlItem;
                    foreach ($requisiteArr["ЗначениеРеквизита"] as $requisite) {
                        $requisite = (array)$requisite;
                        $xmlCustom[Cutil::translit($requisite["Наименование"], "ru", $langParam)] = $requisite["Значение"];
                    }
                } elseif ($key === 'timestamp') {
                    $xmlCustom[$key] = $xmlItem;
                } elseif ($key === 'Товары') {
                    $productsCurrentArr = [];
                    $xmlItemArr = (array)$xmlItem;
                    $productsTMPArr = $xmlItemArr["Товар"];
                    $productsArr = (array)$productsTMPArr;
                    if (!empty($productsArr["Ид"])) {
                        $productsCurrentArr[] = $productsTMPArr;
                    } else {
                        $productsCurrentArr = $productsArr;
                    }
                    foreach ($productsCurrentArr as $keyProductsItem => $productsItem) {
                        $item = (array)$productsItem;
                        //unset($item["ЗначенияРеквизитов"]);
                        unset($item["БазоваяЕдиница"]);
                        foreach ($item as $keyItem => $itemProp) {
                            if ($keyItem === 'ЗначенияРеквизитов') {
                                $itemPropArray = (array)$itemProp;
                                foreach ($itemPropArray["ЗначениеРеквизита"] as $keyReqItem => $reqItem) {
                                    $reqItemArray = (array)$reqItem;
                                    $xmlCustom['products'][$keyProductsItem][Cutil::translit($reqItemArray["Наименование"], "ru", $langParam)] = $reqItemArray["Значение"];
                                }
                            } else {
                                $xmlCustom['products'][$keyProductsItem][Cutil::translit($keyItem, "ru", $langParam)] = $itemProp;
                            }

                        }
                    }
                } elseif ($key === 'Контрагенты') {
                    $contractorsArr = (array)$xmlItem;
                    foreach ($contractorsArr["Контрагент"] as $keyContractors => $contractorsItem) {
                        if ($keyContractors === 'Контакты') {
                            $userContactsArray = (array)$contractorsItem;
                            foreach ($userContactsArray["Контакт"] as $keyContacts => $userContactsItem) {
                                $userContactsItemArray = (array)$userContactsItem;
                                $xmlCustom['contractors'][Cutil::translit($userContactsItemArray["Тип"], "ru", $langParam)] = $userContactsItemArray["Значение"];
                            }
                        } elseif ($keyContractors === 'АдресРегистрации') {
                            $userContactsArray = (array)$contractorsItem;
                            $userContactsAddress = (array)$userContactsArray["АдресноеПоле"];
                            $xmlCustom['contractors'][Cutil::translit($userContactsAddress["Тип"], "ru", $langParam)] = $userContactsAddress["Значение"];
                        } else {
                            $contractorsItem = (array)$contractorsItem;
                            $xmlCustom['contractors'][Cutil::translit($keyContractors, "ru", $langParam)] = $contractorsItem[0];
                        }
                    }
                } elseif ($key === 'ПодчиненныеДокументы') {
                    $podchinennyyeDokumentyArr = (array)$xmlItem;
                    foreach ($podchinennyyeDokumentyArr["ПодчиненныйДокумент"] as $keyItem => $podchinennyyeDokumentyItem) {
                        $podchinennyyeDokumentyItemArray = (array)$podchinennyyeDokumentyItem;
                        foreach ($podchinennyyeDokumentyItemArray as $keyItemProp => $item) {
                            if ($keyItemProp === 'ЗначенияРеквизитов') {
                                $itemArray = (array)$item;
                                foreach ($itemArray["ЗначениеРеквизита"] as $reqProp) {
                                    $reqPropArray = (array)$reqProp;
                                    $xmlCustom[Cutil::translit($key, "ru", $langParam)][$keyItem][Cutil::translit($reqPropArray["Наименование"], "ru", $langParam)] = $reqPropArray["Значение"];
                                }
                            } elseif ($keyItemProp === 'Товары') {
                                $productsCurrentArr = [];
                                $xmlItemArr = (array)$item;
                                $productsTMPArr = $xmlItemArr["Товар"];
                                $productsArr = (array)$productsTMPArr;
                                if (!empty($productsArr["Ид"])) {
                                    $productsCurrentArr[] = $productsTMPArr;
                                } else {
                                    $productsCurrentArr = $productsArr;
                                }

                                //$itemArray = (array)$item;
                                foreach ($productsCurrentArr as $keyProduct => $productItem) {
                                    $productItemArray = (array)$productItem;
                                    foreach ($productItemArray as $keyProp => $propItem) {
                                        if ($keyProp === 'ЗначенияРеквизитов') {
                                            $propItemArray = (array)$propItem;
                                            foreach ($propItemArray["ЗначениеРеквизита"] as $keyReqItem => $reqItem) {
                                                $reqItemArray = (array)$reqItem;
                                                $xmlCustom[Cutil::translit($key, "ru", $langParam)][$keyItem]["products"][$keyProduct][Cutil::translit($reqItemArray["Наименование"], "ru", $langParam)] = $reqItemArray["Значение"];
                                            }
                                        } else {
                                            $xmlCustom[Cutil::translit($key, "ru", $langParam)][$keyItem]["products"][$keyProduct][Cutil::translit($keyProp, "ru", $langParam)] = $propItem;
                                        }
                                    }
                                    unset($xmlCustom[Cutil::translit($key, "ru", $langParam)][$keyItem]["products"][$keyProduct]["bazovayaedinitsa"]);
                                }
                            } else {
                                $xmlCustom[Cutil::translit($key, "ru", $langParam)][$keyItem][Cutil::translit($keyItemProp, "ru", $langParam)] = $item;
                            }
                        }
                    }
                } else {
                    file_put_contents('updateSiteLog.txt', var_export($key, true), FILE_APPEND | LOCK_EX);
                    file_put_contents('updateSiteLog.txt', var_export((array)$xmlItem, true), FILE_APPEND | LOCK_EX);
                }
            } else {
                $xmlCustom[Cutil::translit($key, "ru", $langParam)] = $xmlItem;
            }
        }

        $json = json_encode($xmlCustom, JSON_UNESCAPED_UNICODE);

        $array = json_decode($json, true);
        $array['timestamp'] = ['user' => 'TstFullRight', 'moment' => date(DATE_ATOM), 'e1cib' => false];
        if (!empty($resGet["docs"])) {
            $array["_id"] = $id;
            $array["_rev"] = $rev;
        }

        if (!empty($array["products"]) && !empty($array["nomer"])) {
            $json = json_encode($array, JSON_UNESCAPED_UNICODE);
            $resPUT = PutOrderCOUCHDB($CouchDbIp, '/' . $configExchange["doc"] . '/' . $id, $json, $user_login, $user_password);
            if ($log_success) {
                addLogToIB(
                    $json,
                    'Успех: Заказа отправлен в CouchDB',
                    false,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
        } else {
            if ($log_error) {
                addLogToIB(
                    $array,
                    'Ошибка: В обмене отсутствуют товары или ID заказа!',
                    true,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
        }
    } else {
        if ($log_error) {
            addLogToIB(
                $resExport,
                'Ошибка: Файл обмена не создан!',
                true,
                $IBLOCK_LOGGING,
                $SECTION_LOGGING
            );
        }
    }
    unlink($file);
} else {
    if ($log_error) {
        addLogToIB(
            $orderId,
            'Ошибка: В обмене отсутствуют элементы!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}