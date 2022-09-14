<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
require_once $_SERVER["DOCUMENT_ROOT"] . "/local/exchange/config.php";
require_once $_SERVER["DOCUMENT_ROOT"] . "/local/Classes/phpqrcode/qrlib.php";

/** @var array $configExchange */
/** @var $user_login */
/** @var $user_password */
/** @var $CouchDbIp */
/** @var $IBLOCK_ID */
/** @var $log_success */
/** @var $log_error */

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Sale;
use Bitrix\Sale\Delivery;
use Bitrix\Sale\DiscountCouponsManager;
use Bitrix\Sale\Order;
use Bitrix\Sale\PaySystem;

CModule::IncludeModule("sale");
CModule::IncludeModule("catalog");
CModule::IncludeModule("iblock");

$step = !empty($_GET["step"]) ? (integer)$_GET["step"] : 1;
$command = !empty($_GET["command"]) ? $_GET["command"] : 'change';
$limit = 500;
$skip = ($step - 1) * $limit;

$IBLOCK_LOGGING = 19;
$SECTION_LOGGING = 21330;

$orderArray = [];
$resultSeqOrder = [];
$siteProductArr = [];

$statusArr = [
    "N" => "N",
    "P" => "P",
    "F" => "F",
    "DN" => "ND",
    "DA" => "AD",
    "DG" => "GD",
    "DT" => "TD",
    "DS" => "SD",
    "DF" => "FD",
];

$json = ["selector" => ["class_name" => "doc.buyers_order_www", "timestamp" => ["e1cib" => true]], "limit" => $limit];

$since = file_get_contents("oderSeq.txt");
$keySince = !empty($since) ? "&since=" . $since : "";

$resultSeqOrder = GetDataCOUCHDB($CouchDbIp, '/' . $configExchange["doc"] . '/', "_changes?filter=_selector&include_docs=true" . $keySince, $json, $user_login, $user_password);
if ($since !== $resultSeqOrder["last_seq"] && !empty($resultSeqOrder['last_seq'])) {
    foreach ($resultSeqOrder["results"] as $resultSeqOrderItem) {
        $orderArray[] = $resultSeqOrderItem->doc;
    }
    file_put_contents("oderSeq.txt", $resultSeqOrder["last_seq"]);
}

