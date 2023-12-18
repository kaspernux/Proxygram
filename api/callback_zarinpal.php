<?php

if (!isset($_GET['code'], $_GET['price'], $_GET['from_id'])) die(json_encode(['status' => false, 'msg' => 'Не были отправлены обязательные параметры!', 'code' => 404], 448));

include_once '../config.php';

$factor = $sql->query("SELECT * FROM `factors` WHERE `code` = '{$_GET['code']}'");
$setting = $sql->query("SELECT `zarinpal_token` FROM `payment_setting`")->fetch_assoc();

if ($_GET['Status'] != 'NOK') {
    if ($factor->num_rows > 0) {
        $factor = $factor->fetch_assoc();
        if ($factor['status'] == 'no') {
            if (checkZarinpalFactor($setting['zarinpal_token'], $_GET['Authority'], $_GET['price'])) {
                $sql->query("UPDATE `factors` SET `status` = 'yes' WHERE `code` = '{$_GET['code']}'");
                $sql->query("UPDATE `users` SET `coin` = coin + {$_GET['price']}, `count_charge` = count_charge + 1 WHERE `from_id` = '{$_GET['from_id']}'");
                sendMessage($_GET['from_id'], "🎯 Ваш платеж успешно выполнен, и ваш счет успешно пополнен.\n\n◽Сумма платежа: <code>{$_GET['price']}</code>\n◽ID пользователя: <code>{$_GET['from_id']}</code>");
                sendMessage($config['dev'], "🤖 Новый пользователь пополнил свой счет!\n\n◽ID пользователя: <code>{$_GET['from_id']}</code>\n◽Сумма пополнения: <code>{$_GET['price']}</code>");
                print '<h2 style="text-align: center; color: black; font-size: 40px; margin-top: 60px;">Ваш счет успешно подтвержден, и ваш аккаунт успешно пополнен ✅</h2>';
            } else {
                print '<h2 style="text-align: center; color: black; font-size: 40px; margin-top: 60px;">Счет не оплачен ❌</h2>';
            }
        } else {
            print '<h2 style="text-align: center; color: black; font-size: 40px; margin-top: 60px;">Этот счет уже зарегистрирован в системе ❌</h2>';
        }
    } else {
        print '<h2 style="text-align: center; color: black; font-size: 40px; margin-top: 60px;">Счет с этими параметрами не найден ❌</h2>';
    }
} else {
    print '<h2 style="text-align: center; color: black; font-size: 40px; margin-top: 60px;">Счет не оплачен ❌</h2>';
}

?>
