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

$IBLOCK_LOGGING = 19;
$SECTION_LOGGING = 21335;

$since = '';
$propertiesArrCouchDB = [];
$resultSeqProperties = [];
$propertiesArr = [];
$resultPropertiesArrCouchDB = [];
$propertiesNewArr = [];

$ibpenum = new CIBlockPropertyEnum;
$cibp = new CIBlockProperty;


?>

    <form action="" method="post">
        <input type="text" name="objref" placeholder="Введите Внешний код товара">
        <button type="submit">Запросить</button>
    </form>

<?php
if (!empty($_POST["objref"])) {
    $json = [
        "selector" =>
            [
                //"class_name" => "ireg.property_values",
                "obj" => ["ref" => $_POST["objref"]]
            ],
        "limit" => 500
    ];
    $propertiesArrCouchDB = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["prop"] . '/', '_find/', $json, $user_login, $user_password);

    $propertiesArrCouchDB = $propertiesArrCouchDB["docs"];

    if (!empty($propertiesArrCouchDB)) {

        $arSort = ['ID' => 'ASC'];
        $arFilter = ["IBLOCK_ID" => $IBLOCK_ID, "ACTIVE" => "Y"];
        $propertiesGetList = CIBlockProperty::GetList($arSort, $arFilter);
        while ($property = $propertiesGetList->GetNext()) {
            $propertiesArr[$property["XML_ID"]]["ID"] = $property["ID"];
            $propertiesArr[$property["XML_ID"]]["CODE"] = $property["CODE"];
            $propertiesArr[$property["XML_ID"]]["NAME"] = $property["NAME"];
            $propertiesArr[$property["XML_ID"]]["PROPERTY_TYPE"] = $property["PROPERTY_TYPE"];
            if ($property["PROPERTY_TYPE"] === "L") {
                $db_enum_list = CIBlockProperty::GetPropertyEnum($property["CODE"], [], ["IBLOCK_ID" => $IBLOCK_ID]);
                while ($ar_enum_list = $db_enum_list->GetNext()) {
                    $propertiesArr[$property["XML_ID"]]["VALUES"][$ar_enum_list["VALUE"]] = $ar_enum_list["ID"];
                }
            }
        }

        foreach ($propertiesArrCouchDB as $propertiesArrCouchDBItem) {
            if ($propertiesArrCouchDBItem->class_name !== "ireg.property_values") {
                continue;
            }

            $productXmlId = $propertiesArrCouchDBItem->obj->ref;
            $propertyId = $propertiesArrCouchDBItem->property->ref;
            $deleted = $propertiesArrCouchDBItem->_deleted;
            $value = is_object($propertiesArrCouchDBItem->value) ? $propertiesArrCouchDBItem->value->presentation : $propertiesArrCouchDBItem->value;

            if (!empty($propertiesArr[$propertyId])) {
                $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["ID"] = $propertiesArr[$propertyId]["ID"];
                $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["CODE"] = $propertiesArr[$propertyId]["CODE"];
                $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["PROPERTY_TYPE"] = $propertiesArr[$propertyId]["PROPERTY_TYPE"];

                $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["XML_ID"] = $propertyId;
                $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["NAME"] = $propertiesArrCouchDBItem->property->presentation;
                $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["DELETED"] = $deleted;

                if ($propertiesArr[$propertyId]["PROPERTY_TYPE"] === 'L') {
                    if (is_bool($value)) {
                        $value = $value ? "Да" : "Нет";
                    }

                    if (!empty($propertiesArr[$propertyId]["VALUES"][(string)$value])) {
                        $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["PROPERTY_VALUE"] = $propertiesArr[$propertyId]["VALUES"][(string)$value];
                    } else {
                        $propID = $ibpenum->Add(['PROPERTY_ID' => $propertiesArr[$propertyId]["ID"], 'VALUE' => $value]);

                        if ($propID) {
                            if ($log_success) {
                                addLogToIB(
                                    $propertiesArrCouchDBItem,
                                    'Успех: Добавлено новое значение свойства ' . $propertiesArrCouchDBItem->property->presentation . ': ' . $propID,
                                    false,
                                    $IBLOCK_LOGGING,
                                    $SECTION_LOGGING
                                );
                            }
                            $propertiesArr[$propertyId]["VALUES"][(string)$value] = $propID;
                            $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["PROPERTY_VALUE"] = $propID;
                        } else {
                            if ($log_error) {
                                addLogToIB(
                                    $propertiesArrCouchDBItem,
                                    (string)$ibpenum->LAST_ERROR,
                                    true,
                                    $IBLOCK_LOGGING,
                                    $SECTION_LOGGING
                                );
                            }
                            if (is_object($propertiesArrCouchDBItem->value)) {
                                $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["PROPERTY_VALUE"] = $value;
                                $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["PROPERTY_XML_ID"] = $propertiesArrCouchDBItem->value->ref;
                            } else {
                                $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["PROPERTY_VALUE"] = $value;
                            }

                            $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["NEW"] = "Y";
                        }
                    }

                } else {
                    if (is_object($propertiesArrCouchDBItem->value)) {
                        $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["PROPERTY_VALUE"] = $value;
                        $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["PROPERTY_XML_ID"] = $propertiesArrCouchDBItem->value->ref;
                    } else {
                        $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["PROPERTY_VALUE"] = $value;
                    }
                }
            } else {
                $jsonProp = ['selector' => ["_id" => 'cch.object_properties|' . $propertyId]];
                $propertiesNewArrCouchDB = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["prop"] . '/', '_find/', $jsonProp, $user_login, $user_password);
                $propertiesNewArrCouchDB = $propertiesNewArrCouchDB["docs"][0];

                $propertyName = !empty($propertiesNewArrCouchDB->name_site) ? $propertiesNewArrCouchDB->name_site : $propertiesArrCouchDBItem->property->presentation;
                $propertyCode = !empty($propertiesNewArrCouchDB->local_name_site) ? $propertiesNewArrCouchDB->local_name_site : 'PROP_' . bin2hex(random_bytes(4));
                $propertyCode = preg_replace('/[^\w-]/', '', $propertyCode);

                $arFields = [
                    "NAME" => $propertyName,
                    "XML_ID" => $propertyId,
                    "ACTIVE" => "Y",
                    "SORT" => "999999",
                    "CODE" => $propertyCode,
                    "PROPERTY_TYPE" => "L",
                    "IBLOCK_ID" => $IBLOCK_ID
                ];

                $propertyNewID = $cibp->Add($arFields);

                if ($propertyNewID) {
                    if ($log_success) {
                        addLogToIB(
                            $propertiesArrCouchDBItem,
                            'Успех: Добавлено новое свойство ' . $propertyName . ': ' . $propertyNewID,
                            false,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                    $propertiesArr[$propertyId]["ID"] = $propertyCode;
                    $propertiesArr[$propertyId]["CODE"] = $propertyCode;
                    $propertiesArr[$propertyId]["NAME"] = $propertyName;
                    $propertiesArr[$propertyId]["PROPERTY_TYPE"] = "L";

                    $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["ID"] = $propertyNewID;
                    $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["CODE"] = $propertyCode;
                    $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["PROPERTY_TYPE"] = "L";

                    $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["XML_ID"] = $propertyId;
                    $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["NAME"] = $propertiesArrCouchDBItem->property->presentation;
                    $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["DELETED"] = $deleted;

                    $propID = $ibpenum->Add(['PROPERTY_ID' => $propertyNewID, 'VALUE' => $value]);

                    if ($propID) {
                        if ($log_success) {
                            addLogToIB(
                                $propertiesArrCouchDBItem,
                                'Успех: Добавлено новое значение свойства ' . $propertyName . ': ' . $propID,
                                false,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                        $propertiesArr[$propertyId]["VALUES"][(string)$value] = $propID;
                        $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["PROPERTY_VALUE"] = $propID;
                    } else {
                        if ($log_error) {
                            addLogToIB(
                                $propertiesArrCouchDBItem,
                                (string)$ibpenum->LAST_ERROR,
                                true,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                        if (is_object($propertiesArrCouchDBItem->value)) {
                            $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["PROPERTY_VALUE"] = $value;
                            $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["PROPERTY_XML_ID"] = $propertiesArrCouchDBItem->value->ref;
                        } else {
                            $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["PROPERTY_VALUE"] = $value;
                        }

                        $resultPropertiesArrCouchDB[$productXmlId][$propertyId]["NEW"] = "Y";
                    }
                } else {
                    if ($log_error) {
                        addLogToIB(
                            $propertiesArrCouchDBItem,
                            (string)$cibp->LAST_ERROR,
                            true,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }
                }
            }
        }

        $siteProductGetList = CIBlockElement::GetList(["ID" => 'asc'], ["IBLOCK_ID" => $IBLOCK_ID, "SHOW_HISTORY" => 'Y'], false, false, ["ID", "XML_ID", "NAME", "PROPERTY_CML2_ARTICLE"]);
        while ($siteProductGetListItem = $siteProductGetList->GetNext()) {
            if (!empty($resultPropertiesArrCouchDB[$siteProductGetListItem["XML_ID"]])) {
                foreach ($resultPropertiesArrCouchDB[$siteProductGetListItem["XML_ID"]] as $property) {
                    if (!empty($property["DELETED"]) && $property["DELETED"]) {
                        CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, [$property["CODE"] => false]);
                    } else {
                        if ($property["NEW"] === 'Y') {
                            $propertyGetList = CIBlockProperty::GetList([], ['IBLOCK_ID' => $IBLOCK_ID, 'CODE' => $property["CODE"]])->Fetch();

                            $propID = $ibpenum->Add(['PROPERTY_ID' => $propertyGetList['ID'], 'VALUE' => $property["PROPERTY_VALUE"]]);

                            if ($propID) {
                                if ($log_success) {
                                    addLogToIB(
                                        $property,
                                        'Успех: Добавлено новое значение свойства ' . $property["NAME"] . ': ' . $propID,
                                        false,
                                        $IBLOCK_LOGGING,
                                        $SECTION_LOGGING
                                    );
                                }
                                CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, [$property["CODE"] => $propID]);
                            } else {
                                if ($log_error) {
                                    addLogToIB(
                                        $property,
                                        (string)$ibpenum->LAST_ERROR,
                                        true,
                                        $IBLOCK_LOGGING,
                                        $SECTION_LOGGING
                                    );
                                }
                            }
                        } else {
                            CIBlockElement::SetPropertyValuesEx($siteProductGetListItem["ID"], $IBLOCK_ID, [$property["CODE"] => $property["PROPERTY_VALUE"]]);
                        }
                    }

                }
            }
        }

    } else {
        if ($log_error) {
            addLogToIB(
                $propertiesArrCouchDB,
                'Ошибка: В обмене отсутствуют элементы!',
                true,
                $IBLOCK_LOGGING,
                $SECTION_LOGGING
            );
        }
    }
}