if (!empty($orderArray)) {

    $arSort = ["ID" => "ASC"];
    $arFilter = ["IBLOCK_ID" => $IBLOCK_ID, "SHOW_HISTORY" => "Y"];
    $arSelect = ["ID", "XML_ID"];
    $siteProductGetList = CIBlockElement::GetList($arSort, $arFilter, false, false, $arSelect);
    while ($siteProductGetListItem = $siteProductGetList->GetNext()) {
        $siteProductArr[$siteProductGetListItem["XML_ID"]] = $siteProductGetListItem["ID"];
    }

    foreach ($orderArray as $key => $orderItem) {
        $productsOrigin = [];
        $productsUpdate = [];

        $deliveryArr = [];
        $deliveryPrice = 0;
        $delta = 0;
        $metodOplatyArr = [];
        $orderNewPriceArr = [];

        $korrektirovkaSum = 0;
        $korrektirovkaDelta = 0;

        $filenameQRCode = '';
        $hex = '';
        $filenameProductSklad = '';

        $orderId = $orderItem->nomer;
        $podchinennyedokumenty = $orderItem->podchinennyedokumenty;
        $orderStatus = $orderItem->statusa_zakaza_id;
        $products = $orderItem->products;
        $orderQRCode = $orderItem->qrcode;
        $orderProductSklad = $orderItem->productskladforqrcode;
        $orderCancel = $orderItem->otmenen;
        $orderCancelBool = filter_var($orderItem->otmenen, FILTER_VALIDATE_BOOLEAN);
        $orderCancelString = (string)$orderCancel;
        $orderCancelFlag = !empty($orderCancelString);

        $orderGetByID = CSaleOrder::GetByID($orderId);
        $orderStatusGetByID = $orderGetByID["STATUS_ID"];
        $PERSON_TYPE_ID = $orderGetByID["PERSON_TYPE_ID"];
        $QRCODE_ID = $PERSON_TYPE_ID === "2" ? 40 : 39;
        $PRODUCTSKLADFORQRCODE_ID = $PERSON_TYPE_ID === "2" ? 44 : 43;

        $order = Sale\Order::load($orderId);

        if (!empty($order)) {
            if (!empty($podchinennyedokumenty)) {
                foreach ($podchinennyedokumenty as $podchinennyedokumentyItem) {
                    if ($podchinennyedokumentyItem->khozoperatsiya === "Отпуск товара") {
                        $shipmentIdOrder = (int)$podchinennyedokumentyItem->id;
                        $metodDostavkiId = (int)$podchinennyedokumentyItem->metod_dostavki_id;
                        $products = $podchinennyedokumentyItem->products;
                    } else {
                        $metodOplatyArr[] = $podchinennyedokumentyItem;
                    }
                }
            } else {
                if ($log_error) {
                    addLogToIB(
                        $orderItem,
                        'Ошибка: Отсутствуют подчиненные документы!',
                        true,
                        $IBLOCK_LOGGING,
                        $SECTION_LOGGING
                    );
                }
            }

            if ($orderCancelBool && $orderCancelFlag && $orderGetByID["CANCELED"] === "N") {
                $paymentCollection = $order->getPaymentCollection();
                foreach ($paymentCollection as $payment) {
                    if ($payment->getField("PAID") === "Y") {
                        $payment->setReturn("Y");
                    }
                }

                $order->setField("CANCELED", "Y");
                $order->setField("REASON_CANCELED", "Заказ " . $orderId . " отменен");
                if ($log_success) {
                    addLogToIB(
                        $orderItem,
                        'Успех: Отменен заказ с ID: ' . $orderId,
                        false,
                        $IBLOCK_LOGGING,
                        $SECTION_LOGGING
                    );
                }
            } elseif (!$orderCancelBool && $orderCancelFlag && $orderGetByID["CANCELED"] === "Y") {
                $order->setField("CANCELED", "N");
                $order->setField("REASON_CANCELED", "Заказ " . $orderId . " передумали отменять");
                if ($log_success) {
                    addLogToIB(
                        $orderItem,
                        'Успех: Отменена отмена заказа с ID: ' . $orderId,
                        false,
                        $IBLOCK_LOGGING,
                        $SECTION_LOGGING
                    );
                }
            }

            for ($i = 1; $i <= 3; $i++) {
                $bytes = random_bytes(4);
                $hex .= bin2hex($bytes) . '-';
            }

            if (!empty($orderQRCode)) {
                $filenameQRCode = "/upload/order_qrcode/order_" . $hex . '_' . $orderId . ".png";
                QRcode::png($orderQRCode, $_SERVER["DOCUMENT_ROOT"] . $filenameQRCode, "M", 6, 2);

                $propsQRCodeGetList = CSaleOrderPropsValue::GetList("ID", "ASC", ["ORDER_ID" => $orderId, "ORDER_PROPS_ID" => $QRCODE_ID]);
                if ($propsQRCodeGetListItem = $propsQRCodeGetList->Fetch()) {
                    CSaleOrderPropsValue::Update($propsQRCodeGetListItem["ID"], ["VALUE" => $filenameQRCode]);
                } else {
                    CSaleOrderPropsValue::Add([
                        "ORDER_ID" => $orderId,
                        "ORDER_PROPS_ID" => $QRCODE_ID,
                        "NAME" => "QR-код счета",
                        "CODE" => "QRCODE",
                        "VALUE" => $filenameQRCode,
                    ]);
                }
                if ($log_success) {
                    addLogToIB(
                        $orderItem,
                        'Успех: Добавлен QR-код для заказа с ID: ' . $orderId,
                        false,
                        $IBLOCK_LOGGING,
                        $SECTION_LOGGING
                    );
                }
            }

            if (!empty($orderProductSklad)) {
                $filenameProductSklad = "/upload/order_qrcode/order_" . $hex . '_' . $orderId . ".txt";
                file_put_contents($_SERVER["DOCUMENT_ROOT"] . $filenameProductSklad, $orderProductSklad);

                $propsProductSkladGetList = CSaleOrderPropsValue::GetList("ID", "ASC", ["ORDER_ID" => $orderId, "ORDER_PROPS_ID" => $PRODUCTSKLADFORQRCODE_ID]);
                if ($propsProductSkladGetListItem = $propsProductSkladGetList->Fetch()) {
                    CSaleOrderPropsValue::Update($propsProductSkladGetListItem["ID"], [
                        'NAME' => $propsProductSkladGetListItem['NAME'],
                        'CODE' => $propsProductSkladGetListItem['CODE'],
                        'ORDER_PROPS_ID' => $propsProductSkladGetListItem['ORDER_PROPS_ID'],
                        'ORDER_ID' => $propsProductSkladGetListItem['ORDER_ID'],
                        "VALUE" => $filenameProductSklad
                    ]);
                } else {
                    CSaleOrderPropsValue::Add([
                        "ORDER_ID" => $orderId,
                        "ORDER_PROPS_ID" => $PRODUCTSKLADFORQRCODE_ID,
                        "NAME" => "Информация для пользователя по получению товара",
                        "CODE" => "PRODUCTSKLADFORQRCODE",
                        "VALUE" => $filenameProductSklad,
                    ]);
                }
                if ($log_success) {
                    addLogToIB(
                        $orderItem,
                        'Успех: Добавлены Склады для заказа с ID: ' . $orderId,
                        false,
                        $IBLOCK_LOGGING,
                        $SECTION_LOGGING
                    );
                }
            }

            if (!empty($orderStatus) && $orderStatusGetByID !== $orderStatus && !empty($statusArr[$orderStatus]) && !empty($shipmentIdOrder)) {
                $order->setField("STATUS_ID", $statusArr[$orderStatus]);

                $shipmentCollection = $order->getShipmentCollection();
                foreach ($shipmentCollection as $shipment) {
                    $shipmentId = $shipment->getId();
                    if ($shipment->isSystem() && $shipmentId !== $shipmentIdOrder) {
                        continue;
                    }
                    $shipment->setField("STATUS_ID", $orderStatus);
                }
                if ($log_success) {
                    addLogToIB(
                        $orderItem,
                        'Успех: Обновлен статус заказа с ID: ' . $orderId,
                        false,
                        $IBLOCK_LOGGING,
                        $SECTION_LOGGING
                    );
                }
            }

            if (!empty($products) && !empty($shipmentIdOrder)) {
                $props = [];

                foreach ($products as $productsItem) {
                    $productId = !empty($siteProductArr[$productsItem->id]) ? $siteProductArr[$productsItem->id] : $productsItem->id;
                    $productsUpdate[$productId]["ID"] = $productId;
                    $productsUpdate[$productId]["NAME"] = $productsItem->naimenovanie;
                    $productsUpdate[$productId]["QUANTITY"] = !empty($productsUpdate[$productId]["QUANTITY"]) ? $productsUpdate[$productId]["QUANTITY"] + (int)$productsItem->kolichestvo : (int)$productsItem->kolichestvo;
                    if (!empty($productsItem->svoystvokorziny_discount_price) && (float)$productsItem->svoystvokorziny_discount_price <= (float)$productsItem->tsenazaedinitsu) {
                        $productsUpdate[$productId]["PRICE"] = (float)$productsItem->svoystvokorziny_discount_price;
                    } else {
                        $productsUpdate[$productId]["PRICE"] = (float)$productsItem->tsenazaedinitsu;
                    }
                    $productSum = $productsItem->summa;
                    $productsUpdate[$productId]["SUM"] = !empty($productsUpdate[$productId]["SUM"]) ? (float)$productsUpdate[$productId]["SUM"] + (float)$productSum : (float)$productSum;
                    if ($productId !== "ORDER_DELIVERY") {
                        $orderNewPriceArr[$productId] = $productsUpdate[$productId]["SUM"];
                        if (!empty($productsItem->svoystvokorziny_bl_quality)) {
                            $props[$productId]["BL_QUALITY"] = ["NAME" => "Качество", "CODE" => "BL_QUALITY", "VALUE" => $productsItem->svoystvokorziny_bl_quality];
                        }
                        if (!empty($productsItem->svoystvokorziny_current_price)) {
                            $props[$productId]["CURRENT_PRICE"] = ["NAME" => "Текущая Цена", "CODE" => "CURRENT_PRICE", "VALUE" => $productsItem->svoystvokorziny_current_price];
                        }

                        if (!empty($productsItem->svoystvokorziny_discount_price) && (float)$productsItem->svoystvokorziny_discount_price <= (float)$productsItem->tsenazaedinitsu) {
                            $props[$productId]["DISCOUNT_PRICE"] = ["NAME" => "Цена со скидкой", "CODE" => "DISCOUNT_PRICE", "VALUE" => $productsItem->svoystvokorziny_discount_price];
                        } else {
                            $props[$productId]["DISCOUNT_PRICE"] = ["NAME" => "Цена со скидкой", "CODE" => "DISCOUNT_PRICE", "VALUE" => $productsItem->tsenazaedinitsu];
                        }

                        if (!empty($productsItem->svoystvokorziny_discount_value)) {
                            $props[$productId]["DISCOUNT_VALUE"] = ["NAME" => "Скидка", "CODE" => "DISCOUNT_VALUE", "VALUE" => $productsItem->svoystvokorziny_discount_value];
                        }

                        if (!empty($productSum)) {
                            $props[$productId]["POSITION_SUM"] = ["NAME" => "Сумма позиции", "CODE" => "POSITION_SUM", "VALUE" => $productsUpdate[$productId]["SUM"]];
                        }
                    }
                }

                $deliveryArr = $productsUpdate["ORDER_DELIVERY"];

                $deliveryPrice = !empty($deliveryArr["PRICE"]) ? $deliveryArr["PRICE"] : $order->getDeliveryPrice();

                $orderNewPrice = array_sum($orderNewPriceArr) + (float)$deliveryPrice;
                unset($productsUpdate["ORDER_DELIVERY"]);

                $basket = $order->getBasket();

                foreach ($basket as $basketItem) {
                    $productId = $basketItem->getField("PRODUCT_ID");
                    $productsOrigin[$productId]["ID"] = $basketItem->getField("PRODUCT_ID");
                    $productsOrigin[$productId]["NAME"] = $basketItem->getField("NAME");
                    $productsOrigin[$productId]["QUANTITY"] = !empty($productsOrigin[$productId]["QUANTITY"]) ? $productsOrigin[$productId]["QUANTITY"] + (int)$basketItem->getField("QUANTITY") : (int)$basketItem->getField("QUANTITY");
                    $productsOrigin[$productId]["PRICE"] = $basketItem->getField("PRICE");
                }

                $flag = false;

                $productsMergeTMP = array_merge($productsOrigin, $productsUpdate);
                $productsUniqueTMP = array_unique($productsMergeTMP);
                if (!empty($productsUniqueTMP) && count($productsUpdate) !== count($productsUniqueTMP)) {
                    $flag = true;
                }

                if (($flag || empty($productsOrigin)) && !empty($productsUpdate)) {
                    $paymentCollection = $order->getPaymentCollection();

                    $paymentCollectionSum = 0;
                    foreach ($paymentCollection as $payment) {
                        $paymentSystemId = $payment->getField("PAY_SYSTEM_ID");
                        $paymentSystemPaid = $payment->getField("PAID");
                        $paymentSystemSum = $payment->getField("SUM");

                        if ($paymentSystemPaid === "Y" && $paymentSystemId !== "10") {
                            $paymentCollectionSum += $paymentSystemSum;
                        } elseif ($paymentSystemId === "10") {
                            $korrektirovkaSum += $paymentSystemSum;
                        } elseif ($paymentSystemPaid === "N" && $paymentSystemId === "8") {
                            $payment->delete();
                        } elseif ($paymentSystemPaid === "N" && $paymentSystemId === "9") {
                            $payment->delete();
                        }
                    }

                    if ($orderNewPrice > $paymentCollectionSum) {
                        $delta = $orderNewPrice - $paymentCollectionSum;
                    }

                    if (!empty($productsOrigin)) {
                        foreach ($basket as $basketItem) {
                            $basketItemID = $basketItem->getField("ID");
                            $itemBasketGetItemById = $basket->getItemById($basketItemID);
                            $deleteBasketItem = $itemBasketGetItemById->delete();
                        }
                    }
                    foreach ($productsUpdate as $productsUpdateItem) {
                        $item = $basket->createItem("catalog", $productsUpdateItem["ID"]);
                        $item->setFields([
                            "CURRENCY" => \Bitrix\Currency\CurrencyManager::getBaseCurrency(),
                            "LID" => \Bitrix\Main\Context::getCurrent()->getSite(),
                            "PRODUCT_PROVIDER_CLASS" => "CCatalogProductProvider",
                            "QUANTITY" => (int)$productsUpdateItem["QUANTITY"],
                            "PRICE" => $productsUpdateItem["PRICE"],
                            "CUSTOM_PRICE" => "Y"
                        ]);
                        $newBasketItems[] = $item;
                    }

                    $shipmentCollection = $order->getShipmentCollection();
                    foreach ($shipmentCollection as $shipment) {
                        $shipmentId = $shipment->getId();
                        if ($shipment->isSystem()) {
                            continue;
                        }
                        if ($shipmentId === $shipmentIdOrder) {
                            $shipmentItemCollection = $shipment->getShipmentItemCollection();
                            foreach ($newBasketItems as $item) {
                                $shipmentItem = $shipmentItemCollection->createItem($item);
                                $shipmentItem->setQuantity($item->getQuantity());
                            }

                            $service = \Bitrix\Sale\Delivery\Services\Manager::getById($metodDostavkiId);
                            $deliveryData = [
                                'DELIVERY_ID' => $service['ID'],
                                'DELIVERY_NAME' => $service['NAME'],
                                'ALLOW_DELIVERY' => 'Y',
                                'PRICE_DELIVERY' => $deliveryPrice,
                                'CUSTOM_PRICE_DELIVERY' => 'Y'
                            ];
                            $shipment->setFields($deliveryData);
                            break;
                        }
                    }

                    $paymentCollection = $order->getPaymentCollection();
                    $flagAdjustment = true;
                    if (!empty($delta) && $delta > 0) {
                        if (count($metodOplatyArr) === 1) {
                            $metod_oplaty_id = $metodOplatyArr[0]->metod_oplaty_id;

                            $paymentAdjustment = $paymentCollection->createItem();
                            $paySystemServiceAdjustment = PaySystem\Manager::getObjectById($metod_oplaty_id);
                            $paymentAdjustment->setFields([
                                "PAY_SYSTEM_ID" => $paySystemServiceAdjustment->getField("PAY_SYSTEM_ID"),
                                "PAY_SYSTEM_NAME" => $paySystemServiceAdjustment->getField("NAME"),
                                "SUM" => $delta,
                                "CURRENCY" => $order->getCurrency(),
                            ]);
                            $flagAdjustment = false;
                        } else {
                            foreach ($metodOplatyArr as $metodOplatyArrItem) {
                                if ($metodOplatyArrItem->metod_oplaty_id === "11") {
                                    $paymentAdjustment = $paymentCollection->createItem();
                                    $paySystemServiceAdjustment = PaySystem\Manager::getObjectById($metodOplatyArrItem->metod_oplaty_id);
                                    $paymentAdjustment->setFields([
                                        "PAY_SYSTEM_ID" => $paySystemServiceAdjustment->getField("PAY_SYSTEM_ID"),
                                        "PAY_SYSTEM_NAME" => $paySystemServiceAdjustment->getField("NAME"),
                                        "SUM" => $metodOplatyArrItem->summa,
                                        "CURRENCY" => $order->getCurrency(),
                                        "PAID" => $metodOplatyArrItem->oplacheno ? "Y" : "N"
                                    ]);
                                    $flagAdjustment = false;
                                } elseif ($metodOplatyArrItem->metod_oplaty_id !== "11" && !$metodOplatyArrItem->oplacheno) {
                                    $paymentAdjustment = $paymentCollection->createItem();
                                    $paySystemServiceAdjustment = PaySystem\Manager::getObjectById($metodOplatyArrItem->metod_oplaty_id);
                                    $paymentAdjustment->setFields([
                                        "PAY_SYSTEM_ID" => $paySystemServiceAdjustment->getField("PAY_SYSTEM_ID"),
                                        "PAY_SYSTEM_NAME" => $paySystemServiceAdjustment->getField("NAME"),
                                        "SUM" => $metodOplatyArrItem->summa,
                                        "CURRENCY" => $order->getCurrency(),
                                    ]);
                                    $flagAdjustment = false;
                                }
                            }
                        }

                        if ($flagAdjustment) {
                            $metod_oplaty_id = $metodOplatyArr[1]->metod_oplaty_id;
                            $paymentAdjustment = $paymentCollection->createItem();
                            $paySystemServiceAdjustment = PaySystem\Manager::getObjectById($metod_oplaty_id);
                            $paymentAdjustment->setFields([
                                "PAY_SYSTEM_ID" => $paySystemServiceAdjustment->getField("PAY_SYSTEM_ID"),
                                "PAY_SYSTEM_NAME" => $paySystemServiceAdjustment->getField("NAME"),
                                "SUM" => $delta,
                                "CURRENCY" => $order->getCurrency(),
                            ]);
                        }
                    }

                    $basket->refreshData();
                    $basket->save();

                    foreach ($basket as $item) {
                        $basketProductID = $item->getField("PRODUCT_ID");
                        $basketPropertyCollection = $item->getPropertyCollection();
                        $basketPropertyCollection->setProperty($props[$basketProductID]);
                        $basketPropertyCollection->save();
                    }
                    $order->refreshData();
                    if ($log_success) {
                        addLogToIB(
                            $orderItem,
                            'Успех: Обновлены товары заказа с ID: ' . $orderId,
                            false,
                            $IBLOCK_LOGGING,
                            $SECTION_LOGGING
                        );
                    }

                    $korrektirovkaDelta = round($order->getPrice() - $order->getDeliveryPrice() + (float)$deliveryPrice - $orderNewPrice - $korrektirovkaSum, 2);
                    if ($korrektirovkaDelta > 0) {
                        $paymentKorrektirovka = $paymentCollection->createItem();
                        $paySystemServiceKorrektirovka = PaySystem\Manager::getObjectById(10);
                        $paymentKorrektirovka->setFields([
                            "PAY_SYSTEM_ID" => $paySystemServiceKorrektirovka->getField("PAY_SYSTEM_ID"),
                            "PAY_SYSTEM_NAME" => $paySystemServiceKorrektirovka->getField("NAME"),
                            "SUM" => $korrektirovkaDelta,
                            "CURRENCY" => $order->getCurrency(),
                            "PAID" => "Y"
                        ]);
                        if ($log_success) {
                            addLogToIB(
                                $orderItem,
                                'Успех: Создана корректировка для заказа с ID: ' . $orderId,
                                false,
                                $IBLOCK_LOGGING,
                                $SECTION_LOGGING
                            );
                        }
                    }
                }
            }
            $order->save();
        } else {
            if ($log_error) {
                addLogToIB(
                    $orderItem,
                    'Ошибка: Заказ не сушествует!',
                    true,
                    $IBLOCK_LOGGING,
                    $SECTION_LOGGING
                );
            }
        }
    }

} /*else {
    if ($log_error) {
        addLogToIB(
            $orderArray,
            'Ошибка: В обмене отсутствуют элементы!',
            true,
            $IBLOCK_LOGGING,
            $SECTION_LOGGING
        );
    }
}*/