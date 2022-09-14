<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
\Bitrix\Main\Loader::includeModule('sale');

use Bitrix\Sale;

$idOrder = $_GET["ID"];

if (!empty($idOrder)) {
    $filter = $idOrder === 'all' ? [] : ["ID" => $idOrder]; //ID заказа
    $order_list = [];
    $log_file = $_SERVER["DOCUMENT_ROOT"] . "/local/orders.log";

    if (file_exists($log_file))
        unlink($log_file);

    $db_sales = CSaleOrder::GetList(["DATE_INSERT" => "ASC"], $filter);
    while ($ar_sales = $db_sales->Fetch()) {
        $order_list[] = $ar_sales["ID"];
    }

    foreach ($order_list as $id) {
        $order = Sale\Order::load($id);
        file_put_contents($log_file, "Работаем с заказом " . $id . "\n", FILE_APPEND);

        //отменяем оплаты если есть
        $paymentCollection = $order->getPaymentCollection();
        if ($paymentCollection->isPaid()) {
            file_put_contents($log_file, "Оплачен\n", FILE_APPEND);

            foreach ($paymentCollection as $payment) {
                $payment->setReturn("Y");
            }
        }

        //отменяем отгрузки если есть
        $shipmentCollection = $order->getShipmentCollection();
        if ($shipmentCollection->isShipped()) {
            file_put_contents($log_file, "Отгружен\n", FILE_APPEND);

            $shipment = $shipmentCollection->getItemById($shipmentCollection[0]->getField("ID"));
            $res = $shipment->setField("DEDUCTED", "N");
            if (!$res->isSuccess()) {
                file_put_contents($log_file, print_r($res->getErrors(), true), FILE_APPEND);
            }
        }

        $order->save();

        $res_delete = Sale\Order::delete($id);
        if (!$res_delete->isSuccess()) {
            file_put_contents($log_file, print_r($res_delete->getErrors(), true), FILE_APPEND);
        } else {
            file_put_contents($log_file, "Заказ " . $id . " удален\n", FILE_APPEND);
        }
        file_put_contents($log_file, "------------\n", FILE_APPEND);
    }
}