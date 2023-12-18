<?php

# -- #
/**
* Название проекта: Proxygram
* Канал: @Proxygram
* Группа: @ProxygramHUB
 * Версия: 2.5
**/

include_once 'config.php';
include_once 'api/sanayi.php';
# include_once  'api/hiddify.php';


if ($data == 'присоединиться') {
    if (isJoin($from_id)){
        deleteMessage($from_id, $message_id);
        sendMessage($from_id, $texts['success_joined'], $start_key);
    } else {
        alert($texts['not_join']);
    }
}

elseif(isJoin($from_id) == false){
    joinSend($from_id);
}

elseif($user['status'] == 'неактивен' and $from_id != $config['dev']){
    sendMessage($from_id, $texts['block']);
}

elseif ($text == '/start' or $text == '🔙 Назад' or $text == '/back') {
    step('none');
    sendMessage($from_id, sprintf($texts['start'], $first_name), $start_key);
}

elseif ($text == '❌  Отмена' and $user['step'] == 'подтвердить_сервис') {
    step('none');
    foreach ([$from_id . '-location.txt', $from_id . '-protocol.txt'] as $file) if (file_exists($file)) unlink($file);
    if($sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->num_rows > 0) $sql->query("DELETE FROM `service_factors` WHERE `from_id` = '$from_id'");
    sendMessage($from_id, sprintf($texts['start'], $first_name), $start_key);
}

elseif ($text == '🛒 Купить сервис') {
    $servers = $sql->query("SELECT * FROM `panels` WHERE `status` = 'активен'");
    if ($servers->num_rows > 0) {
        step('buy_service');
        if ($sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->num_rows > 0) $sql->query("DELETE FROM `service_factors` WHERE `from_id` = '$from_id'");
        while ($row = $servers->fetch_assoc()) {
            $location[] = ['text' => $row['name']];
        }
        $location = array_chunk($location, 2);
        $location[] = [['text' => '🔙 Назад']];
        $location = json_encode(['keyboard' => $location, 'resize_keyboard' => true]);
        sendMessage($from_id, $texts['select_location'], $location);
    } else {
        sendmessage($from_id, $texts['inactive_buy_service'], $start_key);
    }
}

elseif ($user['step'] == 'buy_service') {
    $response = $sql->query("SELECT `name` FROM `panels` WHERE `name` = '$text'");
    if ($response->num_rows == 0) {
        step('none');
        sendMessage($from_id, $texts['choice_error']);
    } else {
        step('select_plan');
        $plans = $sql->query("SELECT * FROM `category` WHERE `status` = 'активен'");
        while ($row = $plans->fetch_assoc()) {
            $plan[] = ['text' => $row['name']];
        }
        $plan = array_chunk($plan, 2);
        $plan[] = [['text' => '🔙 Назад']];
        $plan = json_encode(['keyboard' => $plan, 'resize_keyboard' => true]);
        file_put_contents("$from_id-location.txt", $text);
        sendMessage($from_id, $texts['select_plan'], $plan);
    }
}

elseif ($user['step'] == 'select_plan') {
    $response = $sql->query("SELECT `name` FROM `category` WHERE `name` = '$text'")->num_rows;
    if ($response > 0) {
        step('confirm_service');
        sendMessage($from_id, $texts['create_factor'], $confirm_service);
        $location = file_get_contents("$from_id-location.txt");
        $plan = $text;
        $code = rand(111111, 999999);

        $fetch = $sql->query("SELECT * FROM `category` WHERE `name` = '$text'")->fetch_assoc();
        $price = $fetch['price'] ?? 0;
        $limit = $fetch['limit'] ?? 0;
        $date = $fetch['date'] ?? 0;

        $sql->query("INSERT INTO `service_factors` (`from_id`, `location`, `protocol`, `plan`, `price`, `code`, `status`) VALUES ('$from_id', '$location', 'null', '$plan', '$price', '$code', 'активен')");
        $copen_key = json_encode(['inline_keyboard' => [[['text' => '🎁 Промокод', 'callback_data' => 'use_copen-'.$code]]]]);
        sendMessage($from_id, sprintf($texts['service_factor'], $location, $limit, $date, $code, number_format($price)), $copen_key);
    } else {
        sendMessage($from_id, $texts['choice_error']);
    }
}

elseif ($data == 'cancel_copen') {
    step('confirm_service');
    deleteMessage($from_id, $message_id);
}

elseif (strpos($data, 'use_copen') !== false and $user['step'] == 'confirm_service') {
    $code = explode('-', $data)[1];
    step('send_copen-'.$code);
    sendMessage($from_id, $texts['send_copen'], $cancel_copen);
}

elseif (strpos($user['step'], 'send_copen-') !== false) {
    $code = explode('-', $user['step'])[1];
    $copen = $sql->query("SELECT * FROM `copens` WHERE `copen` = '$text'");
    $service = $sql->query("SELECT * FROM `service_factors` WHERE `code` = '$code'")->fetch_assoc();
    if ($copen->num_rows > 0) {
        $copen = $copen->fetch_assoc();
        if ($copen['status'] == 'активен') {
            if ($copen['count_use'] > 0) {
                step('confirm_service');
                $price =  $service['price'] * (intval($copen['percent']) / 100);
                $sql->query("UPDATE `service_factors` SET `price` = price - $price WHERE `code` = '$code'");
                sendMessage($from_id, sprintf($texts['success_copen'], $copen['percent']), $confirm_service);
            } else {
                sendMessage($from_id, $texts['copen_full'], $cancel_copen);
            }
        } else {
            sendMessage($from_id, $texts['copen_error'], $cancel_copen);
        }
    } else {
        sendMessage($from_id, $texts['copen_error'], $cancel_copen);
    }
}

elseif($user['step'] == 'confirm_service' and $text == '☑️ Создать сервис'){
    step('none');
    sendMessage($from_id, $texts['create_service_proccess']);
    # ---------------- удалить лишние файлы ---------------- #
    foreach ([$from_id . '-location.txt', $from_id . '-protocol.txt'] as $file) if (file_exists($file)) unlink($file);
    # ---------------- получить всю информацию для создания сервиса ---------------- #
    $select_service = $sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->fetch_assoc();
    $location = $select_service['location'];
    $plan = $select_service['plan'];
    $price = $select_service['price'];
    $code = $select_service['code'];
    $status = $select_service['status'];
    $name = base64_encode($code) . '_' . $from_id;
    $get_plan = $sql->query("SELECT * FROM `category` WHERE `name` = '$plan'");
    $get_plan_fetch = $get_plan->fetch_assoc();
    $date = $get_plan_fetch['date'] ?? 0;
    $limit = $get_plan_fetch['limit'] ?? 0;
    $info_panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '$location'");
    $panel = $info_panel->fetch_assoc();
    # ---------------- проверить монеты для создания сервиса ---------------- #
    if ($user['coin'] < $select_service['price']) {
        sendMessage($from_id, sprintf($texts['not_coin'], number_format($price)), $start_key);
        exit();
    }
    # ---------------- проверить базу данных ----------------#
    if ($get_plan->num_rows == 0) {
        sendmessage($from_id, sprintf($texts['create_error'], 0), $start_key);
        exit();
    }
    # ---------------- процесс создания сервиса ---------------- #
    if ($panel['type'] == 'marzban') {
        # ---------------- настроить прокси и входящие процессы для панели marzban ---------------- #
        $protocols = explode('|', $panel['protocols']);
        unset($protocols[count($protocols)-1]);
        if ($protocols[0] == '') unset($protocols[0]);
        $proxies = array();
        foreach ($protocols as $protocol) {
            if ($protocol == 'vless' and $panel['flow'] == 'flowon'){
                $proxies[$protocol] = array('flow' => 'xtls-rprx-vision');
            } else {
                $proxies[$protocol] = array();
            }
        }
        sendMessage($from_id, json_encode($protocols, 448));
        sendMessage($from_id, json_encode($proxies, 448));
        $panel_inbounds = $sql->query("SELECT * FROM `marzban_inbounds` WHERE `panel` = '{$panel['code']}'");
        $inbounds = array();
        foreach ($protocols as $protocol) {
            while ($row = $panel_inbounds->fetch_assoc()) {
                $inbounds[$protocol][] = $row['inbound'];
            }
        }
        sendMessage($from_id, json_encode($inbounds, 448));
        # ---------------- создать сервис ---------------- #
        $token = loginPanel($panel['login_link'], $panel['username'], $panel['password'])['access_token'];
        $create_service = createService($name, convertToBytes($limit.'GB'), strtotime("+ $date day"), $proxies, ($panel_inbounds->num_rows > 0) ? $inbounds : 'null', $token, $panel['login_link']);
        $create_status = json_decode($create_service, true);
        # ---------------- проверить ошибки ---------------- #
        if (!isset($create_status['username'])) {
            sendMessage($from_id, sprintf($texts['create_error'], 1), $start_key);
            exit();
        }
        # ---------------- получить ссылки и subscription_url для отправки пользователю ---------------- #
        $links = "";
        foreach ($create_status['links'] as $link) $links .= $link . "\n\n";
        
        if ($info_panel->num_rows > 0) {
            $getMe = json_decode(file_get_contents("https://api.telegram.org/bot{$config['token']}/getMe"), true);
            $subscribe = (strpos($create_status['subscription_url'], 'http') !== false) ? $create_status['subscription_url'] : $panel['login_link'] . $create_status['subscription_url'];
            if ($panel['qr_code'] == 'активен') {
                $encode_url = urlencode($subscribe);
                bot('sendPhoto', ['chat_id' => $from_id, 'photo' => "https://api.qrserver.com/v1/create-qr-code/?data=$encode_url&size=800x800", 'caption' => sprintf($texts['success_create_service'], $name, $location, $date, $limit, number_format($price), $subscribe, '@' . $getMe['result']['username']), 'parse_mode' => 'html', 'reply_markup' => $start_key]);
            } else {
                sendmessage($from_id, sprintf($texts['success_create_service'], $name, $location, $date, $limit, number_format($price), $subscribe, '@' . $getMe['result']['username']), $start_key);
            }
            $sql->query("INSERT INTO `orders` (`from_id`, `location`, `protocol`, `date`, `volume`, `link`, `price`, `code`, `status`, `type`) VALUES ('$from_id', '$location', 'null', '$date', '$limit', '$links', '$price', '$code', 'активен', 'marzban')");
            // sendmessage($config['dev'], sprintf($texts['success_create_notif']), $first_name, $username, $from_id, $user['count_service'], $user['coin'], $location, $plan, $limit, $date, $code, number_format($price));
        }else{
            sendmessage($from_id, sprintf($texts['create_error'], 2), $start_key);
            exit();
        }

    } elseif ($panel['type'] == 'sanayi') {

        include_once 'api/sanayi.php';
        $xui = new Sanayi($panel['login_link'], $panel['token']);
        $san_setting = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '{$panel['code']}'")->fetch_assoc();
        $create_service = $xui->addClient($name, $san_setting['inbound_id'], $date, $limit);
        $create_status = json_decode($create_service, true);
        # ---------------- проверить ошибки ---------------- #
        if ($create_status['status'] == false) {
            sendMessage($from_id, sprintf($texts['create_error'], 1), $start_key);
            exit();
        }

        		# ---------------- получить ссылки и subscription_url для отправки пользователю ---------------- #
		if ($info_panel->num_rows > 0) {
			$getMe = json_decode(file_get_contents("https://api.telegram.org/bot{$config['token']}/getMe"), true);
			$link = str_replace(['%s1', '%s2', '%s3'], [$create_status['results']['id'], str_replace(parse_url($panel['login_link'])['port'], json_decode($xui->getPortById($san_setting['inbound_id']), true)['port'], str_replace(['https://', 'http://'], ['', ''], $panel['login_link'])), $create_status['results']['remark']], $san_setting['example_link']);
			if ($panel['qr_code'] == 'active') {
				$encode_url = urlencode($link);
				bot('sendPhoto', ['chat_id' => $from_id, 'photo' => "https://api.qrserver.com/v1/create-qr-code/?data=$encode_url&size=800x800", 'caption' => sprintf($texts['success_create_service_sanayi'], $name, $location, $date, $limit, number_format($price), $link, $create_status['results']['subscribe'], '@' . $getMe['result']['username']), 'parse_mode' => 'html', 'reply_markup' => $start_key]);
			} else {
				sendMessage($from_id, sprintf($texts['success_create_service_sanayi'], $name, $location, $date, $limit, number_format($price), $link, $create_status['results']['subscribe'], '@' . $getMe['result']['username']), $start_key);
			}
			$sql->query("INSERT INTO `orders` (`from_id`, `location`, `protocol`, `date`, `volume`, `link`, `price`, `code`, `status`, `type`) VALUES ('$from_id', '$location', 'null', '$date', '$limit', '$link', '$price', '$code', 'active', 'sanayi')");
			// sendMessage($config['dev'], sprintf($texts['success_create_notif']), $first_name, $username, $from_id, $user['count_service'], $user['coin'], $location, $plan, $limit, $date, $code, number_format($price));
		} else {
			sendMessage($from_id, sprintf($texts['create_error'], 2), $start_key);
			exit();
		}
	}
	$sql->query("DELETE FROM `service_factors` WHERE `from_id` = '$from_id'");
	$sql->query("UPDATE `users` SET `coin` = coin - $price, `count_service` = count_service + 1 WHERE `from_id` = '$from_id' LIMIT 1");
}
	
elseif ($text == '🎁 Сервис тестирования (бесплатно)' and $test_account_setting['status'] == 'active') {
	step('none');
	if ($user['test_account'] == 'no') {
		sendMessage($from_id, '⏳', $start_key);

		$panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '{$test_account_setting['panel']}'");
		$panel_fetch = $panel->fetch_assoc();

		try {
			if ($panel_fetch['type'] == 'marzban') {
			# ---------------- установка прокси и входящих процессов для панели marzban ---------------- #
				$protocols = explode('|', $panel_fetch['protocols']);
				unset($protocols[count($protocols)-1]);
				if ($protocols[0] == '') unset($protocols[0]);
				$proxies = array();
				foreach ($protocols as $protocol) {
					if ($protocol == 'vless' and $panel_fetch['flow'] == 'flowon'){
						$proxies[$protocol] = array('flow' => 'xtls-rprx-vision');
					} else {
						$proxies[$protocol] = array();
					}
				}

				$panel_inbounds = $sql->query("SELECT * FROM `marzban_inbounds` WHERE `panel` = '{$panel_fetch['code']}'");
				$inbounds = array();
				foreach ($protocols as $protocol) {
					while ($row = $panel_inbounds->fetch_assoc()) {
						$inbounds[$protocol][] = $row['inbound'];
					}
				}
				# ---------------------------------------------- #
				$code = rand(111111, 999999);
				$name = base64_encode($code) . '_' . $from_id;
				$create_service = createService($name, convertToBytes($test_account_setting['volume'].'GB'), strtotime("+ {$test_account_setting['time']} hour"), $proxies, ($panel_inbounds->num_rows > 0) ? $inbounds : 'null', $panel_fetch['token'], $panel_fetch['login_link']);
				$create_status = json_decode($create_service, true);
				if (isset($create_status['username'])) {
					$links = "";
					foreach ($create_status['links'] as $link) $links .= $link . "\n\n";
					$subscribe = (strpos($create_status['subscription_url'], 'http') !== false) ? $create_status['subscription_url'] : $panel_fetch['login_link'] . $create_status['subscription_url'];
					$sql->query("UPDATE `users` SET `count_service` = count_service + 1, `test_account` = 'yes' WHERE `from_id` = '$from_id'");
					$sql->query("INSERT INTO `test_account` (`from_id`, `location`, `date`, `volume`, `link`, `price`, `code`, `status`) VALUES ('$from_id', '{$panel_fetch['name']}', '{$test_account_setting['date']}', '{$test_account_setting['volume']}', '$links', '0', '$code', 'active')");
					deleteMessage($from_id, $message_id + 1);
					sendMessage($from_id, sprintf($texts['create_test_account'], $test_account_setting['time'], $subscribe, $panel_fetch['name'], $test_account_setting['time'], $test_account_setting['volume'], base64_encode($code)), $start_key);
				} else {
					deleteMessage($from_id, $message_id + 1);
					sendMessage($from_id, sprintf($texts['create_error'], 1), $start_key);
				}
			}

			if ($panel_fetch['type'] == 'sanayi') {
				include_once 'api/sanayi.php';
				$code = rand(111111, 999999);
				$name = base64_encode($code) . '_' . $from_id;
				$xui = new Sanayi($panel_fetch['login_link'], $panel_fetch['token']);
				$san_setting = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '{$panel_fetch['code']}'")->fetch_assoc();
				$create_service = $xui->addClient($name, $san_setting['inbound_id'], $test_account_setting['volume'], ($test_account_setting['time'] / 24));
				$create_status = json_decode($create_service, true);
				$link = str_replace(['%s1', '%s2', '%s3'], [$create_status['results']['id'], str_replace(parse_url($panel_fetch['login_link'])['port'], json_decode($xui->getPortById($san_setting['inbound_id']), true)['port'], str_replace(['https://', 'http://'], ['', ''], $panel_fetch['login_link'])), $create_status['results']['remark']], $san_setting['example_link']);
				# ---------------- проверка ошибок ---------------- #
				if ($create_status['status'] == false) {
				sendMessage($from_id, sprintf($texts['create_error'], 1), $start_key);
				exit();
				}
				# ---------------------------------------------- #
				$sql->query("UPDATE `users` SET `count_service` = count_service + 1, `test_account` = 'yes' WHERE `from_id` = '$from_id'");
				$sql->query("INSERT INTO `test_account` (`from_id`, `location`, `date`, `volume`, `link`, `price`, `code`, `status`) VALUES ('$from_id', '{$panel_fetch['name']}', '{$test_account_setting['date']}', '{$test_account_setting['volume']}', '$link', '0', '$code', 'active')");
				deleteMessage($from_id, $message_id + 1);
				sendMessage($from_id, sprintf($texts['create_test_account'], $test_account_setting['time'], $link, $panel_fetch['name'], $test_account_setting['time'], $test_account_setting['volume'], base64_encode($code)), $start_key);
			}
		} catch (\Throwable $e) {
			sendMessage($config['dev'], $e);
		}

	} else {
		sendMessage($from_id, $texts['already_test_account'], $start_key);
	}
}

elseif ($text == '🛍 Мои сервисы' or $data == 'back_services') {
	$services = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$from_id'");
	if ($services->num_rows > 0) {
		while ($row = $services->fetch_assoc()) {
			$status = ($row['status'] == 'active') ? '🟢 | ' : '🔴 | ';
			$key[] = ['text' => $status . base64_encode($row['code']) . ' - ' . $row['location'], 'callback_data' => 'service_status-'.$row['code']];
		}
		$key = array_chunk($key, 1);
		$key = json_encode(['inline_keyboard' => $key]);
		if (isset($text)) {
			sendMessage($from_id, sprintf($texts['my_services'], $services->num_rows), $key);
		} else {
			editMessage($from_id, sprintf($texts['my_services'], $services->num_rows), $message_id, $key);
		}
	} else {
		if (isset($text)) {
			sendMessage($from_id, $texts['my_services_not_found'], $start_key);
		} else {
			editMessage($from_id, $texts['my_services_not_found'], $message_id, $start_key);
		}
	}
}

elseif (strpos($data, 'service_status-') !== false) {
	$code = explode('-', $data)[1];
	$getService = $sql->query("SELECT * FROM `orders` WHERE `code` = '$code'")->fetch_assoc();
	$panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '{$getService['location']}'")->fetch_assoc();

	if ($panel['type'] == 'marzban') {

		$getUser = getUserInfo(base64_encode($code) . '_' . $from_id, $panel['token'], $panel['login_link']);
		if (isset($getUser['links']) and $getUser != false) {
			$links = implode("\n\n", $getUser['links']) ?? 'NULL';
			$subscribe = (strpos($getUser['subscription_url'], 'http') !== false) ? $getUser['subscription_url'] : $panel['login_link'] . $getUser['subscription_url'];
			$note = $sql->query("SELECT * FROM `notes` WHERE `code` = '$code'");

			$manage_service_btns = json_encode(['inline_keyboard' => [    
				// [['text' => 'Текст', 'callback_data' => 'access_settings-'.$code.'-marzban']],
				[['text' => 'Купить дополнительный объем', 'callback_data' => 'buy_extra_volume-'.$code.'-marzban'], ['text' => 'Увеличить кредитное время', 'callback_data' => 'buy_extra_time-'.$code.'-marzban']],
				[['text' => 'Написать заметку', 'callback_data' => 'write_note-'.$code.'-marzban'], ['text' => 'Получить QrCode', 'callback_data' => 'getQrCode-'.$code.'-marzban']],
				[['text' => '🔙 Назад', 'callback_data' => 'back_services']]
			]]);
			if ($note->num_rows == 0) {
				editMessage($from_id, sprintf($texts['your_service'], ($getUser['status'] == 'active') ? '🟢 Активен' : '🔴 Неактивен', $getService['location'], base64_encode($code), Conversion($getUser['used_traffic'], 'GB'), Conversion($getUser['data_limit'], 'GB'), date('Y-d-m H:i:s',  $getUser['expire']), $subscribe), $message_id, $manage_service_btns);
			} else {
				$note = $note->fetch_assoc();
				editMessage($from_id, sprintf($texts['your_service_with_note'], ($getUser['status'] == 'active') ? '🟢 Активен' : '🔴 Неактивен', $note['note'],$getService['location'], base64_encode($code), Conversion($getUser['used_traffic'], 'GB'), Conversion($getUser['data_limit'], 'GB'), date('Y-d-m H:i:s',  $getUser['expire']), $subscribe), $message_id, $manage_service_btns);
			}
		} else {
			$sql->query("DELETE FROM `orders` WHERE `code` = '$code'");
			alert($texts['not_found_service']);
		}

	} elseif ($panel['type'] == 'sanayi') {

		include_once 'api/sanayi.php';
		$san_setting = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '{$panel['code']}'")->fetch_assoc();
		$xui = new Sanayi($panel['login_link'], $panel['token']);
		$getUser = $xui->getUserInfo(base64_encode($code) . '_' . $from_id, $san_setting['inbound_id']);
		$getUser = json_decode($getUser, true);
		if ($getUser['status']) {
			$note = $sql->query("SELECT * FROM `notes` WHERE `code` = '$code'");
			$order = $sql->query("SELECT * FROM `orders` WHERE `code` = '$code'")->fetch_assoc();
			$link = $order['link'];

            $manage_service_btns = json_encode(['inline_keyboard' => [    
				// [['text' => 'Текст', 'callback_data' => 'access_settings-'.$code.'-marzban']],
				[['text' => 'Купить дополнительный объем', 'callback_data' => 'buy_extra_volume-'.$code.'-marzban'], ['text' => 'Увеличить кредитное время', 'callback_data' => 'buy_extra_time-'.$code.'-marzban']],
				[['text' => 'Написать заметку', 'callback_data' => 'write_note-'.$code.'-marzban'], ['text' => 'Получить QrCode', 'callback_data' => 'getQrCode-'.$code.'-marzban']],
				[['text' => '🔙 Назад', 'callback_data' => 'back_services']]
			]]);
			if ($note->num_rows == 0) {
				editMessage($from_id, sprintf($texts['your_service'], ($getUser['status'] == 'active') ? '🟢 Активен' : '🔴 Неактивен', $getService['location'], base64_encode($code), Conversion($getUser['used_traffic'], 'GB'), Conversion($getUser['data_limit'], 'GB'), date('Y-d-m H:i:s',  $getUser['expire']), $subscribe), $message_id, $manage_service_btns);
			} else {
				$note = $note->fetch_assoc();
				editMessage($from_id, sprintf($texts['your_service_with_note'], ($getUser['status'] == 'active') ? '🟢 Активен' : '🔴 Неактивен', $note['note'],$getService['location'], base64_encode($code), Conversion($getUser['used_traffic'], 'GB'), Conversion($getUser['data_limit'], 'GB'), date('Y-d-m H:i:s',  $getUser['expire']), $subscribe), $message_id, $manage_service_btns);
			}
		} else {
			$sql->query("DELETE FROM `orders` WHERE `code` = '$code'");
			alert($texts['not_found_service']);
		}

	} elseif ($panel['type'] == 'sanayi') {

		include_once 'api/sanayi.php';
		$san_setting = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '{$panel['code']}'")->fetch_assoc();
		$xui = new Sanayi($panel['login_link'], $panel['token']);
		$getUser = $xui->getUserInfo(base64_encode($code) . '_' . $from_id, $san_setting['inbound_id']);
		$getUser = json_decode($getUser, true);
		if ($getUser['status']) {
			$note = $sql->query("SELECT * FROM `notes` WHERE `code` = '$code'");
			$order = $sql->query("SELECT * FROM `orders` WHERE `code` = '$code'")->fetch_assoc();
			$link = $order['link'];
			
			$manage_service_btns = json_encode(['inline_keyboard' => [
			// [['text' => 'Настройки доступа', 'callback_data' => 'access_settings-'.$code.'-sanayi']],
			[['text' => 'Купить дополнительный объем', 'callback_data' => 'buy_extra_volume-'.$code.'-sanayi'], ['text' => 'Увеличить время кредита', 'callback_data' => 'buy_extra_time-'.$code.'-sanayi']],
			[['text' => 'Написать заметку', 'callback_data' => 'write_note-'.$code.'-sanayi'], ['text' => 'Получить QrCode', 'callback_data' => 'getQrCode-'.$code.'-sanayi']],
			[['text' => '🔙 Назад', 'callback_data' => 'back_services']]
		]]);

		if ($note->num_rows == 0) {
			editMessage($from_id, sprintf($texts['your_service'], ($getUser['result']['enable'] == true) ? '🟢 Активен' : '🔴 Неактивен', $getService['location'], base64_encode($code), Conversion($getUser['result']['up'] + $getUser['result']['down'], 'GB'), ($getUser['result']['total'] == 0) ? 'Неограничен' : Conversion($getUser['result']['total'], 'GB') . ' MB', date('Y-d-m H:i:s',  $getUser['result']['expiryTime']), $link), $message_id, $manage_service_btns);
		} else {
			$note = $note->fetch_assoc();
			editMessage($from_id, sprintf($texts['your_service_with_note'], ($getUser['result']['enable'] == true) ? '🟢 Активен' : '🔴 Неактивен', $note['note'], $getService['location'], base64_encode($code), Conversion($getUser['result']['up'] + $getUser['result']['down'], 'GB'), ($getUser['result']['total'] == 0) ? 'Неограничен' : Conversion($getUser['result']['total'], 'GB') . ' MB', date('Y-d-m H:i:s',  $getUser['result']['expiryTime']), $link), $message_id, $manage_service_btns);
		} else {
			$sql->query("DELETE FROM `orders` WHERE `code` = '$code'");
			alert($texts['not_found_service']);
		}
	}
}

elseif (strpos($data, 'getQrCode') !== false) {
    alert($texts['wait']);

    $code = explode('-', $data)[1];
    $type = explode('-', $data)[2];
    $getService = $sql->query("SELECT * FROM `orders` WHERE `code` = '$code'")->fetch_assoc();
    $panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '{$getService['location']}'")->fetch_assoc();

    if ($type == 'marzban') {
        $token = loginPanel($panel['login_link'], $panel['username'], $panel['password'])['access_token'];
        $getUser = getUserInfo(base64_encode($code) . '_' . $from_id, $token, $panel['login_link']);
        if (isset($getUser['links']) and $getUser != false) {
            $subscribe = (strpos($getUser['subscription_url'], 'http') !== false) ? $getUser['subscription_url'] : $panel['login_link'] . $getUser['subscription_url'];
            $encode_url = urldecode($subscribe);
            bot('sendPhoto', ['chat_id' => $from_id, 'photo' => "https://api.qrserver.com/v1/create-qr-code/?data=$encode_url&size=800x800", 'caption' => "<code>$subscribe</code>", 'parse_mode' => 'html']);
        } else {
            alert('❌ Ошибка', true);
        }
    } elseif ($type == 'sanayi') {
        $order = $sql->query("SELECT * FROM `orders` WHERE `code` = '$code'")->fetch_assoc();
        $link = $order['link'];
        $encode_url = urlencode($link);
        bot('sendPhoto', ['chat_id' => $from_id, 'photo' => "https://api.qrserver.com/v1/create-qr-code/?data=$encode_url&size=800x800", 'caption' => "<code>$link</code>", 'parse_mode' => 'html']);
    } else {
        alert('❌ Ошибка -> тип не найден!', true);
    }
}

elseif (strpos($data, 'write_note') !== false) {
    $code = explode('-', $data)[1];
    $type = explode('-', $data)[2];
    step('set_note-'.$code.'-'.$type);
    deleteMessage($from_id, $message_id);
    sendMessage($from_id, sprintf($texts['send_note'], $code), $back);
}

elseif (strpos($user['step'], 'set_note') !== false) {
    $code = explode('-', $user['step'])[1];
    $type = explode('-', $user['step'])[2];
    if ($sql->query("SELECT `code` FROM `notes` WHERE `code` = '$code'")->num_rows == 0) {
        $sql->query("INSERT INTO `notes` (`note`, `code`, `type`, `status`) VALUES ('$text', '$code', '$type', 'active')");
    } else {
        $sql->query("UPDATE `notes` SET `note` = '$text' WHERE `code` = '$code'");
    }
    sendMessage($from_id, sprintf($texts['set_note_success'], $code), $start_key);
}

elseif (strpos($data, 'buy_extra_time') !== false) {
    $code = explode('-', $data)[1];
    $type = explode('-', $data)[2];
    $category_date = $sql->query("SELECT * FROM `category_date` WHERE `status` = 'active'");

    if ($category_date->num_rows > 0) {
        while ($row = $category_date->fetch_assoc()) {
            $key[] = ['text' => $row['name'], 'callback_data' => 'select_extra_time-'.$row['code'].'-'.$code];
        }
        $key = array_chunk($key, 2);
        $key[] = [['text' => '🔙 Назад', 'callback_data' => 'service_status-'.$code]];
        $key = json_encode(['inline_keyboard' => $key]);
        editMessage($from_id, sprintf($texts['select_extra_time_plan'], $code), $message_id, $key);
    } else {
        alert($texts['not_found_plan_extra_time'], true);
    }
}

elseif (strpos($data, 'buy_extra_volume') !== false) {
    $code = explode('-', $data)[1];
    $type = explode('-', $data)[2];
    $category_limit = $sql->query("SELECT * FROM `category_limit` WHERE `status` = 'active'");

    if ($category_limit->num_rows > 0) {
        while ($row = $category_limit->fetch_assoc()) {
            $key[] = ['text' => $row['name'], 'callback_data' => 'select_extra_volume-'.$row['code'].'-'.$code];
        }
        $key = array_chunk($key, 2);
        $key[] = [['text' => '🔙 Назад', 'callback_data' => 'service_status-'.$code]];
        $key = json_encode(['inline_keyboard' => $key]);
        editMessage($from_id, sprintf($texts['select_extra_volume_plan'], $code), $message_id, $key);
    } else {
        alert($texts['not_found_plan_extra_volume'], true);
    }
}
elseif ($data == 'cancel_buy') {
    step('none');
    deleteMessage($from_id, $message_id);
    sendMessage($from_id, $texts['cancel_extra_factor'], $start_key);
}

elseif (strpos($data, 'select_extra_time') !== false) {
    $service_code = explode('-', $data)[2];
    $plan_code = explode('-', $data)[1];
    $service = $sql->query("SELECT * FROM `orders` WHERE `code` = '$service_code'")->fetch_assoc();
    $plan = $sql->query("SELECT * FROM `category_date` WHERE `code` = '$plan_code'")->fetch_assoc();
    
    $access_key = json_encode(['inline_keyboard' => [
        [['text' => '❌ Отмена', 'callback_data' => 'cancel_buy'], ['text' => '✅ Подтвердить', 'callback_data' => 'confirm_extra_time-'.$service_code.'-'.$plan_code]],
    ]]);
    
    editMessage($from_id, sprintf($texts['create_buy_extra_time_factor'], $service_code, $service_code, $plan['name'], number_format($plan['price']), $service_code), $message_id, $access_key);
}

elseif (strpos($data, 'confirm_extra_time') !== false) {
    alert($texts['wait']);
    $service_code = explode('-', $data)[1];
    $plan_code = explode('-', $data)[2];
    $service = $sql->query("SELECT * FROM `orders` WHERE `code` = '$service_code'")->fetch_assoc();
    $plan = $sql->query("SELECT * FROM `category_date` WHERE `code` = '$plan_code'")->fetch_assoc();
    $getService = $sql->query("SELECT * FROM `orders` WHERE `code` = '$service_code'")->fetch_assoc();
    $panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '{$getService['location']}'")->fetch_assoc();

    if ($user['coin'] >= $plan['price']) {
        if ($service['type'] == 'marzban') {
            $token = loginPanel($panel['login_link'], $panel['username'], $panel['password'])['access_token'];
            $getUser = getUserInfo(base64_encode($service_code) . '_' . $from_id, $token, $panel['login_link']);
            $response = Modifyuser(base64_encode($service_code) . '_' . $from_id, array('expire' => $getUser['expire'] += 86400 * $plan['date']), $token, $panel['login_link']);
        } elseif ($service['type'] == 'sanayi') {
            include_once 'api/sanayi.php';
            $panel_setting = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '{$panel['code']}'")->fetch_assoc();
            $xui = new Sanayi($panel['login_link'], $panel['token']);
            $getUser = $xui->getUserInfo(base64_encode($service_code) . '_' . $from_id, $panel_setting['inbound_id']);
            $getUser = json_decode($getUser, true);
            if ($getUser['status'] == true) {
                $response = $xui->addExpire(base64_encode($service_code) . '_' . $from_id, $plan['date'], $panel_setting['inbound_id']);
                // sendMessage($from_id, $response);
            } else {
                alert('❌ Ошибка --> услуга не найдена');
            }
        }

        $sql->query("UPDATE `users` SET `coin` = coin - {$plan['price']} WHERE `from_id` = '$from_id'");
        deleteMessage($from_id, $message_id);
        sendMessage($from_id, sprintf($texts['success_extra_time'], $plan['date'], $plan['name'], number_format($plan['price'])), $start_key);
    } else {
        alert($texts['not_coin_extra'], true);
    }
}

elseif (strpos($data, 'select_extra_volume') !== false) {
    $service_code = explode('-', $data)[2];
    $plan_code = explode('-', $data)[1];
    $service = $sql->query("SELECT * FROM `orders` WHERE `code` = '$service_code'")->fetch_assoc();
    $plan = $sql->query("SELECT * FROM `category_limit` WHERE `code` = '$plan_code'")->fetch_assoc();
    
    $access_key = json_encode(['inline_keyboard' => [
        [['text' => '❌ Отмена', 'callback_data' => 'cancel_buy'], ['text' => '✅ Подтвердить', 'callback_data' => 'confirm_extra_volume-'.$service_code.'-'.$plan_code]],
    ]]);
    
    editMessage($from_id, sprintf($texts['create_buy_extra_volume_factor'], $service_code, $service_code, $plan['name'], number_format($plan['price']), $service_code), $message_id, $access_key);
}

elseif (strpos($data, 'confirm_extra_volume') !== false) {
    alert($texts['wait']);
    $service_code = explode('-', $data)[1];
    $plan_code = explode('-', $data)[2];
    $service = $sql->query("SELECT * FROM `orders` WHERE `code` = '$service_code'")->fetch_assoc();
    $plan = $sql->query("SELECT * FROM `category_limit` WHERE `code` = '$plan_code'")->fetch_assoc();
    $getService = $sql->query("SELECT * FROM `orders` WHERE `code` = '$service_code'")->fetch_assoc();
    $panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '{$getService['location']}'")->fetch_assoc();

    if ($user['coin'] >= $plan['price']) {
        if ($service['type'] == 'marzban') {
            $token = loginPanel($panel['login_link'], $panel['username'], $panel['password'])['access_token'];
            $getUser = getUserInfo(base64_encode($service_code) . '_' . $from_id, $token, $panel['login_link']);
            $response = Modifyuser(base64_encode($service_code) . '_' . $from_id, array('data_limit' => $getUser['data_limit'] += $plan['limit'] * pow(1024, 3)), $token, $panel['login_link']);
        } elseif ($service['type'] == 'sanayi') {
            include_once 'api/sanayi.php';
            $panel_setting = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '{$panel['code']}'")->fetch_assoc();
            $xui = new Sanayi($panel['login_link'], $panel['token']);
            $getUser = $xui->getUserInfo(base64_encode($service_code) . '_' . $from_id, $panel_setting['inbound_id']);
            $getUser = json_decode($getUser, true);
            if ($getUser['status'] == true) {
                $response = $xui->addVolume(base64_encode($service_code) . '_' . $from_id, $plan['limit'], $panel_setting['inbound_id']);
            } else {
                alert('❌ Ошибка --> услуга не найдена');
            }
        }

        $sql->query("UPDATE `users` SET `coin` = coin - {$plan['price']} WHERE `from_id` = '$from_id'");
        deleteMessage($from_id, $message_id);
        sendMessage($from_id, sprintf($texts['success_extra_volume'], $plan['limit'], $plan['name'], number_format($plan['price'])), $start_key);
    } else {
        alert($texts['not_coin_extra'], true);
    }
}

elseif ($text == '💸 Пополнить счет') {
    if ($auth_setting['status'] == 'active') {
        if ($auth_setting['iran_number'] == 'active' or $auth_setting['virtual_number'] == 'active' or $auth_setting['both_number'] == 'active') {
            if (is_null($user['phone'])) {
                step('authentication');
                sendMessage($from_id, $texts['send_phone'], $send_phone);
            } else {
                step('diposet');
                sendMessage($from_id, $texts['diposet'], $back);
            }
        } else {
            step('diposet');
            sendMessage($from_id, $texts['diposet'], $back);
        }
    } else {
        step('diposet');
        sendMessage($from_id, $texts['diposet'], $back);
    }
}

elseif ($user['step'] == 'authentication') {
    $contact = $update->message->contact;
    if (isset($contact)) {
        if ($contact->user_id == $from_id) {
            if ($auth_setting['iran_number'] == 'active') {
                if (strpos($contact->phone_number, '+98') !== false) {
                    $sql->query("UPDATE `users` SET `phone` = '{$contact->phone_number}' WHERE `from_id` = '$from_id'");
                    sendMessage($from_id, $texts['send_phone_success'], $start_key);
                } else {
                    sendMessage($from_id, $texts['only_iran'], $back);
                }
            } elseif ($auth_setting['virtual_number'] == 'active') {
                if (strpos($contact->phone_number, '+98') === false) {
                    $sql->query("UPDATE `users` SET `phone` = '{$contact->phone_number}' WHERE `from_id` = '$from_id'");
                    sendMessage($from_id, $texts['send_phone_success'], $start_key);
                } else {
                    sendMessage($from_id, $texts['only_virtual'], $back);
                }
            } elseif ($auth_setting['both_number'] == 'active') {
                $sql->query("UPDATE `users` SET `phone` = '{$contact->phone_number}' WHERE `from_id` = '$from_id'");
                sendMessage($from_id, $texts['send_phone_success'], $start_key);   
            }
        } else {
            sendMessage($from_id, $texts['send_phone_with_below_btn'], $send_phone);    
        }
    } else {
        sendMessage($from_id, $texts['send_phone_with_below_btn'], $send_phone);
    }
}
elseif ($user['step'] == 'diposet') {
    if (is_numeric($text) and $text >= 2000) {
        step('sdp-' . $text);
        sendMessage($from_id, sprintf($texts['select_diposet_payment'], number_format($text)), $select_diposet_payment);
    } else {
        sendMessage($from_id, $texts['diposet_input_invalid'], $back);
    }
}

elseif ($data == 'cancel_payment_proccess') {
    step('none');
    deleteMessage($from_id, $message_id);
    sendMessage($from_id, sprintf($texts['start'], $first_name), $start_key);
}

elseif (in_array($data, ['zarinpal', 'idpay']) and strpos($user['step'], 'sdp-') !== false) {
    if ($payment_setting[$data . '_status'] == 'active') {
        $status = $sql->query("SELECT `{$data}_token` FROM `payment_setting`")->fetch_assoc()[$data . '_token'];
        if ($status != 'none') {
            step('none');
            $price = explode('-', $user['step'])[1];
            $code = rand(11111111, 99999999);
            $sql->query("INSERT INTO `factors` (`from_id`, `price`, `code`, `status`) VALUES ('$from_id', '$price', '$code', 'no')");
            $response = ($data == 'zarinpal') ? zarinpalGenerator($from_id, $price, $code) : idpayGenerator($from_id, $price, $code);
            if ($response) $pay = json_encode(['inline_keyboard' => [[['text' => '💵 Оплатить', 'url' => $response]]]]);
            deleteMessage($from_id, $message_id);
            sendMessage($from_id, sprintf($texts['create_diposet_factor'], $code, number_format($price)), $pay);
            sendMessage($from_id, $texts['back_to_menu'], $start_key);
        } else {
            alert($texts['error_choice_pay']);
        }
    } else {
        alert($texts['not_active_payment']);
    }
}

elseif ($data == 'nowpayment' and strpos($user['step'], 'sdp-') !== false) {
    if ($payment_setting[$data . '_status'] == 'active') {
        alert('⏱ Пожалуйста, подождите несколько секунд.');
        if ($payment_setting[$data . '_status'] == 'active') {
            $code = rand(111111, 999999);
            $price = explode('-', $user['step'])[1];
            $dollar = json_decode(file_get_contents($config['domain'] . '/api/arz.php'), true)['price'];
            $response_gen = nowPaymentGenerator((intval($price) / intval($dollar)), 'usd', 'trx', $code);
            if (!is_null($response_gen)) {
                $response = json_decode($response_gen, true);
                $sql->query("INSERT INTO `factors` (`from_id`, `price`, `code`, `status`) VALUES ('$from_id', '$price', '{$response['payment_id']}', 'no')");
                $key = json_encode(['inline_keyboard' => [[['text' => '✅ Я оплатил', 'callback_data' => 'checkpayment-' . $response['payment_id']]]]]);
                deleteMessage($from_id, $message_id);
                sendMessage($from_id, sprintf($texts['create_nowpayment_factor'], $response['payment_id'], number_format($price), number_format($dollar), $response['pay_amount'], $response['pay_address']), $key);
                sendMessage($from_id, $texts['back_to_menu'], $start_key);
            } else {
                deleteMessage($from_id, $message_id);
                sendMessage($from_id, $texts['error_nowpayment'] . "\n◽- <code>USDT: $dollar</code>", $start_key);
            }
        } else {
            alert($texts['not_active_payment']);
        }
    } else {
        alert($texts['not_active_payment']);
    }
}

elseif (strpos($data, 'checkpayment') !== false) {
    $payment_id = explode('-', $data)[1];
    $get = checkNowPayment($payment_id);
    $status = json_decode($get, true)['payment_status'];
    if ($status != 'waiting') {
        $factor = $sql->query("SELECT * FROM `factors` WHERE `code` = '$payment_id'")->fetch_assoc();
        if ($factor['status'] == 'no') {
            $sql->query("UPDATE `users` SET `coin` = coin + {$factor['price']}, `count_charge` = count_charge + 1 WHERE `from_id` = '$from_id'");
            $sql->query("UPDATE `factors` SET `status` = 'yes' WHERE `code` = '$payment_id'");
            deleteMessage($from_id, $message_id);
            sendMessage($from_id, sprintf($texts['success_nowpayment'], number_format($factor['price'])), $start_key);
            // sendMessage($config['dev'], $texts['success_payment_notif']);
        } else {
            alert($texts['not_success_nowpayment']);
        }
    } else {
        alert($texts['not_success_nowpayment']);
    }
}

elseif ($data == 'kart') {
	if ($payment_setting['card_status'] == 'active') {
	    $price = explode('-', $user['step'])[1];
	    step('send_fish-'.$price);
	    $code = rand(11111111, 99999999);
	    $card_number = $sql->query("SELECT `card_number` FROM `payment_setting`")->fetch_assoc()['card_number'];
	    $card_number_name = $sql->query("SELECT `card_number_name` FROM `payment_setting`")->fetch_assoc()['card_number_name'];
	    deleteMessage($from_id, $message_id);
	    sendMessage($from_id, sprintf($texts['create_kart_factor'], $code, number_format($price), ($card_number != 'none') ? $card_number : '❌ Не настроено', ($card_number_name != 'none') ? $card_number_name : ''), $back);
	} else {
        alert($texts['not_active_payment']);
    }
}

elseif (strpos($user['step'], 'send_fish') !== false) {
    $price = explode('-', $user['step'])[1];
    if (isset($update->message->photo)) {
        step('none');
        $key = json_encode(['inline_keyboard' => [[['text' => '❌', 'callback_data' => 'cancel_fish-'.$from_id], ['text' => '✅', 'callback_data' => 'accept_fish-'.$from_id.'-'.$price]]]]);
        sendMessage($from_id, $texts['success_send_fish'], $start_key);
        sendMessage($config['dev'], sprintf($texts['success_send_fish_notif'], $from_id, $username, $price), $key);
        forwardMessage($from_id, $config['dev'], $message_id);
        if (!is_null($settings['log_channel'])) {
            sendMessage($settings['log_channel'], sprintf($texts['success_send_fish_notif'], $from_id, $username, $price));
            forwardMessage($from_id, $settings['log_channel'], $message_id);
        }
    } else {
        sendMessage($from_id, $texts['error_input_kart'], $back);
    }
}

elseif ($text == '🛒 Тарифы услуг') {
    sendMessage($from_id, $texts['service_tariff']);
}


elseif ($text == '👤 Профиль') {
    $count_all = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$from_id'")->num_rows;
    $count_all_active = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$from_id' AND `status` = 'active'")->num_rows;
    $count_all_inactive = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$from_id' AND `status` = 'inactive'")->num_rows;
    sendMessage($from_id, sprintf($texts['my_account'], $from_id, number_format($user['coin']), $count_all, $count_all_active, $count_all_inactive), $start_key);
}

elseif ($text == '📮 Онлайн поддержка') {
    step('support');
    sendMessage($from_id, $texts['support'], $back);
}

elseif ($user['step'] == 'support') {
    step('none');
    sendMessage($from_id, $texts['success_support'], $start_key);
    sendMessage($config['dev'], sprintf($texts['new_support_message'], $from_id, $from_id, $username, $user['coin']), $manage_user);
    forwardMessage($from_id, $config['dev'], $message_id);
}

elseif ($text == '🔗 Руководство по подключению') {
    step('select_sys');
    sendMessage($from_id, $texts['select_sys'], $education);
}

elseif (strpos($data, 'edu') !== false) {
    $sys = explode('_', $data)[1];
    deleteMessage($from_id, $message_id);
    sendMessage($from_id, $texts['edu_'.$sys], $education);
}

# ------------ Панель ------------ #

$admins = $sql->query("SELECT * FROM `admins`")->fetch_assoc() ?? [];
if ($from_id == $config['dev'] or in_array($from_id, $admins)) {
    if (in_array($text, ['/panel', 'panel', '🔧 Управление', 'панель', '⬅️ Назад к управлению'])) {
        step('panel');
        sendMessage($from_id, "👮‍♂️ - Привет, уважаемый админ [ <b>$first_name</b> ] !\n\n⚡️ Добро пожаловать в админ-панель бота.\n🗃 Текущая версия бота: <code>{$config['version']}</code>\n\n⚙️ Выберите один из вариантов ниже для управления ботом:\n\n🤖 | Чтобы быть в курсе всех обновлений и будущих версий бота, подпишитесь на канал Proxygram:↓\n◽️@Proxygram\n🤖 И также для обратной связи по обновлениям или ошибкам присоединяйтесь к группе ProxygramHUB:↓\n◽️@ProxygramHUB", $panel);
    }

    elseif($text == '👥 Управление статистикой бота'){
        sendMessage($from_id, "👋 Добро пожаловать в управление общей статистикой бота.\n\n👇🏻Выберите один из вариантов ниже:\n\n◽️@Proxygram", $manage_statistics);
    }

    elseif($text == '🌐 Управление сервером'){
        sendMessage($from_id, "⚙️ Добро пожаловать в управление тарифами.\n\n👇🏻Выберите один из вариантов ниже :\n\n◽️@Proxygram", $manage_server);
    }

    elseif($text == '👤 Управление пользователями'){
        sendMessage($from_id, "👤 Добро пожаловать в управление пользователями.\n\n👇🏻Выберите один из вариантов ниже :\n\n◽️@Proxygram", $manage_user);
    }

    elseif($text == '📤 Управление сообщением'){
        sendMessage($from_id, "📤 Добро пожаловать в управление сообщением.\n\n👇🏻Выберите один из вариантов ниже :\n\n◽️@Proxygram", $manage_message);
    }

    elseif($text == '👮‍♂️Управление админами'){
        sendMessage($from_id, "👮‍♂️ Добро пожаловать в управление админами.\n\n👇🏻Выберите один из вариантов ниже :\n\n◽️@Proxygram", $manage_admin);
    }

    elseif($text == '⚙️ Настройки'){
        sendMessage($from_id, "⚙️️ Добро пожаловать в настройки бота.\n\n👇🏻Выберите один из вариантов ниже :\n\n◽️@Proxygram", $manage_setting);
    }

    // ----------- не трогайте эту часть ----------- //
    elseif ($text == base64_decode('YmFzZTY0X2RlY29kZQ==')('8J+TniDYp9i32YTYp9i524zZhyDYotm+2K/bjNiqINix2KjYp9iq')) {
        base64_decode('c2VuZE1lc3NhZ2U=')($from_id, base64_decode('8J+QnSB8INio2LHYp9uMINin2LfZhNin2Lkg2KfYsiDYqtmF2KfZhduMINii2b7Yr9uM2Kog2YfYpyDZiCDZhtiz2K7ZhyDZh9in24wg2KjYudiv24wg2LHYqNin2Kog2LLZhtio2YjYsSDZvtmG2YQg2K/YsSDaqdin2YbYp9mEINiy2YbYqNmI2LEg2b7ZhtmEINi52LbZiCDYtNuM2K8gOuKGkwril73vuI9AWmFuYm9yUGFuZWwK8J+QnSB8INmIINmH2YXahtmG24zZhiDYqNix2KfbjCDZhti42LEg2K/Zh9uMINii2b7Yr9uM2Kog24zYpyDYqNin2q8g2YfYpyDYqNmHINqv2LHZiNmHINiy2YbYqNmI2LEg2b7ZhtmEINio2b7bjNmI2YbYr9uM2K8gOuKGkwril73vuI9AWmFuYm9yUGFuZWxHYXAK8J+QnSB8INmG2YXZiNmG2Ycg2LHYqNin2Kog2KLYrtix24zZhiDZhtiz2K7ZhyDYsdio2KfYqiDYstmG2KjZiNixINm+2YbZhCA64oaTCuKXve+4j0BaYW5ib3JQYW5lbEJvdA=='), $panel);
    }

    // ----------- управление аутентификацией ----------- //
    elseif ($text == '🔑 Система аутентификации' or $data == 'manage_auth') {
        if (isset($text)) {
            sendMessage($from_id, "🀄️ Добро пожаловать в раздел системы аутентификации бота!\n\n📚 Руководство по этому разделу:↓\n\n🟢 : Активно \n🔴 : Неактивно", $manage_auth);
        } else {
            editMessage($from_id, "🀄️ Добро пожаловать в раздел системы аутентификации бота!\n\n📚 Руководство по этому разделу:↓\n\n🟢 : Активно \n🔴 : Неактивно", $message_id, $manage_auth);
        }
    }

    elseif ($data == 'change_status_auth') {
        if ($auth_setting['status'] == 'active') {
            $sql->query("UPDATE `auth_setting` SET `status` = 'inactive'");
        } else {
            $sql->query("UPDATE `auth_setting` SET `status` = 'active'");
        }
        alert('✅ Изменения успешно выполнены.', true);
        editMessage($from_id, "🆙 Чтобы обновить изменения, нажмите на кнопку ниже!", $message_id, json_encode(['inline_keyboard' => [[['text' => '🔎 Обновить изменения', 'callback_data' => 'manage_auth']]]]));
    }

    elseif ($data == 'change_status_auth_iran') {
        if ($auth_setting['status'] == 'active') {
            if ($auth_setting['virtual_number'] == 'inactive' and $auth_setting['both_number'] == 'inactive') {
                if ($auth_setting['iran_number'] == 'active') {
                    $sql->query("UPDATE `auth_setting` SET `iran_number` = 'inactive'");
                } else {
                    $sql->query("UPDATE `auth_setting` SET `iran_number` = 'active'");
                }
                alert('✅ Изменения успешно выполнены.', true);
                editMessage($from_id, "🆙 Чтобы обновить изменения, нажмите на кнопку ниже!", $message_id, json_encode(['inline_keyboard' => [[['text' => '🔎 Обновить изменения', 'callback_data' => 'manage_auth']]]]));
            } else {
                alert('⚠️ Чтобы активировать систему аутентификации иранских номеров, необходимо отключить разделы ( 🏴󠁧󠁢󠁥󠁮󠁧󠁿 Виртуальные номера ) и ( 🌎 Все номера )!', true);
            }
        } else {
            alert('🔴 Чтобы включить этот раздел, сначала необходимо активировать раздел ( ℹ️ Система аутентификации )!', true);
        }
    }

    elseif ($data == 'change_status_auth_virtual') {
        if ($auth_setting['status'] == 'active') {
            if ($auth_setting['iran_number'] == 'inactive' and $auth_setting['both_number'] == 'inactive') {
                if ($auth_setting['virtual_number'] == 'active') {
                    $sql->query("UPDATE `auth_setting` SET `virtual_number` = 'inactive'");
                } else {
                    $sql->query("UPDATE `auth_setting` SET `virtual_number` = 'active'");
                }
                alert('✅ Изменения успешно выполнены.', true);
                editMessage($from_id, "🆙 Чтобы обновить изменения, нажмите на кнопку ниже!", $message_id, json_encode(['inline_keyboard' => [[['text' => '🔎 Обновить изменения', 'callback_data' => 'manage_auth']]]]));
            } else {
                alert('⚠️ Чтобы активировать систему аутентификации виртуальных номеров, необходимо отключить разделы ( 🇮🇷 Иранские номера ) и ( 🌎 Все номера )!', true);
            }
        } else {
            alert('🔴 Чтобы включить этот раздел, сначала необходимо активировать раздел ( ℹ️ Система аутентификации )!', true);
        }
    }

    elseif ($data == 'change_status_auth_all_country') {
        if ($auth_setting['status'] == 'active') {
            if ($auth_setting['iran_number'] == 'inactive' and $auth_setting['virtual_number'] == 'inactive') {
                if ($auth_setting['both_number'] == 'active') {
                    $sql->query("UPDATE `auth_setting` SET `both_number` = 'inactive'");
                } else {
                    $sql->query("UPDATE `auth_setting` SET `both_number` = 'active'");
                }
                alert('✅ Изменения успешно выполнены.', true);
                editMessage($from_id, "🆙 Чтобы обновить изменения, нажмите на кнопку ниже!", $message_id, json_encode(['inline_keyboard' => [[['text' => '🔎 Обновить изменения', 'callback_data' => 'manage_auth']]]]));
            } else {
                alert('⚠️ Чтобы активировать систему аутентификации всех номеров, необходимо отключить разделы ( 🇮🇷 Иранские номера ) и ( 🏴󠁧󠁢󠁥󠁮󠁧󠁿 Виртуальные номера )!', true);
            }
        } else {
            alert('🔴 Чтобы включить этот раздел, сначала необходимо активировать раздел ( ℹ️ Система аутентификации )!', true);
        }
    }
    // ----------- Управление статусом ----------- //
    elseif($text == '👤 Статистика бота'){
        $state1 = $sql->query("SELECT `status` FROM `users`")->num_rows;
        $state2 = $sql->query("SELECT `status` FROM `users` WHERE `status` = 'inactive'")->num_rows;
        $state3 = $sql->query("SELECT `status` FROM `users` WHERE `status` = 'active'")->num_rows;
        $state4 = $sql->query("SELECT `status` FROM `factors` WHERE `status` = 'yes'")->num_rows;
        sendMessage($from_id, "⚙️ Статистика вашего бота следующая:↓\n\n▫️Общее количество пользователей бота: <code>$state1</code> человек\n▫️Количество заблокированных пользователей: <code>$state2</code> человек\n▫️Количество активных пользователей: <code>$state3</code> человек\n\n🔢 Общее количество платежей: <code>$state4</code> штук\n\n🤖 @Proxygram", $manage_statistics);
    }
    
    // ----------- Управление серверами ----------- //
    elseif ($text == '❌ Отмена и возврат') {
        step('none');
        if (file_exists('add_panel.txt')) unlink('add_panel.txt');
        sendMessage($from_id, "⚙️ Добро пожаловать в управление серверами.\n\n👇🏻 Выберите одну из следующих опций:\n\n◽️@Proxygram", $manage_server);
    }
    
    elseif ($data == 'close_panel') {
        step('none');
        editMessage($from_id, "✅ Панель управления серверами успешно закрыта!", $message_id);
    }
    
    elseif ($text == '⏱ Управление тестовым аккаунтом' or $data == 'back_account_test') {
        step('none');
        // sendMessage($from_id, "{$test_account_setting['status']} - {$test_account_setting['panel']} - {$test_account_setting['volume']} - {$test_account_setting['time']}");
        // exit();
        if (isset($text)) {
            sendMessage($from_id, "⏱ Добро пожаловать в настройки тестового аккаунта.\n\n🟢 Отправьте объем в GB боту. Например, 200 МБ: 0.2\n🟢 Отправьте время в часах. Например, 5 часов: 5\n\n👇🏻 Выберите одну из следующих опций:\n◽️@Proxygram", $manage_test_account);
        } else {
            editMessage($from_id, "⏱ Добро пожаловать в настройки тестового аккаунта.\n\n🟢 Отправьте объем в GB боту. Например, 200 МБ: 0.2\n🟢 Отправьте время в часах. Например, 5 часов: 5\n\n👇🏻 Выберите одну из следующих опций:\n◽️@Proxygram", $message_id, $manage_test_account);
        }
    }
    
    elseif ($data == 'null') {
        alert('#️⃣ Эта кнопка служебная!');
    }
    
    elseif ($data == 'change_test_account_status') {
        $status = $sql->query("SELECT `status` FROM `test_account_setting`")->fetch_assoc()['status'];
        if($status == 'active'){
            $sql->query("UPDATE `test_account_setting` SET `status` = 'inactive'");
        }else{
            $sql->query("UPDATE `test_account_setting` SET `status` = 'active'");
        }
        $manage_test_account = json_encode(['inline_keyboard' => [
            [['text' => ($status == 'active') ? '🔴' : '🟢', 'callback_data' => 'change_test_account_status'], ['text' => '▫️Состояние :', 'callback_data' => 'null']],
            [['text' => ($test_account_setting['panel'] == 'none') ? '🔴 Не подключено' : '🟢 Подключено', 'callback_data' => 'change_test_account_panel'], ['text' => '▫️Подключено к панели :', 'callback_data' => 'null']],
            [['text' => $sql->query("SELECT * FROM `test_account`")->num_rows, 'callback_data' => 'null'], ['text' => '▫️Количество тестовых аккаунтов :', 'callback_data' => 'null']],
            [['text' => $test_account_setting['volume'] . ' GB', 'callback_data' => 'change_test_account_volume'], ['text' => '▫️Объем :', 'callback_data' => 'null']],
            [['text' => $test_account_setting['time'] . ' часов', 'callback_data' => 'change_test_account_time'], ['text' => '▫️Время :', 'callback_data' => 'null']],
        ]]);
        editMessage($from_id, "⏱ Добро пожаловать в настройки тестового аккаунта.\n\n👇🏻 Выберите одну из следующих опций:\n◽️@Proxygram", $message_id, $manage_test_account);
    }
    
    elseif ($data == 'change_test_account_volume') {
        step('change_test_account_volume');
        editMessage($from_id, "🆕 Отправьте новое значение в виде целого числа:", $message_id, $back_account_test);
    }
    
    elseif ($user['step'] == 'change_test_account_volume') {
        if (isset($text)) {
            if (is_numeric($text)) {
                step('none');
                $sql->query("UPDATE `test_account_setting` SET `volume` = '$text'");
                $manage_test_account = json_encode(['inline_keyboard' => [
                    [['text' => ($status == 'active') ? '🔴' : '🟢', 'callback_data' => 'change_test_account_status'], ['text' => '▫️Состояние :', 'callback_data' => 'null']],
                    [['text' => ($test_account_setting['panel'] == 'none') ? '🔴 Не подключено' : '🟢 Подключено', 'callback_data' => 'change_test_account_panel'], ['text' => '▫️Подключено к панели :', 'callback_data' => 'null']],
                    [['text' => $sql->query("SELECT * FROM `test_account`")->num_rows, 'callback_data' => 'null'], ['text' => '▫️Количество тестовых аккаунтов :', 'callback_data' => 'null']],
                    [['text' => $text . ' GB', 'callback_data' => 'change_test_account_volume'], ['text' => '▫️Объем :', 'callback_data' => 'null']],
                    [['text' => $test_account_setting['time'] . ' часов', 'callback_data' => 'change_test_account_time'], ['text' => '▫️Время :', 'callback_data' => 'null']],
                ]]);
                sendMessage($from_id, "✅ Операция изменения успешно выполнена.\n\n👇🏻 Выберите одну из следующих опций.\n◽️@Proxygram", $manage_test_account);
            } else {
                sendMessage($from_id, "❌ Неверный ввод!", $back_account_test);
            }
        }
    }
    
    elseif ($data == 'change_test_account_time') {
        step('change_test_account_time');
        editMessage($from_id, "🆕 Отправьте новое значение в виде целого числа:", $message_id, $back_account_test);
    }
    
    elseif ($user['step'] == 'change_test_account_time') {
        if (isset($text)) {
            if (is_numeric($text)) {
                step('none');
                $sql->query("UPDATE `test_account_setting` SET `time` = '$text'");
                $manage_test_account = json_encode(['inline_keyboard' => [
                    [['text' => ($status == 'active') ? '🔴' : '🟢', 'callback_data' => 'change_test_account_status'], ['text' => '▫️Состояние :', 'callback_data' => 'null']],
                    [['text' => ($test_account_setting['panel'] == 'none') ? '🔴 Не подключено' : '🟢 Подключено', 'callback_data' => 'change_test_account_panel'], ['text' => '▫️Подключено к панели :', 'callback_data' => 'null']],
                    [['text' => $sql->query("SELECT * FROM `test_account`")->num_rows, 'callback_data' => 'null'], ['text' => '▫️Количество тестовых аккаунтов :', 'callback_data' => 'null']],
                    [['text' => $test_account_setting['volume'] . ' GB', 'callback_data' => 'change_test_account_volume'], ['text' => '▫️Объем :', 'callback_data' => 'null']],
                    [['text' => $text . ' часов', 'callback_data' => 'change_test_account_time'], ['text' => '▫️Время :', 'callback_data' => 'null']],
                ]]);
                sendMessage($from_id, "✅ Операция изменения успешно выполнена.\n\n👇🏻 Выберите одну из следующих опций.\n◽️@Proxygram", $manage_test_account);
            } else {
                sendMessage($from_id, "❌ Неверный ввод!", $back_account_test);
            }
        }
    }
    
    elseif ($data == 'change_test_account_panel') {
        $panels = $sql->query("SELECT * FROM `panels`");
        if ($panels->num_rows > 0) {
            step('change_test_account_panel');
            while ($row = $panels->fetch_assoc()) {
                $key[] = [['text' => $row['name'], 'callback_data' => 'select_test_panel-'.$row['code']]];
            }
            $key[] = [['text' => '🔙 Назад', 'callback_data' => 'back_account_test']];
            $key = json_encode(['inline_keyboard' => $key]);
            editMessage($from_id, "🔧 Выберите одну из панелей для раздела тестового аккаунта:", $message_id, $key);
        } else {
            alert('❌ Нет зарегистрированных панелей в боте!');
        }
    }
    
    elseif (strpos($data, 'select_test_panel-') !== false) {
        $code = explode('-', $data)[1];
        $panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'");
        if ($panel->num_rows > 0) {
            $sql->query("UPDATE `test_account_setting` SET `panel` = '$code'");
            $panel = $panel->fetch_assoc();
            $manage_test_account = json_encode(['inline_keyboard' => [
                [['text' => ($test_account_setting['status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_test_account_status'], ['text' => '▫️Состояние :', 'callback_data' => 'null']],
                [['text' => $panel['name'], 'callback_data' => 'change_test_account_panel'], ['text' => '▫️Подключено к панели :', 'callback_data' => 'null']],
                [['text' => $sql->query("SELECT * FROM `test_account`")->num_rows, 'callback_data' => 'null'], ['text' => '▫️Количество тестовых аккаунтов :', 'callback_data' => 'null']],
                [['text' => $test_account_setting['volume'] . ' GB', 'callback_data' => 'change_test_account_volume'], ['text' => '▫️Объем :', 'callback_data' => 'null']],
                [['text' => $test_account_setting['time'] . ' часов', 'callback_data' => 'change_test_account_time'], ['text' => '▫️Время :', 'callback_data' => 'null']],
            ]]);
            editMessage($from_id, "✅ Операция изменения успешно выполнена.\n\n👇🏻 Выберите одну из следующих опций.\n◽️@Proxygram", $message_id, $manage_test_account);
        } else {
            alert('❌ Панель не найдена!');
        }
    }
    
    elseif  ($text == '➕ Добавить сервер') {
        step('add_server_select');
        sendMessage($from_id, "ℹ️ Какую из следующих панелей вы хотите добавить?", $select_panel);
    }

    # ------------- hedifay ------------- #
    elseif ($data == 'hedifay') {
        alert('❌ Мы еще работаем над этим разделом. Пожалуйста, будьте терпеливы!', true);
        exit();
        // step('add_server_hedifay');
        // deleteMessage($from_id, $message_id);
        // sendMessage($from_id, "‌👈🏻⁩ Пожалуйста, отправьте свое имя панели: ↓\n\nПример имени: 🇳🇱 - Нидерланды\n• Это имя будет отображаться для пользователей.", $cancel_add_server);
    }

    elseif ($user['step'] == 'add_server_hedifay') {
        if ($sql->query("SELECT `name` FROM `panels` WHERE `name` = '$text'")->num_rows == 0) {
            step('send_address_hedifay');
            file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
            sendMessage($from_id, "🌐 Пожалуйста, отправьте адрес входа в панель.\n\n- пример:\n\n<code>https://1.1.1.1.sslip.io/8itQkDU30qCOwzUkK3LnMf58qfsw/175dbb13-95d7-3807-a987-gbs3434bd1b412/admin</code>", $cancel_add_server);
        } else {
            sendMessage($from_id, "❌ Панель с именем [ <b>$text</b> ] уже зарегистрирована в боте!", $cancel_add_server);
        }
    }

    elseif ($user['step'] == 'send_address_hedifay') {
        if (strlen($text) > 50 and substr($text, -1) != '/') {
            if (checkUrl($text) == 200) {
                $info = explode("\n", file_get_contents('add_panel.txt'));
                preg_match('#https:\/\/.*?\/(.*)\/admin#', $text, $matches);
                $token = $matches[1];
                $code = rand(111111, 999999);
                $sql->query("INSERT INTO `hiddify_panels` (`name`, `login_link`, `token`, `code`, `status`, `type`) VALUES ('{$info[0]}', '$text', '$token', '$code', 'active', 'hiddify')");
                sendMessage($from_id, "✅ Ваша панель успешно добавлена в бот!", $manage_server);
            }
        } else {
            sendMessage($from_id, "❌ Ваш отправленный адрес неверен!", $cancel_add_server);
        }
    }

    # ------------- sanayi ------------- #

    elseif ($data == 'sanayi') {
        step('add_server_sanayi');
        deleteMessage($from_id, $message_id);
        sendMessage($from_id, "‌👈🏻 Пожалуйста, отправьте свое имя панели: ↓\n\nПример имени: 🇳🇱 - Нидерланды\n• Это имя будет отображаться для пользователей.", $cancel_add_server);
    }
    
    elseif ($user['step'] == 'add_server_sanayi') {
        if ($sql->query("SELECT `name` FROM `panels` WHERE `name` = '$text'")->num_rows == 0) {
            step('send_address_sanayi');
            file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
            sendMessage($from_id, "🌐 Пожалуйста, отправьте ссылку для входа в панель.\n\n- пример:\n http://1.1.1.1:8000\n http://1.1.1.1:8000/vrshop\n http://domain.com:8000", $cancel_add_server);
        } else {
            sendMessage($from_id, "❌ Панель с именем [ <b>$text</b> ] уже зарегистрирована в боте!", $cancel_add_server);
        }
    }
    
    elseif ($user['step'] == 'send_address_sanayi') {
        if (preg_match("/^(http|https):\/\/(\d+\.\d+\.\d+\.\d+|.*)\:.*$/", $text)) {
            if ($sql->query("SELECT `login_link` FROM `panels` WHERE `login_link` = '$text'")->num_rows == 0) {
                step('send_username_sanayi');
                file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
                sendMessage($from_id, "🔎 - Имя пользователя ( <b>username</b> ) для вашей панели:", $cancel_add_server);
            } else {
            sendMessage($from_id, "❌ Панель с адресом [ <b>$text</b> ] уже зарегистрирована в боте!", $cancel_add_server);
        }
        } else {
            sendMessage($from_id, "🚫 Ваша отправленная ссылка неверна!", $cancel_add_server);
        }
    }
    
    elseif ($user['step'] == 'send_username_sanayi') {
        step('send_password_sanayi');
        file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
        sendMessage($from_id, "🔎 - Пароль ( <b>password</b> ) для вашего сервера:", $cancel_add_server);
    }
    
    elseif ($user['step'] == 'send_password_sanayi') {
        step('none');
        $info = explode("\n", file_get_contents('add_panel.txt'));
        $response = loginPanelSanayi($info[1], $info[2], $text);
        if ($response['success']) {
            $code = rand(11111111, 99999999);
            $session = str_replace([" ", "\n", "\t"], ['', '', ''], explode('session	', file_get_contents('cookie.txt'))[1]);
            $sql->query("INSERT INTO `panels` (`name`, `login_link`, `username`, `password`, `token`, `code`, `status`, `type`) VALUES ('{$info[0]}', '{$info[1]}', '{$info[2]}', '$text', '$session', '$code', 'inactive', 'sanayi')");
            $sql->query("INSERT INTO `sanayi_panel_setting` (`code`, `inbound_id`, `example_link`, `flow`) VALUES ('$code', 'none', 'none', 'offflow')");
            sendMessage($from_id, "✅ Робот успешно вошел в вашу панель!\n\n▫️Имя пользователя : <code>{$info[2]}</code>\n▫️Пароль : <code>{$text}</code>\n▫️Код отслеживания : <code>$code</code>", $manage_server);
        } else {
            sendMessage($from_id, "❌ Ошибка входа в панель, пожалуйста, попробуйте еще раз через несколько минут!\n\n🎯 Возможные причины неподключения робота к вашей панели:↓\n\n◽ Закрытый порт\n◽ Неверный адрес\n◽ Неверный отправленный адрес\n◽ Неверное имя пользователя или пароль\n◽ IP-адрес в блок-листе\n◽️ Закрыт доступ CURL\n◽️ Проблемы с хостом", $manage_server);
        }
        foreach (['add_panel.txt', 'cookie.txt'] as $file) if (file_exists($file)) unlink($file);
    }
    
    # ------------- marzban ------------- #
    
    elseif ($data == 'marzban') {
        step('add_server');
        deleteMessage($from_id, $message_id);
        sendMessage($from_id, "‌👈🏻 Пожалуйста, отправьте свое имя панели: ↓\n\nПример имени: 🇳🇱 - Нидерланды\n• Это имя будет отображаться для пользователей.", $cancel_add_server);
    }
    
    elseif ($user['step'] == 'add_server') {
        if ($sql->query("SELECT `name` FROM `panels` WHERE `name` = '$text'")->num_rows == 0) {
            step('send_address');
            file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
            sendMessage($from_id, "🌐 Пожалуйста, отправьте ссылку для входа в панель.\n\n- пример: http://1.1.1.1:8000", $cancel_add_server);
        } else {
            sendMessage($from_id, "❌ Панель с именем [ <b>$text</b> ] уже зарегистрирована в боте!", $cancel_add_server);
        }
    }
    
    elseif ($user['step'] == 'send_address') {
        if (preg_match("/^(http|https):\/\/(\d+\.\d+\.\d+\.\d+|.*)\:\d+$/", $text)) {
            if ($sql->query("SELECT `login_link` FROM `panels` WHERE `login_link` = '$text'")->num_rows == 0) {
                step('send_username');
                file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
                sendMessage($from_id, "🔎 - Имя пользователя ( <b>username</b> ) для вашей панели:", $cancel_add_server);
            } else {
            sendMessage($from_id, "❌ Панель с адресом [ <b>$text</b> ] уже зарегистрирована в боте!", $cancel_add_server);
        }
        } else {
            sendMessage($from_id, "🚫 Ваш отправленный адрес неверен!", $cancel_add_server);
        }
    }
    
    elseif ($user['step'] == 'send_username') {
        step('send_password');
        file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
        sendMessage($from_id, "🔎 - Пароль ( <b>password</b> ) для вашего сервера:", $cancel_add_server);
    }
    
    elseif ($user['step'] == 'send_password') {
        step('none');
        $info = explode("\n", file_get_contents('add_panel.txt'));
        $response = loginPanel($info[1], $info[2], $text);
        if (isset($response['access_token'])) {
            $code = rand(11111111, 99999999);
            $sql->query("INSERT INTO `panels` (`name`, `login_link`, `username`, `password`, `token`, `code`, `type`) VALUES ('{$info[0]}', '{$info[1]}', '{$info[2]}', '$text', '{$response['access_token']}', '$code', 'marzban')");
            sendMessage($from_id, "✅ Робот успешно вошел в вашу панель!\n\n▫️Имя пользователя : <code>{$info[2]}</code>\n▫️Пароль : <code>{$text}</code>\n▫️Код отслеживания : <code>$code</code>", $manage_server);
        } else {
            sendMessage($from_id, "❌ Ошибка входа в панель, пожалуйста, попробуйте еще раз через несколько минут!\n\n🎯 Возможные причины неподключения робота к вашей панели:↓\n\n◽ Закрытый порт\n◽ Неверный адрес\n◽ Неверный отправленный адрес\n◽ Неверное имя пользователя или пароль\n◽ IP-адрес в блок-листе\n◽️ Закрыт доступ CURL\n◽️ Проблемы с хостом", $manage_server);
        }
        if (file_exists('add_panel.txt')) unlink('add_panel.txt');
    }
    
    # ------------------------------------ #
    
    elseif ($text == '🎟 Добавить план') {
        step('none');
        sendMessage($from_id, "ℹ️ Какой тип плана вы хотите добавить?\n\n👇🏻 Выберите один из следующих вариантов:", $add_plan_button);
    }

    elseif ($data == 'add_buy_plan') { 
        step('add_name');
        deleteMessage($from_id, $message_id);
        sendMessage($from_id, "👇🏻Отправьте название этой категории:↓", $back_panel);
    }
    
    elseif ($user['step'] == 'add_name' and $text != '⬅️ Назад к управлению') {
        step('add_limit');
        file_put_contents('add_plan.txt', "$text\n", FILE_APPEND);
        sendMessage($from_id, "👇🏻Отправьте свой объем в виде целого латинского числа:↓\n\n◽Пример: <code>50</code>", $back_panel);
    }
    
    elseif ($user['step'] == 'add_limit' and $text != '⬅️ Назад к управлению') {
        step('add_date');
        file_put_contents('add_plan.txt', "$text\n", FILE_APPEND);
        sendMessage($from_id, "👇🏻Отправьте свою дату в виде целого латинского числа:↓\n\n◽Пример: <code>30</code>", $back_panel);
    }
    
    elseif ($user['step'] == 'add_date' and $text != '⬅️ Назад к управлению') {
        step('add_price');
        file_put_contents('add_plan.txt', "$text\n", FILE_APPEND);
        sendMessage($from_id, "💸Отправьте сумму этого объема в виде целого латинского числа:↓\n\n◽Пример: <code>60000</code>", $back_panel);
    }
    
    elseif ($user['step'] == 'add_price' and $text != '⬅️ Назад к управлению') {
        step('none');
        $info = explode("\n", file_get_contents('add_plan.txt'));
        $code = rand(1111111, 9999999);
        $sql->query("INSERT INTO `category` (`limit`, `date`, `name`, `price`, `code`, `status`) VALUES ('{$info[1]}', '{$info[2]}', '{$info[0]}', '$text', '$code', 'active')");
        sendmessage($from_id, "✅ Ваши данные успешно зарегистрированы и добавлены в список.\n\n◽Отправленный объем : <code>{$info[1]}</code>\n◽Отправленная цена : <code>$text</code>", $manage_server);
        if (file_exists('add_plan.txt')) unlink('add_plan.txt');
    }

    elseif ($data == 'add_limit_plan') { 
        step('add_name_limit');
        deleteMessage($from_id, $message_id);
        sendMessage($from_id, "👇🏻Отправьте название этой категории:↓", $back_panel);
    }
    
    elseif ($user['step'] == 'add_name_limit' and $text != '⬅️ Назад к управлению') {
        step('add_limit_limit');
        file_put_contents('add_plan_limit.txt', "$text\n", FILE_APPEND);
        sendMessage($from_id, "👇🏻Отправьте свой объем в виде целого латинского числа:↓\n\n◽Пример: <code>50</code>", $back_panel);
    }
    
    elseif ($user['step'] == 'add_limit_limit' and $text != '⬅️ Назад к управлению') {
        step('add_price_limit');
        file_put_contents('add_plan_limit.txt', "$text\n", FILE_APPEND);
        sendMessage($from_id, "💸Отправьте сумму этого объема в виде целого латинского числа:↓\n\n◽Пример: <code>60000</code>", $back_panel);
    }
    
    elseif ($user['step'] == 'add_price_limit' and $text != '⬅️ Назад к управлению') {
        step('none');
        $info = explode("\n", file_get_contents('add_plan_limit.txt'));
        $code = rand(1111111, 9999999);
        $sql->query("INSERT INTO `category_limit` (`limit`, `name`, `price`, `code`, `status`) VALUES ('{$info[1]}', '{$info[0]}', '$text', '$code', 'active')");
        sendmessage($from_id, "✅ Ваши данные успешно зарегистрированы и добавлены в список.\n\n◽Отправленный объем : <code>{$info[1]}</code>\n◽Отправленная цена : <code>$text</code>", $manage_server);
        if (file_exists('add_plan_limit.txt')) unlink('add_plan_limit.txt');
    }

    elseif ($data == 'add_date_plan') { 
        step('add_name_date');
        deleteMessage($from_id, $message_id);
        sendMessage($from_id, "👇🏻Отправьте название этой категории:↓", $back_panel);
    }
    
    elseif ($user['step'] == 'add_name_date' and $text != '⬅️ Назад к управлению') {
        step('add_date_date');
        file_put_contents('add_plan_date.txt', "$text\n", FILE_APPEND);
        sendMessage($from_id, "👇🏻Отправьте свою дату в виде целого латинского числа:↓\n\n◽Пример: <code>30</code>", $back_panel);
    }
    
    elseif ($user['step'] == 'add_date_date' and $text != '⬅️ Назад к управлению') {
        step('add_price_date');
        file_put_contents('add_plan_date.txt', "$text\n", FILE_APPEND);
        sendMessage($from_id, "💸Отправьте сумму этого объема в виде целого латинского числа:↓\n\n◽Пример: <code>60000</code>", $back_panel);
    }
    
    elseif ($user['step'] == 'add_price_date' and $text != '⬅️ Назад к управлению') {
        step('none');
        $info = explode("\n", file_get_contents('add_plan_date.txt'));
        $code = rand(1111111, 9999999);
        $sql->query("INSERT INTO `category_date` (`date`, `name`, `price`, `code`, `status`) VALUES ('{$info[1]}', '{$info[0]}', '$text', '$code', 'active')");
        sendmessage($from_id, "✅ Ваши данные успешно зарегистрированы и добавлены в список.\n\n◽Отправленный объем : <code>{$info[1]}</code>\n◽Отправленная цена : <code>$text</code>", $manage_server);
        if (file_exists('add_plan_date.txt')) unlink('add_plan_date.txt');
    }
    
    elseif ($text == '⚙️ Список серверов' or $data == 'back_panellist') {
        step('none');
        $info_servers = $sql->query("SELECT * FROM `panels`");
        if($info_servers->num_rows == 0){
            if(!isset($data)){
                sendMessage($from_id, "❌ Нет зарегистрированных серверов в боте.");
            }else{
                editMessage($from_id, "❌ Нет зарегистрированных серверов в боте.", $message_id);
            }
            exit();
        }
        $key[] = [['text' => '▫️Статус', 'callback_data' => 'null'], ['text' => '▫️Имя', 'callback_data' => 'null'], ['text' => '▫️Код отслеживания', 'callback_data' => 'null']];
        while($row = $info_servers->fetch_array()){
            $name = $row['name'];
            $code = $row['code'];
            if($row['status'] == 'active') $status = '✅ Активен'; else $status = '❌ Неактивен';
            $key[] = [['text' => $status, 'callback_data' => 'change_status_panel-'.$code], ['text' => $name, 'callback_data' => 'status_panel-'.$code], ['text' => $code, 'callback_data' => 'status_panel-'.$code]];
        }
        $key[] = [['text' => '❌ Закрыть панель | close panel', 'callback_data' => 'close_panel']];
        $key = json_encode(['inline_keyboard' => $key]);
        if(!isset($data)){
            sendMessage($from_id, "🔎 Список зарегистрированных серверов:\n\n⚙️ Вы можете управлять каждым сервером, нажав на его код отслеживания.\n\nℹ️ Нажмите на каждый код отслеживания для управления им.", $key);
        }else{
            editMessage($from_id, "🔎 Список зарегистрированных серверов:\n\n⚙️ Вы можете управлять каждым сервером, нажав на его код отслеживания.\n\nℹ️ Нажмите на каждый код отслеживания для управления им.", $message_id, $key);
        }
    }
    
    elseif (strpos($data, 'change_status_panel-') !== false) {
        $code = explode('-', $data)[1];
        $info_panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'")->fetch_assoc();
        if ($info_panel['type'] == 'sanayi') {
            $sanayi_setting = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '{$info_panel['code']}'")->fetch_assoc();
            if ($sanayi_setting['example_link'] == 'none') {
                alert('🔴 Чтобы включить панель Sanayi, сначала необходимо настроить идентификатор и пример сервиса!');
                exit;
            } elseif ($sanayi_setting['inbound_id'] == 'none') {
                alert('🔴 Чтобы включить панель Sanayi, сначала необходимо настроить идентификатор и пример сервиса!');
                exit;
            }
        }
        $status = $info_panel['status'];
        if ($status == 'active') {
            $sql->query("UPDATE `panels` SET `status` = 'inactive' WHERE `code` = '$code'");
        } else {
            $sql->query("UPDATE `panels` SET `status` = 'active' WHERE `code` = '$code'");
        }
        $key[] = [['text' => '▫️Статус', 'callback_data' => 'null'], ['text' => '▫️Имя', 'callback_data' => 'null'], ['text' => '▫️Код отслеживания', 'callback_data' => 'null']];
        $result = $sql->query("SELECT * FROM `panels`");
        while ($row = $result->fetch_array()) {
            $name = $row['name'];
            $code = $row['code'];
            if ($row['status'] == 'active') $status = '✅ Активно'; else $status = '❌ Неактивно';
            $key[] = [['text' => $status, 'callback_data' => 'change_status_panel-' . $code], ['text' => $name, 'callback_data' => 'status_panel-' . $code], ['text' => $code, 'callback_data' => 'status_panel-' . $code]];
        }
        $key[] = [['text' => '❌ Закрыть панель | закрыть панель', 'callback_data' => 'close_panel']];
        $key = json_encode(['inline_keyboard' => $key]);
        editMessage($from_id, "🔎 Список ваших зарегистрированных серверов:\n\nℹ️ Для управления каждым щелкните по нему.", $message_id, $key);
    }
    
    elseif (strpos($data, 'status_panel-') !== false or strpos($data, 'update_panel-') !== false) {
        alert('🔄 - Пожалуйста, подождите несколько секунд, пока данные загружаются...', false);
    
        $code = explode('-', $data)[1];
        $info_server = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'")->fetch_assoc();
    
        if ($info_server['status'] == 'active') $status = '✅ Активно'; else $status = '❌ Неактивно';
        if (strpos($info_server['login_link'], 'https://') !== false) $status_ssl = '✅ Активно'; else $status_ssl = '❌ Неактивно';
    
        $info = [
            'ip' => explode(':', str_replace(['http://', 'https://'], '', $info_server['login_link']))[0] ?? '⚠️',
            'port' => explode(':', str_replace(['http://', 'https://'], '', $info_server['login_link']))[1] ?? '⚠️',
            'type' => ($info_server['type'] == 'marzban') ? 'Marzban' : 'Sanayi',
        ];
    
        $txt = "Информация о панели [ <b>{$info_server['name']}</b> ] успешно получена.\n\n🔎 Текущий статус в роботе: <b>$status</b>\nℹ️ Код сервера (для получения информации): <code>$code</code>\n\n◽️Тип панели: <b>{$info['type']}</b>\n◽️Локация: <b>{$info_server['name']}</b>\n◽️IP: <code>{$info['ip']}</code>\n◽️Порт: <code>{$info['port']}</code>\n◽️Статус SSL: <b>$status_ssl</b>\n\n🔑 Имя пользователя панели: <code>{$info_server['username']}</code>\n🔑 Пароль панели: <code>{$info_server['password']}</code>";
    
        $protocols = explode('|', $info_server['protocols']);
        unset($protocols[count($protocols) - 1]);
        if (in_array('vmess', $protocols)) $vmess_status = '✅'; else $vmess_status = '❌';
        if (in_array('trojan', $protocols)) $trojan_status = '✅'; else $trojan_status = '❌';
        if (in_array('vless', $protocols)) $vless_status = '✅'; else $vless_status = '❌';
        if (in_array('shadowsocks', $protocols)) $shadowsocks_status = '✅'; else $shadowsocks_status = '❌';
    
        if ($info_server['type'] == 'marzban') {
            $back_panellist = json_encode(['inline_keyboard' => [
                [['text' => '🆙 Обновить информацию', 'callback_data' => 'update_panel-' . $code]],
                [['text' => '🔎 - Статус :', 'callback_data' => 'null'], ['text' => $info_server['status'] == 'active' ? '✅' : '❌', 'callback_data' => 'change_status_panel-' . $code]],
                [['text' => '🎯 - Flow :', 'callback_data' => 'null'], ['text' => $info_server['flow'] == 'flowon' ? '✅' : '❌', 'callback_data' => 'change_status_flow-' . $code]],
                [['text' => '🗑 Удалить панель', 'callback_data' => 'delete_panel-' . $code], ['text' => '✍️ Изменить имя', 'callback_data' => 'change_name_panel-' . $code]],
                [['text' => 'vmess - [' . $vmess_status . ']', 'callback_data' => 'change_protocol|vmess-' . $code], ['text' => 'trojan [' . $trojan_status . ']', 'callback_data' => 'change_protocol|trojan-' . $code], ['text' => 'vless [' . $vless_status . ']', 'callback_data' => 'change_protocol|vless-' . $code]],
                [['text' => 'shadowsocks [' . $shadowsocks_status . ']', 'callback_data' => 'change_protocol|shadowsocks-' . $code]],
                [['text' => 'ℹ️ Управление исходящим трафиком', 'callback_data' => 'manage_marzban_inbound-' . $code], ['text' => '⏺ Настроить исходящий трафик', 'callback_data' => 'set_inbound_marzban-' . $code]],
                [['text' => '🔙 Вернуться к списку панелей', 'callback_data' => 'back_panellist']],
            ]]);
        } elseif ($info_server['type'] == 'sanayi') {
            $back_panellist = json_encode(['inline_keyboard' => [
                [['text' => '🆙 Обновить информацию', 'callback_data' => 'update_panel-' . $code]],
                [['text' => '🔎 - Статус :', 'callback_data' => 'null'], ['text' => $info_server['status'] == 'active' ? '✅' : '❌', 'callback_data' => 'change_status_panel-' . $code]],
                [['text' => '🗑 Удалить панель', 'callback_data' => 'delete_panel-' . $code], ['text' => '✍️ Изменить имя', 'callback_data' => 'change_name_panel-' . $code]],
                [['text' => '🆔 Настроить исходящий трафик для создания службы', 'callback_data' => 'set_inbound_sanayi-' . $code]],
                [['text' => '🌐 Настроить пример ссылки (служба) для доставки', 'callback_data' => 'set_example_link_sanayi-' . $code]],
                [['text' => 'vmess - [' . $vmess_status . ']', 'callback_data' => 'change_protocol|vmess-' . $code], ['text' => 'trojan [' . $trojan_status . ']', 'callback_data' => 'change_protocol|trojan-' . $code], ['text' => 'vless [' . $vless_status . ']', 'callback_data' => 'change_protocol|vless-' . $code]],
                [['text' => 'shadowsocks [' . $shadowsocks_status . ']', 'callback_data' => 'change_protocol|shadowsocks-' . $code]],
                [['text' => '🔙 Вернуться к списку панелей', 'callback_data' => 'back_panellist']],
            ]]);
        }
        editMessage($from_id, $txt, $message_id, $back_panellist);
    }
    elseif (strpos($data, 'set_inbound_marzban') !== false) {
        $code = explode('-', $data)[1];
        step('send_inbound_marzban-' . $code);
        sendMessage($from_id, "🆕 Отправьте название вашего исходящего (Inbound):\n\n❌ Обратите внимание, что если вы введете название неверно, это может вызвать ошибку при создании сервиса, и также ваш исходящий (Inbound), отправленный вами, должен соответствовать протоколу, который вы активировали для этой панели в роботе.", $back_panel);
    }
    
    elseif (strpos($user['step'], 'send_inbound_marzban') !== false and $text != '✔ Завершить и сохранить') {
        $code = explode('-', $user['step'])[1];
        $rand_code = rand(111111, 999999);
        $panel_fetch = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'")->fetch_assoc();
        $token = loginPanel($panel_fetch['login_link'], $panel_fetch['username'], $panel_fetch['password'])['access_token'];
        $inbounds = inbounds($token, $panel_fetch['login_link']);
        $status = checkInbound(json_encode($inbounds), $text);
        if ($status) {
            $res = $sql->query("INSERT INTO `marzban_inbounds` (`panel`, `inbound`, `code`, `status`) VALUES ('$code', '$text', '$rand_code', 'active')");
            sendMessage($from_id, "✅ Ваш исходящий (Inbound) успешно настроен.\n\n#️⃣ Если вы отправите новый исходящий (Inbound), отправьте его, иначе отправьте команду /end_inbound или нажмите на кнопку ниже.", $end_inbound);
        } else {
            sendMessage($from_id, "🔴 Ваш исходящий (Inbound) не найден!", $end_inbound);
        }
    }
    
    elseif (($text == '✔ Завершить и сохранить' or $text == '/end_inbound') and strpos($user['step'], 'send_inbound_marzban') !== false) {
        step('none');
        sendMessage($from_id, "✅ Все ваши отправленные исходящие (Inbound) сохранены.", $manage_server);
    }
    
    elseif (strpos($data, 'manage_marzban_inbound') !== false) {
        $panel_code = explode('-', $data)[1];
        $fetch_inbounds = $sql->query("SELECT * FROM `marzban_inbounds` WHERE `panel` = '$panel_code'");
        if ($fetch_inbounds->num_rows > 0) {
            while ($row = $fetch_inbounds->fetch_assoc()) {
                $key[] = [['text' => $row['inbound'], 'callback_data' => 'null'], ['text' => '🗑', 'callback_data' => 'delete_marzban_inbound-' . $row['code'] . '-' . $panel_code]];
            }
            $key[] = [['text' => '🔙 Вернуться', 'callback_data' => 'status_panel-' . $panel_code]];
            $key = json_encode(['inline_keyboard' => $key]);
            editMessage($from_id, "🔎 Список всех настроенных исходящих (Inbound) для этой панели:\n\n", $message_id, $key);
        } else {
            alert('❌ Нет настроенных исходящих (Inbound) для этой панели!', true);
        }
    }
    
    elseif (strpos($data, 'delete_marzban_inbound') !== false) {
        $panel_code = explode('-', $data)[2];
        $inbound_code = explode('-', $data)[1];
        $fetch = $sql->query("SELECT * FROM `marzban_inbounds` WHERE `panel` = '$panel_code'");
        if ($fetch->num_rows > 0) {
            alert('✅ Ваш выбранный исходящий (Inbound) успешно удален из базы данных робота.', true);
            $sql->query("DELETE FROM `marzban_inbounds` WHERE `panel` = '$panel_code' AND `code` = '$inbound_code'");
            $key = json_encode(['inline_keyboard' => [[['text' => '🔎', 'callback_data' => 'manage_marzban_inbound-' . $panel_code]]]]);
            editMessage($from_id, "⬅️ Для возврата к списку исходящих (Inbound) нажмите кнопку ниже.", $message_id, $key);
        } else {
            alert('❌ Не удалось найти такой исходящий (Inbound) в базе данных робота!', true);
        }
    }
    
    elseif (strpos($data, 'set_inbound_sanayi') !== false) {
        $code = explode('-', $data)[1];
        step('send_inbound_id-' . $code);
        sendMessage($from_id, "👇 Отправьте ID материнской службы, в которую должны быть добавлены клиенты (ID):", $back_panel);
    }
    
    elseif (strpos($user['step'], 'send_inbound_id') !== false) {
        if (is_numeric($text)) {
            $code = explode('-', $user['step'])[1];
            $info_panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'")->fetch_assoc();
            include_once 'api/sanayi.php';
            $xui = new Sanayi($info_panel['login_link'], $info_panel['token']);
            $id_status = json_decode($xui->checkId($text), true)['status'];
            if ($id_status == true) {
                step('none');
                if ($sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '$code'")->num_rows > 0) {
                    $sql->query("UPDATE `sanayi_panel_setting` SET `inbound_id` = '$text' WHERE `code` = '$code'");
                } else {
                    $sql->query("INSERT INTO `sanayi_panel_setting` (`code`, `inbound_id`, `example_link`, `flow`) VALUES ('$code', '$text', 'none', 'offflow')");
                }
                sendMessage($from_id, "✅ Успешно настроено!", $manage_server);
            } else {
                sendMessage($from_id, "❌ Исходящий (Inbound) с ID <code>$text</code> не найден!", $back_panel);
            }
        } else {
            sendMessage($from_id, "❌ Входное значение должно быть только числом!", $back_panel);
        }
    }
    elseif (strpos($data, 'set_example_link_sanayi') !== false) {
        $code = explode('-', $data)[1];
        step('set_example_link_sanayi-' . $code);
        sendMessage($from_id, "✏️ Отправьте свой образец услуги, учитывая следующие инструкции:\n\n▫️Замените каждую переменную в отправленной ссылке значением s1 и %s2 и т. д.\n\nНапример, полученная ссылка:\n\n<code>vless://a8eff4a8-226d3343bbf-9e9d-a35f362c4cb4@1.1.1.1:2053?security=reality&type=grpc&host=&headerType=&serviceName=xyz&sni=cdn.discordapp.com&fp=chrome&pbk=SbVKOEMjK0sIlbwg4akyBg5mL5KZwwB-ed4eEE7YnRc&sid=&spx=#Proxygram</code>\n\nИ ваша отправленная ссылка в роботе должна быть следующей (примерно):\n\n<code>vless://%s1@%s2?security=reality&type=grpc&host=&headerType=&serviceName=xyz&sni=cdn.discordapp.com&fp=chrome&pbk=SbVKOEMjK0sIlbwg4akyBg5mL5KZwwB-ed4eEE7YnRc&sid=&spx=#%s3</code>\n\n⚠️ Отправьте правильно, иначе робот столкнется с ошибкой при покупке услуги.", $back_panel);
    }
    
    elseif (strpos($user['step'], 'set_example_link_sanayi') !== false) {
        if (strpos($text, '%s1') !== false and strpos($text, '%s3') !== false) {
            step('none');
            $code = explode('-', $user['step'])[1];
            if ($sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '$code'")->num_rows > 0) {
                $sql->query("UPDATE `sanayi_panel_setting` SET `example_link` = '$text' WHERE `code` = '$code'");
            } else {
                $sql->query("INSERT INTO `sanayi_panel_setting` (`code`, `inbound_id`, `example_link`, `flow`) VALUES ('$code', 'none', '$text', 'offflow')");
            }
            sendMessage($from_id, "✅ Успешно настроено!", $manage_server);
        } else {
            sendMessage($from_id, "❌ Ваш отправленный образец ссылки неверен!", $back_panel);
        }
    }
    
    elseif (strpos($data, 'change_status_flow-') !== false) {
        $code = explode('-', $data)[1];
        $info_panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'");
        $status = $info_panel->fetch_assoc()['flow'];
        if ($status == 'flowon') {
            $sql->query("UPDATE `panels` SET `flow` = 'flowoff' WHERE `code` = '$code'");
        } else {
            $sql->query("UPDATE `panels` SET `flow` = 'flowon' WHERE `code` = '$code'");
        }
        $back = json_encode(['inline_keyboard' => [[['text' => '🆙 Обновить информацию', 'callback_data' => 'update_panel-' . $code]]]]);
        editmessage($from_id, '✅ Изменения успешно внесены.', $message_id, $back);
    }
    
    elseif (strpos($data, 'change_protocol|') !== false) {
        $code = explode('-', $data)[1];
        $protocol = explode('-', explode('|', $data)[1])[0];
        $panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code' LIMIT 1")->fetch_assoc();
        $protocols = explode('|', $panel['protocols']);
        unset($protocols[count($protocols) - 1]);
    
        if ($protocol == 'vless') {
            if (in_array($protocol, $protocols)) {
                unset($protocols[array_search($protocol, $protocols)]);
            } else {
                array_push($protocols, $protocol);
            }
        } elseif ($protocol == 'vmess') {
            if (in_array($protocol, $protocols)) {
                unset($protocols[array_search($protocol, $protocols)]);
            } else {
                array_push($protocols, $protocol);
            }
        } elseif ($protocol == 'trojan') {
            if (in_array($protocol, $protocols)) {
                unset($protocols[array_search($protocol, $protocols)]);
            } else {
                array_push($protocols, $protocol);
            }
        } elseif ($protocol == 'shadowsocks') {
            if (in_array($protocol, $protocols)) {
                unset($protocols[array_search($protocol, $protocols)]);
            } else {
                array_push($protocols, $protocol);
            }
        }
    
        $protocols = join('|', $protocols) . '|';
        $sql->query("UPDATE `panels` SET `protocols` = '$protocols' WHERE `code` = '$code' LIMIT 1");
    
        $back = json_encode(['inline_keyboard' => [[['text' => '🆙 Обновить информацию', 'callback_data' => 'update_panel-' . $code]]]]);
        editmessage($from_id, '✅ Состояние протокола успешно изменено.', $message_id, $back);
    }
    
    elseif (strpos($data, 'change_name_panel-') !== false) {
        $code = explode('-', $data)[1];
        step('change_name-' . $code);
        sendMessage($from_id, "🔰Отправьте новое имя панели:", $back_panel);
    }
    
    elseif (strpos($user['step'], 'change_name-') !== false) {
        $code = explode('-', $user['step'])[1];
        step('none');
        $sql->query("UPDATE `panels` SET `name` = '$text' WHERE `code` = '$code'");
        sendMessage($from_id, "✅ Имя панели успешно установлено на [ <b>$text</b> ].", $back_panellist);
    }
    
    elseif (strpos($data, 'delete_panel-') !== false) {
        step('none');
        $code = explode('-', $data)[1];
        $sql->query("DELETE FROM `panels` WHERE `code` = '$code'");
        $info_servers = $sql->query("SELECT * FROM `panels`");
        if ($info_servers->num_rows == 0) {
            if (!isset($data)) {
                sendMessage($from_id, "❌ Нет зарегистрированных серверов.");
            } else {
                editMessage($from_id, "❌ Нет зарегистрированных серверов.", $message_id);
            }
            exit();
        }
        $key[] = [['text' => '▫️Статус', 'callback_data' => 'null'], ['text' => '▫️Имя', 'callback_data' => 'null'], ['text' => '▫️Код отслеживания', 'callback_data' => 'null']];
        while ($row = $info_servers->fetch_array()) {
            $name = $row['name'];
            $code = $row['code'];
            if ($row['status'] == 'active') $status = '✅ Активен'; else $status = '❌ Неактивен';
            $key[] = [['text' => $status, 'callback_data' => 'change_status_panel-' . $code], ['text' => $name, 'callback_data' => 'status_panel-' . $code], ['text' => $code, 'callback_data' => 'status_panel-' . $code]];
        }
        $key[] = [['text' => '❌ Закрыть панель | закрыть панель', 'callback_data' => 'close_panel']];
        $key = json_encode(['inline_keyboard' => $key]);
        if (!isset($data)) {
            sendMessage($from_id, "🔎 Список ваших зарегистрированных серверов:\n\n⚙️ Нажмите на код отслеживания сервера, чтобы перейти в раздел управления сервером.\n\nℹ️ Нажмите на каждый, чтобы управлять им.", $key);
        } else {
            editMessage($from_id, "🔎 Список ваших зарегистрированных серверов:\n\n⚙️ Нажмите на код отслеживания сервера, чтобы перейти в раздел управления сервером.\n\nℹ️ Нажмите на каждый, чтобы управлять им.", $message_id, $key);
        }
    }
    
    elseif ($text == '⚙️ Управление планами' or $data == 'back_cat') {
        step('manage_plans');
        if ($text) {
            sendMessage($from_id, "ℹ️ Какой план вы хотите управлять?\n\n👇🏻 Выберите одну из следующих опций:", $manage_plans);
        } else {
            editMessage($from_id, "ℹ️ Какой план вы хотите управлять?\n\n👇🏻 Выберите одну из следующих опций:", $message_id, $manage_plans);
        }
    }
    
    elseif ($data == 'manage_main_plan') {
        step('manage_main_plan');
        $count = $sql->query("SELECT * FROM `category`")->num_rows;
        if ($count == 0) {
            if (isset($data)) {
                editmessage($from_id, "❌ Список планов пуст.", $message_id);
                exit();
            } else {
                sendmessage($from_id, "❌ Список планов пуст.", $manage_server);
                exit();
            }
        }
        $result = $sql->query("SELECT * FROM `category`");
        $button[] = [['text' => 'Удалить', 'callback_data' => 'null'], ['text' => 'Статус', 'callback_data' => 'null'], ['text' => 'Имя', 'callback_data' => 'null'], ['text' => 'Информация', 'callback_data' => 'null']];
        while ($row = $result->fetch_array()) {
            $status = $row['status'] == 'active' ? '✅' : '❌';
            $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit-' . $row['code']], ['text' => $status, 'callback_data' => 'change_status_cat-' . $row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list-' . $row['code']], ['text' => '👁', 'callback_data' => 'manage_cat-' . $row['code']]];
        }
        $button = json_encode(['inline_keyboard' => $button]);
        $count = $result->num_rows;
        $count_active = $sql->query("SELECT * FROM `category` WHERE `status` = 'active'")->num_rows;
        if (isset($data)) {
            editmessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> шт.\n🔢 Общее количество активных списков: <code>$count_active</code>  шт.", $message_id, $button);
        } else {
            sendMessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> шт.\n🔢 Общее количество активных списков: <code>$count_active</code>  шт.", $button);
        }
    }
    

    elseif ($data == 'manage_limit_plan') {
        step('manage_limit_plan');
        $count = $sql->query("SELECT * FROM `category_limit`")->num_rows;
        if ($count == 0) {
            if(isset($data)){
                editmessage($from_id, "❌ Список планов пуст.", $message_id);
                exit();
            } else {
                sendmessage($from_id, "❌ Список планов пуст.", $manage_server);
                exit();
            }
        }
        $result = $sql->query("SELECT * FROM `category_limit`");
        $button[] = [['text' => 'Удалить', 'callback_data' => 'null'], ['text' => 'Статус', 'callback_data' => 'null'], ['text' => 'Имя', 'callback_data' => 'null'], ['text' => 'Информация', 'callback_data' => 'null']];
        while ($row = $result->fetch_array()) {
            $status = $row['status'] == 'active' ? '✅' : '❌';
            $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit_limit-'.$row['code']], ['text' => $status, 'callback_data' => 'change_status_cat_limit-'.$row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list_limit-'.$row['code']], ['text' => '👁', 'callback_data' => 'manage_cat_limit-'.$row['code']]];
        }
        $button = json_encode(['inline_keyboard' => $button]);
        $count = $result->num_rows;
        $count_active = $sql->query("SELECT * FROM `category_limit` WHERE `status` = 'active'")->num_rows;
        if (isset($data)) {
            editmessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> шт.\n🔢 Общее количество активных списков: <code>$count_active</code>  шт.", $message_id, $button);
        }else{
            sendMessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> шт.\n🔢 Общее количество активных списков: <code>$count_active</code>  шт.", $button);
        }
    }
    
    elseif ($data == 'manage_date_plan') {
        step('manage_date_plan');
        $count = $sql->query("SELECT * FROM `category_date`")->num_rows;
        if ($count == 0) {
            if(isset($data)){
                editmessage($from_id, "❌ Список планов пуст.", $message_id);
                exit();
            } else {
                sendmessage($from_id, "❌ Список планов пуст.", $manage_server);
                exit();
            }
        }
        $result = $sql->query("SELECT * FROM `category_date`");
        $button[] = [['text' => 'Удалить', 'callback_data' => 'null'], ['text' => 'Статус', 'callback_data' => 'null'], ['text' => 'Имя', 'callback_data' => 'null'], ['text' => 'Информация', 'callback_data' => 'null']];
        while ($row = $result->fetch_array()) {
            $status = $row['status'] == 'active' ? '✅' : '❌';
            $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit_date-'.$row['code']], ['text' => $status, 'callback_data' => 'change_status_cat_date-'.$row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list_date-'.$row['code']], ['text' => '👁', 'callback_data' => 'manage_cat_date-'.$row['code']]];
        }
        $button = json_encode(['inline_keyboard' => $button]);
        $count = $result->num_rows;
        $count_active = $sql->query("SELECT * FROM `category_date` WHERE `status` = 'active'")->num_rows;
        if (isset($data)) {
            editmessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> шт.\n🔢 Общее количество активных списков: <code>$count_active</code>  шт.", $message_id, $button);
        }else{
            sendMessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> шт.\n🔢 Общее количество активных списков: <code>$count_active</code>  шт.", $button);
        }
    }
    
    elseif (strpos($data, 'change_status_cat-') !== false) {
        $code = explode('-', $data)[1];
        $info_cat = $sql->query("SELECT * FROM `category` WHERE `code` = '$code' LIMIT 1");
        $status = $info_cat->fetch_assoc()['status'];
        if ($status == 'active') {
            $sql->query("UPDATE `category` SET `status` = 'inactive' WHERE `code` = '$code'");
        } else {
            $sql->query("UPDATE `category` SET `status` = 'active' WHERE `code` = '$code'");
        }
        $button[] = [['text' => 'Удалить', 'callback_data' => 'null'], ['text' => 'Статус', 'callback_data' => 'null'], ['text' => 'Имя', 'callback_data' => 'null'], ['text' => 'Информация', 'callback_data' => 'null']];
        $result = $sql->query("SELECT * FROM `category`");
        while ($row = $result->fetch_array()) {
            $status = $row['status'] == 'active' ? '✅' : '❌';
            $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit-'.$row['code']], ['text' => $status, 'callback_data' => 'change_status_cat-'.$row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list-'.$row['code']], ['text' => '👁', 'callback_data' => 'manage_cat-'.$row['code']]];
        }
        $button = json_encode(['inline_keyboard' => $button]);
        $count = $result->num_rows;
        $count_active = $sql->query("SELECT * FROM `category` WHERE `status` = 'active'")->num_rows;
        if (isset($data)) {
            editmessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> шт.\n🔢 Общее количество активных списков: <code>$count_active</code>  шт.", $message_id, $button);
        }else{
            sendMessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> шт.\n🔢 Общее количество активных списков: <code>$count_active</code>  шт.", $button);
        }
    }
    
    elseif (strpos($data, 'change_status_cat_limit-') !== false) {
        $code = explode('-', $data)[1];
        $info_cat = $sql->query("SELECT * FROM `category_limit` WHERE `code` = '$code' LIMIT 1");
        $status = $info_cat->fetch_assoc()['status'];
        if ($status == 'active') {
            $sql->query("UPDATE `category_limit` SET `status` = 'inactive' WHERE `code` = '$code'");
        } else {
            $sql->query("UPDATE `category_limit` SET `status` = 'active' WHERE `code` = '$code'");
        }
        $button[] = [['text' => 'Удалить', 'callback_data' => 'null'], ['text' => 'Статус', 'callback_data' => 'null'], ['text' => 'Имя', 'callback_data' => 'null'], ['text' => 'Информация', 'callback_data' => 'null']];
        $result = $sql->query("SELECT * FROM `category_limit`");
        while ($row = $result->fetch_array()) {
            $status = $row['status'] == 'active' ? '✅' : '❌';
            $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit_limit-'.$row['code']], ['text' => $status, 'callback_data' => 'change_status_cat_limit-'.$row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list_limit-'.$row['code']], ['text' => '👁', 'callback_data' => 'manage_cat_limit-'.$row['code']]];
        }
        $button = json_encode(['inline_keyboard' => $button]);
        $count = $result->num_rows;
        $count_active = $sql->query("SELECT * FROM `category_limit` WHERE `status` = 'active'")->num_rows;
        if (isset($data)) {
            editmessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> шт.\n🔢 Общее количество активных списков: <code>$count_active</code>  шт.", $message_id, $button);
        }else{
            sendMessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> шт.\n🔢 Общее количество активных списков: <code>$count_active</code>  шт.", $button);
        }
    }
    
    elseif (strpos($data, 'change_status_cat_date-') !== false) {
        $code = explode('-', $data)[1];
        $info_cat = $sql->query("SELECT * FROM `category_date` WHERE `code` = '$code' LIMIT 1");
        $status = $info_cat->fetch_assoc()['status'];
        if ($status == 'active') {
            $sql->query("UPDATE `category_date` SET `status` = 'inactive' WHERE `code` = '$code'");
        } else {
            $sql->query("UPDATE `category_date` SET `status` = 'active' WHERE `code` = '$code'");
        }
        $button[] = [['text' => 'Удалить', 'callback_data' => 'null'], ['text' => 'Статус', 'callback_data' => 'null'], ['text' => 'Имя', 'callback_data' => 'null'], ['text' => 'Информация', 'callback_data' => 'null']];
        $result = $sql->query("SELECT * FROM `category_date`");
        while ($row = $result->fetch_array()) {
            $status = $row['status'] == 'active' ? '✅' : '❌';
            $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit_date-'.$row['code']], ['text' => $status, 'callback_data' => 'change_status_cat_date-'.$row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list_date-'.$row['code']], ['text' => '👁', 'callback_data' => 'manage_cat_date-'.$row['code']]];
        }
        $button = json_encode(['inline_keyboard' => $button]);
        $count = $result->num_rows;
        $count_active = $sql->query("SELECT * FROM `category_date` WHERE `status` = 'active'")->num_rows;
        if (isset($data)) {
            editmessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> шт.\n🔢 Общее количество активных списков: <code>$count_active</code>  шт.", $message_id, $button);
        }else{
            sendMessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> шт.\n🔢 Общее количество активных списков: <code>$count_active</code>  шт.", $button);
        }
    }
    
    elseif (strpos($data, 'delete_limit-') !== false) {
        $code = explode('-', $data)[1];
        $sql->query("DELETE FROM `category` WHERE `code` = '$code' LIMIT 1");
        $count = $sql->query("SELECT * FROM `category`")->num_rows;
        if ($count == 0) {
            editmessage($from_id, "❌ Список планов пуст.", $message_id);
            exit();
        }
        $result = $sql->query("SELECT * FROM `category`");
        while ($row = $result->fetch_array()) {
            $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit-'.$code], ['text' => $row['name'], 'callback_data' => 'manage_list-'.$row['code']]];
        }
        $button = json_encode(['inline_keyboard' => $button]);
        $count = $result->num_rows;
        editmessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> штук", $message_id, $button);
    }
    
    elseif (strpos($data, 'delete_limit_limit-') !== false) {
        $code = explode('-', $data)[1];
        $sql->query("DELETE FROM `category_limit` WHERE `code` = '$code' LIMIT 1");
        $count = $sql->query("SELECT * FROM `category_limit`")->num_rows;
        if ($count == 0) {
            editmessage($from_id, "❌ Список планов пуст.", $message_id);
            exit();
        }
        $result = $sql->query("SELECT * FROM `category_limit`");
        while ($row = $result->fetch_array()) {
            $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit_limit-'.$row['code']], ['text' => $status, 'callback_data' => 'change_status_cat_limit-'.$row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list_limit-'.$row['code']], ['text' => '👁', 'callback_data' => 'manage_cat_limit-'.$row['code']]];
        }
        $button = json_encode(['inline_keyboard' => $button]);
        $count = $result->num_rows;
        editmessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> штук", $message_id, $button);
    }
    
    elseif (strpos($data, 'delete_limit_date-') !== false) {
        $code = explode('-', $data)[1];
        $sql->query("DELETE FROM `category_date` WHERE `code` = '$code' LIMIT 1");
        $count = $sql->query("SELECT * FROM `category_date`")->num_rows;
        if ($count == 0) {
            editmessage($from_id, "❌ Список планов пуст.", $message_id);
            exit();
        }
        $result = $sql->query("SELECT * FROM `category_date`");
        while ($row = $result->fetch_array()) {
            $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit_date-'.$row['code']], ['text' => $status, 'callback_data' => 'change_status_cat_date-'.$row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list_date-'.$row['code']], ['text' => '👁', 'callback_data' => 'manage_cat_date-'.$row['code']]];
        }
        $button = json_encode(['inline_keyboard' => $button]);
        $count = $result->num_rows;
        editmessage($from_id, "🔰Ваш список категорий следующий:\n\n🔢 Общее количество: <code>$count</code> штук", $message_id, $button);
    }
    
    elseif (strpos($data, 'manage_list-') !== false) {
        $code = explode('-', $data)[1];
        $res = $sql->query("SELECT * FROM `category` WHERE `code` = '$code'")->fetch_assoc();
        alert($res['name']);
    }
    
    elseif (strpos($data, 'manage_list_limit-') !== false) {
        $code = explode('-', $data)[1];
        $res = $sql->query("SELECT * FROM `category_limit` WHERE `code` = '$code'")->fetch_assoc();
        alert($res['name']);
    }
    
    elseif (strpos($data, 'manage_list_date-') !== false) {
        $code = explode('-', $data)[1];
        $res = $sql->query("SELECT * FROM `category_date` WHERE `code` = '$code'")->fetch_assoc();
        alert($res['name']);
    }
    
    elseif (strpos($data, 'manage_cat-') !== false) {
        $code = explode('-', $data)[1];
        $res = $sql->query("SELECT * FROM `category` WHERE `code` = '$code'")->fetch_assoc();
        $key = json_encode(['inline_keyboard' => [
            [['text' => 'Дата', 'callback_data' => 'null'], ['text' => 'Объем', 'callback_data' => 'null'], ['text' => 'Цена', 'callback_data' => 'null'], ['text' => 'Название', 'callback_data' => 'null']],
            [['text' => $res['date'], 'callback_data' => 'change_date-'.$res['code']], ['text' => $res['limit'], 'callback_data' => 'change_limit-'.$res['code']], ['text' => $res['price'], 'callback_data' => 'change_price-'.$res['code']], ['text' => '✏️', 'callback_data' => 'change_name-'.$res['code']]],
            [['text' => '⬅️ Назад', 'callback_data' => 'back_cat']],
        ]]);
        editmessage($from_id, "🌐 Информация о плане успешно получена.\n\n▫️Название плана: <b>{$res['name']}</b>\n▫️Объем: <code>{$res['limit']}</code>\n▫️Дата: <code>{$res['date']}</code>\n▫️Цена: <code>{$res['price']}</code>\n\n📎 Вы можете изменить значения, нажав на каждый из них!", $message_id, $key);
    }
    
    elseif (strpos($data, 'manage_cat_date-') !== false) {
        $code = explode('-', $data)[1];
        $res = $sql->query("SELECT * FROM `category_date` WHERE `code` = '$code'")->fetch_assoc();
        $key = json_encode(['inline_keyboard' => [
            [['text' => 'Дата', 'callback_data' => 'null'], ['text' => 'Цена', 'callback_data' => 'null'], ['text' => 'Название', 'callback_data' => 'null']],
            [['text' => $res['date'], 'callback_data' => 'change_date_date-'.$res['code']], ['text' => $res['price'], 'callback_data' => 'change_price_date-'.$res['code']], ['text' => '✏️', 'callback_data' => 'change_name_date-'.$res['code']]],
            [['text' => '⬅️ Назад', 'callback_data' => 'back_cat']],
        ]]);
        editmessage($from_id, "🌐 Информация о плане успешно получена.\n\n▫️Название плана: <b>{$res['name']}</b>\n▫️Дата: <code>{$res['date']}</code>\n▫️Цена: <code>{$res['price']}</code>\n\n📎 Вы можете изменить значения, нажав на каждый из них!", $message_id, $key);
    }
    
    elseif (strpos($data, 'manage_cat_limit-') !== false) {
        $code = explode('-', $data)[1];
        $res = $sql->query("SELECT * FROM `category_limit` WHERE `code` = '$code'")->fetch_assoc();
        $key = json_encode(['inline_keyboard' => [
            [['text' => 'Объем', 'callback_data' => 'null'], ['text' => 'Цена', 'callback_data' => 'null'], ['text' => 'Название', 'callback_data' => 'null']],
            [['text' => $res['limit'], 'callback_data' => 'change_limit_limit-'.$res['code']], ['text' => $res['price'], 'callback_data' => 'change_price_limit-'.$res['code']], ['text' => '✏️', 'callback_data' => 'change_name_limit-'.$res['code']]],
            [['text' => '⬅️ Назад', 'callback_data' => 'back_cat']],
        ]]);
        editmessage($from_id, "🌐 Информация о плане успешно получена.\n\n▫️Название плана: <b>{$res['name']}</b>\n▫️Объем: <code>{$res['limit']}</code>\n▫️Цена: <code>{$res['price']}</code>\n\n📎 Вы можете изменить значения, нажав на каждый из них!", $message_id, $key);
    }
    
    elseif (strpos($data, 'change_date-') !== false) {
        $code = explode('-', $data)[1];
        step('change_date-'.$code);
        sendMessage($from_id, "🔰Отправьте новое значение в виде целого латинского числа:", $back_panel);
    }
    
    elseif (strpos($data, 'change_date_date-') !== false) {
        $code = explode('-', $data)[1];
        step('change_date_date-'.$code);
        sendMessage($from_id, "🔰Отправьте новое значение в виде целого латинского числа:", $back_panel);
    }
    elseif (strpos($data, 'change_limit-') !== false) {
        $code = explode('-', $data)[1];
        step('change_limit-'.$code);
        sendMessage($from_id, "🔰Отправьте новое значение в виде целого латинского числа:", $back_panel);
    }
    
    elseif (strpos($data, 'change_limit_limit-') !== false) {
        $code = explode('-', $data)[1];
        step('change_limit_limit-'.$code);
        sendMessage($from_id, "🔰Отправьте новое значение в виде целого латинского числа:", $back_panel);
    }
    
    elseif (strpos($data, 'change_price-') !== false) {
        $code = explode('-', $data)[1];
        step('change_price-'.$code);
        sendMessage($from_id, "🔰Отправьте новое значение в виде целого латинского числа:", $back_panel);
    }
    
    elseif (strpos($data, 'change_price_date-') !== false) {
        $code = explode('-', $data)[1];
        step('change_price_date-'.$code);
        sendMessage($from_id, "🔰Отправьте новое значение в виде целого латинского числа:", $back_panel);
    }
    
    elseif (strpos($data, 'change_price_limit-') !== false) {
        $code = explode('-', $data)[1];
        step('change_price_limit-'.$code);
        sendMessage($from_id, "🔰Отправьте новое значение в виде целого латинского числа:", $back_panel);
    }
    
    elseif (strpos($data, 'change_name-') !== false) {
        $code = explode('-', $data)[1];
        step('change_namee-'.$code);
        sendMessage($from_id, "🔰Отправьте новое имя:", $back_panel);
    }
    
    elseif (strpos($data, 'change_name_date-') !== false) {
        $code = explode('-', $data)[1];
        step('change_name_date-'.$code);
        sendMessage($from_id, "🔰Отправьте новое имя:", $back_panel);
    }
    
    elseif (strpos($data, 'change_name_limit-') !== false) {
        $code = explode('-', $data)[1];
        step('change_name_limit-'.$code);
        sendMessage($from_id, "🔰Отправьте новое имя:", $back_panel);
    }
    
    elseif (strpos($user['step'], 'change_date-') !== false and $text != '⬅️ Назад к управлению') {
        $code = explode('-', $user['step'])[1];
        step('none');
        $sql->query("UPDATE `category` SET `date` = '$text' WHERE `code` = '$code' LIMIT 1");
        sendMessage($from_id, "✅ Ваши данные успешно сохранены.", $manage_server);
    }
    
    elseif (strpos($user['step'], 'change_date_date-') !== false and $text != '⬅️ Назад к управлению') {
        $code = explode('-', $user['step'])[1];
        step('none');
        $sql->query("UPDATE `category_date` SET `date` = '$text' WHERE `code` = '$code' LIMIT 1");
        sendMessage($from_id, "✅ Ваши данные успешно сохранены.", $manage_server);
    }
    
    elseif (strpos($user['step'], 'change_limit-') !== false and $text != '⬅️ Назад к управлению') {
        $code = explode('-', $user['step'])[1];
        step('none');
        $sql->query("UPDATE `category` SET `limit` = '$text' WHERE `code` = '$code' LIMIT 1");
        sendMessage($from_id, "✅ Ваши данные успешно сохранены.", $manage_server);
    }
    
    elseif (strpos($user['step'], 'change_limit_limit-') !== false and $text != '⬅️ Назад к управлению') {
        $code = explode('-', $user['step'])[1];
        step('none');
        $sql->query("UPDATE `category_limit` SET `limit` = '$text' WHERE `code` = '$code' LIMIT 1");
        sendMessage($from_id, "✅ Ваши данные успешно сохранены.", $manage_server);
    }
    
    elseif (strpos($user['step'], 'change_price-') !== false and $text != '⬅️ Назад к управлению') {
        $code = explode('-', $user['step'])[1];
        step('none');
        $sql->query("UPDATE `category` SET `price` = '$text' WHERE `code` = '$code' LIMIT 1");
        sendMessage($from_id, "✅ Ваши данные успешно сохранены.", $manage_server);
    }
    
    elseif (strpos($user['step'], 'change_price_date-') !== false and $text != '⬅️ Назад к управлению') {
        $code = explode('-', $user['step'])[1];
        step('none');
        $sql->query("UPDATE `category_date` SET `price` = '$text' WHERE `code` = '$code' LIMIT 1");
        sendMessage($from_id, "✅ Ваши данные успешно сохранены.", $manage_server);
    }
    
    elseif (strpos($user['step'], 'change_price_limit-') !== false and $text != '⬅️ Назад к управлению') {
        $code = explode('-', $user['step'])[1];
        step('none');
        $sql->query("UPDATE `category_limit` SET `price` = '$text' WHERE `code` = '$code' LIMIT 1");
        sendMessage($from_id, "✅ Ваши данные успешно сохранены.", $manage_server);
    }
    
    elseif (strpos($user['step'], 'change_namee-') !== false and $text != '⬅️ Назад к управлению') {
        $code = explode('-', $user['step'])[1];
        step('none');
        $sql->query("UPDATE `category` SET `name` = '$text' WHERE `code` = '$code' LIMIT 1");
        sendMessage($from_id, "✅ Ваши данные успешно сохранены.", $manage_server);
    }
    
    elseif (strpos($user['step'], 'change_name_date-') !== false and $text != '⬅️ Назад к управлению') {
        $code = explode('-', $user['step'])[1];
        step('none');
        $sql->query("UPDATE `category_date` SET `name` = '$text' WHERE `code` = '$code' LIMIT 1");
        sendMessage($from_id, "✅ Ваши данные успешно сохранены.", $manage_server);
    }
    
elseif (strpos($user['step'], 'change_name_limit-') !== false and $text != '⬅️ Назад к управлению') {
    $code = explode('-', $user['step'])[1];
    step('none');
    $sql->query("UPDATE `category_limit` SET `name` = '$text' WHERE `code` = '$code' LIMIT 1");
    sendMessage($from_id, "✅ Ваши данные успешно сохранены.", $manage_server);
}

// ----------- Управление сообщениями ----------- //
elseif ($text == '🔎 Статус отправки / массового пересылки') {
    $info_send = $sql->query("SELECT * FROM `sends`")->fetch_assoc();
    if ($info_send['send'] == 'yes') $send_status = '✅'; else $send_status = '❌';
    if ($info_send['step'] == 'send') $status_send = '✅'; else $status_send = '❌';
    if ($info_send['step'] == 'forward') $status_forward = '✅'; else $status_forward = '❌';
    sendMessage($from_id, "👇🏻Статус ваших отправок описан ниже:\n\nℹ️ В очереди на отправку/пересылку : <b>$send_status</b>\n⬅️ Массовая отправка : <b>$status_send</b>\n⬅️ Массовая пересылка : <b>$status_forward</b>\n\n🟥 Чтобы отменить массовую отправку/пересылку, отправьте команду /cancel_send.", $manage_message);
}

elseif ($text == '/cancel_send') {
    $sql->query("UPDATE `sends` SET `send` = 'no', `text` = 'null', `type` = 'null', `step` = 'null'");
    sendMessage($from_id, "✅ Ваша массовая отправка/пересылка успешно отменена.", $manage_message);
}

elseif ($text == '📬 Массовая отправка') {
    step('send_all');
    sendMessage($from_id, "👇 Отправьте свой текст в виде сообщения:", $back_panel);
}

elseif ($user['step'] == 'send_all') {
    step('none');
    if (isset($update->message->text)) {
        $type = 'text';
    } else {
        $type = $update->message->photo[count($update->message->photo) - 1]->file_id;
        $text = $update->message->caption;
    }
    $sql->query("UPDATE `sends` SET `send` = 'yes', `text` = '$text', `type` = '$type', `step` = 'send'");
    sendMessage($from_id, "✅ Ваше сообщение успешно добавлено в очередь для массовой отправки!", $manage_message);
}

elseif ($text == '📬 Массовая пересылка') {
    step('for_all');
    sendMessage($from_id, "👈🏻⁩ Перешлите свой текст:", $back_panel);
}

elseif ($user['step'] == 'for_all') {
    step('none');
    sendMessage($from_id, "✅ Ваше сообщение успешно добавлено в очередь для массовой пересылки!", $panel);
    $sql->query("UPDATE `sends` SET `send` = 'yes', `text` = '$message_id', `type` = '$from_id', `step` = 'forward'");
}

elseif ($text == '📞 Отправить сообщение пользователю' or $text == '📤 Отправить сообщение пользователю') {
    step('sendmessage_user1');
    sendMessage($from_id, "🔢 Отправьте числовой идентификатор желаемого пользователя:", $back_panel);
}

elseif ($user['step'] == 'sendmessage_user1' and $text != '⬅️ Назад к управлению') {
    if ($sql->query("SELECT `from_id` FROM `users` WHERE `from_id` = '$text'")->num_rows > 0) {
        step('sendmessage_user2');
        file_put_contents('id.txt', $text);
        sendMessage($from_id, "👇 Отправьте свое сообщение в виде текста:", $back_panel);
    } else {
        step('sendmessage_user1');
        sendMessage($from_id, "❌ Указанный вами числовой идентификатор не является участником бота!", $back_panel);
    }
}

elseif ($user['step'] == 'sendmessage_user2' and $text != '⬅️ Назад к управлению') {
    step('none');
    $id = file_get_contents('id.txt');
    sendMessage($from_id, "✅ Ваше сообщение успешно отправлено пользователю <code>$id</code>.", $manage_message);
    if (isset($update->message->text)) {
        sendmessage($id, $text);
    } else {
        $file_id = $update->message->photo[count($update->message->photo) - 1]->file_id;
        $caption = $update->message->caption;
        bot('sendphoto', ['chat_id' => $id, 'photo' => $file_id, 'caption' => $caption]);
    }
    unlink('id.txt');
}
 // ----------- управление пользователями ----------- //
elseif ($text == '🔎 Информация о пользователе') {
    step('info_user');
    sendMessage($from_id, "🔰Отправьте числовой идентификатор пользователя:", $back_panel);
}

elseif ($user['step'] == 'info_user') {
    $info = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
    if ($info->num_rows > 0) {
        step('none');
        $res_get = bot('getchatmember', ['user_id' => $text, 'chat_id' => $text]);
        $first_name = $res_get->result->user->first_name;
        $username = '@' . $res_get->result->user->username;
        $coin = number_format($info->fetch_assoc()['coin']) ?? 0;
        $count_service = $info->fetch_assoc()['count_service'] ?? 0;
        $count_payment = $info->fetch_assoc()['count_charge'] ?? 0;   
        sendMessage($from_id, "⭕️ Информация о пользователе [ <code>$text</code> ] успешно получена.\n\n▫️Имя пользователя: $username\n▫️Никнейм пользователя: <b>$first_name</b>\n▫️Баланс пользователя: <code>$coin</code> томан\n▫️ Количество услуг пользователя: <code>$count_service</code> штук\n▫️Количество платежей пользователя: <code>$count_payment</code> штук", $manage_user);
    } else {
        sendMessage($from_id, "‼ Пользователь <code>$text</code> не является участником робота!", $back_panel);
    }
}

elseif ($text == '➕ Пополнить баланс') {
    step('add_coin');
    sendMessage($from_id, "🔰Отправьте числовой идентификатор пользователя:", $back_panel);
}

elseif ($user['step'] == 'add_coin') {
    $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
    if ($user->num_rows > 0) {
        step('add_coin2');
        file_put_contents('id.txt', $text);
        sendMessage($from_id, "🔎Отправьте сумму, которую хотите добавить:", $back_panel);
    } else {
        sendMessage($from_id, "‼ Пользователь <code>$text</code> не является участником робота!", $back_panel);
    }
}

elseif ($user['step'] == 'add_coin2') {
    step('none');
    $id = file_get_contents('id.txt');
    $sql->query("UPDATE `users` SET `coin` = coin + $text WHERE `from_id` = '$id'");
    sendMessage($from_id, "✅ Успешно выполнено.", $manage_user);
    sendMessage($id, "✅ Ваш счет был пополнен администрацией на сумму <code>$text</code> томан.");
    unlink('id.txt');
}

elseif ($text == '➖ Уменьшить баланс') {
    step('rem_coin');
    sendMessage($from_id, "🔰Отправьте числовой идентификатор пользователя:", $back_panel);
}

elseif ($user['step'] == 'rem_coin' and $text != '⬅️ Назад к управлению') {
    $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
    if ($user->num_rows > 0) {
        step('rem_coin2');
        file_put_contents('id.txt', $text);
        sendMessage($from_id, "🔎Отправьте сумму, которую хотите вычесть:", $back_panel);
    } else {
        sendMessage($from_id, "‼ Пользователь <code>$text</code> не является участником робота!", $back_panel);
    }
}

elseif ($user['step'] == 'rem_coin2' and $text != '⬅️ Назад к управлению') {  
    step('none');
    $id = file_get_contents('id.txt');
    $sql->query("UPDATE `users` SET `coin` = coin - $text WHERE `from_id` = '$id'");
    sendMessage($from_id, "✅ Успешно выполнено.", $manage_user);
    sendMessage($id, "✅ Со счета вас списано <code>$text</code> томанов по решению администрации.");
    unlink('id.txt');
}

elseif (strpos($data, 'cancel_fish') !== false) {
    $id = explode('-', $data)[1];
    editMessage($from_id, "✅ Успешно выполнено !", $message_id);
    sendMessage($id, "❌ Отправленный вами платеж был отменен администрацией из-за ошибки и ваш счет не был пополнен !");
}

elseif (strpos($data, 'accept_fish') !== false) {
    $id = explode('-', $data)[1];
    $price = explode('-', $data)[2];
    $sql->query("UPDATE `users` SET `coin` = coin + $price WHERE `from_id` = '$id'");
    editMessage($from_id, "✅ Успешно выполнено !", $message_id);
    sendMessage($id, "✅ Ваш счет успешно пополнен на сумму <code>$price</code> томанов !");
}

elseif ($text == '❌ Блокировать') {
    step('block');
    sendMessage($from_id, "🔢 Отправьте айди пользователя в виде числа :", $back_panel);
}

elseif ($user['step'] == 'block' and $text != '⬅️ Назад к управлению') {
    $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
    if ($user->num_rows > 0) {
        step('none');
        $sql->query("UPDATE `users` SET `status` = 'inactive' WHERE `from_id` = '$text'");
        sendMessage($from_id, "✅ Пользователь успешно заблокирован.", $manage_user);
    } else {
        sendMessage($from_id, "‼ Пользователь <code>$text</code> не является участником робота !", $back_panel);
    }
}

elseif ($text == '✅ Разблокировать') {
    step('unblock');
    sendmessage($from_id, "🔢 Отправьте айди пользователя в виде числа :", $back_panel);
}

elseif ($user['step'] == 'unblock' and $text != '⬅️ Назад к управлению' ){
    $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
    if ($user->num_rows > 0) {
        step('none');
        $sql->query("UPDATE `users` SET `status` = 'active' WHERE `from_id` = '$text'");
        sendMessage($from_id, "✅ Пользователь успешно разблокирован.", $manage_user);
    } else {
        sendMessage($from_id, "‼ Пользователь <code>$text</code> не является участником робота !", $back_panel);  
    }
}

// ----------- управление настройками ----------- //
elseif ($text == '◽Разделы') {
    sendMessage($from_id, "🔰Этот раздел не завершен!");
}

elseif ($text == '🚫 Управление антиспамом' or $data == 'back_spam') {
    if (isset($text)) {
        sendMessage($from_id, "🚫 Добро пожаловать в раздел управления антиспамом робота!\n\n✏️ Нажмите на любую из кнопок слева, чтобы изменить текущее значение.\n\n👇🏻Выберите один из вариантов: \n◽️@Proxygram", $manage_spam);
    } else {
        editMessage($from_id, "🚫 Добро пожаловать в раздел управления антиспамом робота!\n\n✏️ Нажмите на любую из кнопок слева, чтобы изменить текущее значение.\n\n👇🏻Выберите один из вариантов: \n◽️@Proxygram", $message_id, $manage_spam);
    }
}

elseif ($data == 'change_status_spam') {
    $status = $sql->query("SELECT * FROM `spam_setting`")->fetch_assoc()['status'];
    if ($status == 'active') {
        $sql->query("UPDATE `spam_setting` SET `status` = 'inactive'");
    } elseif ($status == 'inactive') {
        $sql->query("UPDATE `spam_setting` SET `status` = 'active'");
    }
    $manage_spam = json_encode(['inline_keyboard' => [
        [['text' => ($status == 'active') ? '🔴' : '🟢', 'callback_data' => 'change_status_spam'], ['text' => '▫️Статус :', 'callback_data' => 'null']],
        [['text' => ($spam_setting['status'] == 'ban') ? '🚫 Блокировать' : '⚠️ Предупреждать', 'callback_data' => 'change_type_spam'], ['text' => '▫️Модель поведения :', 'callback_data' => 'null']],
        [['text' => $spam_setting['time'] . ' секунд', 'callback_data' => 'change_time_spam'], ['text' => '▫️Время : ', 'callback_data' => 'null']],
        [['text' => $spam_setting['count_message'] . ' сообщений', 'callback_data' => 'change_count_spam'], ['text' => '▫️Количество сообщений : ', 'callback_data' => 'null']],
    ]]);
    editMessage($from_id, "🚫 Добро пожаловать в раздел управления антиспамом робота!\n\n✏️ Нажмите на любую из кнопок слева, чтобы изменить текущее значение.\n\n👇🏻Выберите один из вариантов: \n◽️@Proxygram", $message_id, $manage_spam);
}

elseif ($data == 'change_type_spam') {
    $type = $sql->query("SELECT * FROM `spam_setting`")->fetch_assoc()['type'];
    if ($type == 'ban') {
        $sql->query("UPDATE `spam_setting` SET `type` = 'warn'");
    } elseif ($type == 'warn') {
        $sql->query("UPDATE `spam_setting` SET `type` = 'ban'");
    }
    $manage_spam = json_encode(['inline_keyboard' => [
        [['text' => ($spam_setting['status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_spam'], ['text' => '▫️Статус :', 'callback_data' => 'null']],
        [['text' => ($type == 'ban') ? '⚠️ Предупреждать' : '🚫 Блокировать', 'callback_data' => 'change_type_spam'], ['text' => '▫️Модель поведения :', 'callback_data' => 'null']],
        [['text' => $spam_setting['time'] . ' секунд', 'callback_data' => 'change_time_spam'], ['text' => '▫️Время : ', 'callback_data' => 'null']],
        [['text' => $spam_setting['count_message'] . ' сообщений', 'callback_data' => 'change_count_spam'], ['text' => '▫️Количество сообщений : ', 'callback_data' => 'null']],
    ]]);
    editMessage($from_id, "🚫 Добро пожаловать в раздел управления антиспамом робота!\n\n✏️ Нажмите на любую из кнопок слева, чтобы изменить текущее значение.\n\n👇🏻Выберите один из вариантов: \n◽️@Proxygram", $message_id, $manage_spam);
}

elseif ($data == 'change_count_spam') {
    step('change_count_spam');
    editMessage($from_id, "🆙 Введите новое значение в виде целого числа :", $message_id, $back_spam);
}

elseif ($user['step'] == 'change_count_spam') {
    if (is_numeric($text)) {
        step('none');
        $sql->query("UPDATE `spam_setting` SET `count_message` = '$text'");
        $manage_spam = json_encode(['inline_keyboard' => [
            [['text' => ($spam_setting['status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_spam'], ['text' => '▫️Статус :', 'callback_data' => 'null']],
            [['text' => ($spam_setting['type'] == 'ban') ? '🚫 Блокировать' : '⚠️ Предупреждать', 'callback_data' => 'change_type_spam'], ['text' => '▫️Модель поведения :', 'callback_data' => 'null']],
            [['text' => $spam_setting['time'] . ' секунд', 'callback_data' => 'change_time_spam'], ['text' => '▫️Время : ', 'callback_data' => 'null']],
            [['text' => $text . ' сообщений', 'callback_data' => 'change_count_spam'], ['text' => '▫️Количество сообщений : ', 'callback_data' => 'null']],
        ]]);
        sendMessage($from_id, "✅ Изменения успешно внесены !\n🚫 Добро пожаловать в раздел управления антиспамом робота!\n\n✏️ Нажмите на любую из кнопок слева, чтобы изменить текущее значение.\n\n👇🏻Выберите один из вариантов: \n◽️@Proxygram", $manage_spam);
    } else {
        sendMessage($from_id, "❌ Ваш ввод не является числом !", $back_spam);
    }
}

    
elseif ($data == 'change_time_spam') {
    step('change_time_spam');
    editMessage($from_id, "🆙 Отправьте новое значение в виде целого числа:", $message_id, $back_spam);
}

elseif ($user['step'] == 'change_time_spam') {
    if (is_numeric($text)) {
        step('none');
        $sql->query("UPDATE `spam_setting` SET `time` = '$text'");
        $manage_spam = json_encode(['inline_keyboard' => [
            [['text' => ($spam_setting['status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_spam'], ['text' => '▫️Состояние :', 'callback_data' => 'null']],
            [['text' => ($spam_setting['type'] == 'ban') ? '🚫 Блокировка' : '⚠️ Предупреждение', 'callback_data' => 'change_type_spam'], ['text' => '▫️Метод обработки :', 'callback_data' => 'null']],
            [['text' => $text . ' секунд', 'callback_data' => 'change_time_spam'], ['text' => '▫️Время : ', 'callback_data' => 'null']],
            [['text' => $spam_setting['count_message'] . ' сообщений', 'callback_data' => 'change_count_spam'], ['text' => '▫️Количество сообщений : ', 'callback_data' => 'null']],
        ]]);
        sendMessage($from_id, "✅ Изменения успешно внесены!\n🚫 Добро пожаловать в раздел управления антиспамом бота!\n\n✏️ Нажмите на любую из кнопок слева, чтобы изменить текущее значение.\n\n👇🏻Выберите одну из следующих опций: \n◽️@Proxygram", $manage_spam);
    } else {
        sendMessage($from_id, "❌ Введенное вами число неверно!", $back_spam);
    }
}

elseif ($text == '◽Каналы') {    
    $lockSQL = $sql->query("SELECT `chat_id`, `name` FROM `lock`");
    if (mysqli_num_rows($lockSQL) > 0) {
        $locksText = "☑️ Добро пожаловать в раздел (🔒 Блокировка каналов)\n\n🚦 Инструкция :\n1 - 👁 Нажмите на имя для просмотра каждого.\n2 - Для удаления каждого нажмите на кнопку ( 🗑 )\n3 - Для добавления блокировки нажмите кнопку ( ➕ Добавить блокировку )";
        $button[] = [['text' => '🗝 Имя блокировки', 'callback_data' => 'none'], ['text' => '🗑 Удалить', 'callback_data' => 'none']];
        while ($row = $lockSQL->fetch_assoc()) {
            $name = $row['name'];
            $link = str_replace("@", "", $row['chat_id']);
            $button[] = [['text' => $name, 'url' => "https://t.me/$link"], ['text' => '🗑', 'callback_data' => "remove_lock-{$row['chat_id']}"]];
        }
    } else $locksText = '❌ У вас нет блокировок для удаления и просмотра. Пожалуйста, добавьте через кнопку ( ➕ Добавить блокировку ).';
    $button[] = [['text' => '➕ Добавить блокировку', 'callback_data' => 'addLock']];
    if ($data) editmessage($from_id, $locksText, $message_id, json_encode(['inline_keyboard' => $button]));
    else sendMessage($from_id, $locksText, json_encode(['inline_keyboard' => $button]));
}

elseif($data == 'addLock'){
    step('add_channel');
    deleteMessage($from_id, $message_id);
    sendMessage($from_id, "✔ Отправьте имя вашего канала с символом @:", $back_panel);
}

elseif ($user['step'] == 'add_channel' and $data != 'back_look' and $text != '⬅️ Назад к управлению') {
    if (strpos($text, "@") !== false) { 
        if ($sql->query("SELECT * FROM `lock` WHERE `chat_id` = '$text'")->num_rows == 0) {
            $info_channel = bot('getChatMember', ['chat_id' => $text, 'user_id' => bot('getMe')->result->id]);
            if ($info_channel->result->status == 'administrator') {
                step('none');
                $channel_name = bot('getChat', ['chat_id' => $text])->result->title ?? 'без имени';
                $sql->query("INSERT INTO `lock`(`name`, `chat_id`) VALUES ('$channel_name', '$text')");
                $txt = "✅ Ваш канал успешно добавлен в список обязательных подписок.\n\n🆔 - $text";
                sendmessage($from_id, $txt, $panel);
            } else { 
                sendMessage($from_id, "❌ Робот не является администратором в канале $text!", $back_panel);
            }
        } else {
            sendMessage($from_id, "❌ Этот канал уже зарегистрирован в боте!", $back_panel);
        }
    } else {
        sendmessage($from_id, "❌ Ваш отправленный юзернейм должен содержать символ @!", $back_panel);
    }
}

elseif (strpos($data, "remove_lock-") !== false) {
    $link = explode("-", $data)[1];
    $sql->query("DELETE FROM `lock` WHERE `chat_id` = '$link' LIMIT 1");
    $lockSQL = $sql->query("SELECT `chat_id`, `name` FROM `lock`");
    if (mysqli_num_rows($lockSQL) > 0) {
        $locksText = "☑️ Добро пожаловать в раздел (🔒 Блокировка каналов)\n\n🚦 Инструкция :\n1 - 👁 Нажмите на имя для просмотра каждого.\n2 - Для удаления каждого нажмите на кнопку ( 🗑 )\n3 - Для добавления блокировки нажмите кнопку ( ➕ Добавить блокировку )";
        $button[] = [['text' => '🗝 Имя блокировки', 'callback_data' => 'none'], ['text' => '🗑 Удалить', 'callback_data' => 'none']];
        while ($row = $lockSQL->fetch_assoc()) {
            $name = $row['name'];
            $link = str_replace("@", "", $row['chat_id']);
            $button[] = [['text' => $name, 'url' => "https://t.me/$link"], ['text' => '🗑', 'callback_data' => "remove_lock_{$row['chat_id']}"]];
        }
    } else $locksText = '❌ У вас нет блокировок для удаления и просмотра. Пожалуйста, добавьте через кнопку ( ➕ Добавить блокировку ).';
    $button[] = [['text' => '➕ Добавить блокировку', 'callback_data' => 'addLock']];
    if ($data) editmessage($from_id, $locksText, $message_id, json_encode(['inline_keyboard' => $button]));
    else sendMessage($from_id, $locksText, json_encode(['inline_keyboard' => $button]));
}

// ----------------- Управление оплатой ----------------- //
elseif ($text == '◽Настройки платежного шлюза') {
    sendMessage($from_id, "⚙️️ Добро пожаловать в настройки платежного шлюза.\n\n👇🏻Выберите одну из следующих опций:", $manage_payment);
}

elseif ($text == '✏️ Состояние включено/выключено платежного шлюза бота') {
    sendMessage($from_id, "✏️ Состояние включено/выключено платежного шлюза бота следующее:", $manage_off_on_paymanet);
}

elseif ($data == 'change_status_zarinpal') {
    $status = $sql->query("SELECT * FROM `payment_setting`")->fetch_assoc()['zarinpal_status'];
    if ($status == 'active') {
        $sql->query("UPDATE `payment_setting` SET `zarinpal_status` = 'inactive'");
    } elseif ($status == 'inactive') {
        $sql->query("UPDATE `payment_setting` SET `zarinpal_status` = 'active'");
    }
    $manage_off_on_paymanet = json_encode(['inline_keyboard' => [
        [['text' => ($status == 'inactive') ? '🟢' : '🔴', 'callback_data' => 'change_status_zarinpal'], ['text' => '▫️Заринпал :', 'callback_data' => 'null']],
        [['text' => ($payment_setting['idpay_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_idpay'], ['text' => '▫️Идпей :', 'callback_data' => 'null']],
        [['text' => ($payment_setting['nowpayment_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_nowpayment'], ['text' => ': nowpayment ▫️', 'callback_data' => 'null']],
        [['text' => ($payment_setting['card_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_card'], ['text' => '▫️Карта к карте :', 'callback_data' => 'null']]
    ]]);
    editMessage($from_id, "✏️ Состояние включено/выключено платежного шлюза бота следующее:", $message_id, $manage_off_on_paymanet);
}

elseif ($data == 'change_status_idpay') {
    $status = $sql->query("SELECT * FROM `payment_setting`")->fetch_assoc()['idpay_status'];
    if ($status == 'active') {
        $sql->query("UPDATE `payment_setting` SET `idpay_status` = 'inactive'");
    } elseif ($status == 'inactive') {
        $sql->query("UPDATE `payment_setting` SET `idpay_status` = 'active'");
    }
    $manage_off_on_paymanet = json_encode(['inline_keyboard' => [
        [['text' => ($payment_setting['zarinpal_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_zarinpal'], ['text' => '▫️Заринпал :', 'callback_data' => 'null']],
        [['text' => ($status == 'inactive') ? '🟢' : '🔴', 'callback_data' => 'change_status_idpay'], ['text' => '▫️Идпей :', 'callback_data' => 'null']],
        [['text' => ($payment_setting['nowpayment_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_nowpayment'], ['text' => ': nowpayment ▫️', 'callback_data' => 'null']],
        [['text' => ($payment_setting['card_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_card'], ['text' => '▫️Карта к карте :', 'callback_data' => 'null']]
    ]]);
    editMessage($from_id, "✏️ Состояние включено/выключено платежного шлюза бота следующее:", $message_id, $manage_off_on_paymanet);
}
 
elseif ($data == 'change_status_nowpayment') {
    $status = $sql->query("SELECT * FROM `payment_setting`")->fetch_assoc()['nowpayment_status'];
    if ($status == 'active') {
        $sql->query("UPDATE `payment_setting` SET `nowpayment_status` = 'inactive'");
    } elseif ($status == 'inactive') {
        $sql->query("UPDATE `payment_setting` SET `nowpayment_status` = 'active'");
    }
    $manage_off_on_paymanet = json_encode(['inline_keyboard' => [
        [['text' => ($payment_setting['zarinpal_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_zarinpal'], ['text' => '▫️Зарин Пал :', 'callback_data' => 'null']],
        [['text' => ($payment_setting['idpay_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_idpay'], ['text' => '▫️Айди Пей :', 'callback_data' => 'null']],
        [['text' => ($status == 'inactive') ? '🟢' : '🔴', 'callback_data' => 'change_status_nowpayment'], ['text' => ': nowpayment ▫️', 'callback_data' => 'null']],
        [['text' => ($payment_setting['card_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_card'], ['text' => '▫️Карта к карте :', 'callback_data' => 'null']]
    ]]);
    editMessage($from_id, "✏️ Состояние включения/выключения платежного шлюза бота следующее:", $message_id, $manage_off_on_paymanet);
}

elseif ($data == 'change_status_card') {
    $status = $sql->query("SELECT * FROM `payment_setting`")->fetch_assoc()['card_status'];
    if ($status == 'active') {
        $sql->query("UPDATE `payment_setting` SET `card_status` = 'inactive'");
    } elseif ($status == 'inactive') {
        $sql->query("UPDATE `payment_setting` SET `card_status` = 'active'");
    }
    $manage_off_on_paymanet = json_encode(['inline_keyboard' => [
        [['text' => ($payment_setting['zarinpal_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_zarinpal'], ['text' => '▫️Зарин Пал :', 'callback_data' => 'null']],
        [['text' => ($payment_setting['idpay_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_idpay'], ['text' => '▫️Айди Пей :', 'callback_data' => 'null']],
        [['text' => ($payment_setting['nowpayment_status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_nowpayment'], ['text' => ': nowpayment ▫️', 'callback_data' => 'null']],
        [['text' => ($status == 'inactive') ? '🟢' : '🔴', 'callback_data' => 'change_status_card'], ['text' => '▫️Карта к карте :', 'callback_data' => 'null']]
    ]]);
    editMessage($from_id, "✏️ Состояние включения/выключения платежного шлюза бота следующее:", $message_id, $manage_off_on_paymanet);
}

elseif ($text == '▫️Установка номера карты') {
    step('set_card_number');
    sendMessage($from_id, "🪪 Пожалуйста, отправьте свой номер карты корректно и точно:", $back_panel);
}

elseif ($user['step'] == 'set_card_number') {
    if (is_numeric($text)) {
        step('none');
        $sql->query("UPDATE `payment_setting` SET `card_number` = '$text'");
        sendMessage($from_id, "✅ Ваш номер карты успешно установлен!\n\n◽️Номер карты : <code>$text</code>", $manage_payment);
    } else {
        sendMessage($from_id, "❌ Ваш номер карты введен неверно!", $back_panel);
    }
}

elseif ($text == '▫️Установка владельца номера карты') {
    step('set_card_number_name');
    sendMessage($from_id, "#️⃣ Пожалуйста, отправьте имя владельца карты точно и корректно:", $back_panel);
}

elseif ($user['step'] == 'set_card_number_name') {
    step('none');
    $sql->query("UPDATE `payment_setting` SET `card_number_name` = '$text'");
    sendMessage($from_id, "✅ Владелец номера карты успешно установлен!\n\n◽️Владелец номера карты : <code>$text</code>", $manage_payment);
}

elseif ($text == '◽ NOWPayments') {
    step('set_nowpayment_token');
    sendMessage($from_id, "🔎 Пожалуйста, отправьте свой api_key:", $back_panel);
}

elseif ($user['step'] == 'set_nowpayment_token') {
    step('none');
    $sql->query("UPDATE `payment_setting` SET `nowpayment_token` = '$text'");
    sendMessage($from_id, "✅ Успешно установлено!", $manage_payment);
}

elseif ($text == '▫️Айди Пей') {
    step('set_idpay_token');
    sendMessage($from_id, "🔎 Пожалуйста, отправьте свой api_key Айди Пей:", $back_panel);
}

elseif ($user['step'] == 'set_idpay_token') {
    step('none');
    $sql->query("UPDATE `payment_setting` SET `idpay_token` = '$text'");
    sendMessage($from_id, "✅ Успешно установлено!", $manage_payment);
}

elseif ($text == '▫️Зарин Пал') {
    step('set_zarinpal_token');
    sendMessage($from_id, "🔎 Пожалуйста, отправьте свой api_key Зарин Пал:", $back_panel);
}

elseif ($user['step'] == 'set_zarinpal_token') {
    step('none');
    $sql->query("UPDATE `payment_setting` SET `zarinpal_token` = '$text'");
    sendMessage($from_id, "✅ Успешно установлено!", $manage_payment);
}

// -----------------manage copens ----------------- //
elseif ($text == '🎁 Управление промокодами' or $data == 'back_copen') {
    step('none');
    if (isset($text)) {
        sendMessage($from_id, "🎁 Добро пожаловать в раздел управления промокодами бота!\n\n👇🏻Выберите один из следующих вариантов: \n◽️@Proxygram", $manage_copens);
    } else {
        editMessage($from_id, "🎁 Добро пожаловать в раздел управления промокодами бота!\n\n👇🏻Выберите один из следующих вариантов: \n◽️@Proxygram", $message_id, $manage_copens);
    }
}

elseif ($data == 'add_copen') {
    step('add_copen');
    editMessage($from_id, "🆕 Пожалуйста, отправьте свой промокод:", $message_id, $back_copen);
}

elseif ($user['step'] == 'add_copen') {
    step('send_percent');
    file_put_contents('add_copen.txt', "$text\n", FILE_APPEND);
    sendMessage($from_id, "🔢 Пожалуйста, отправьте процент скидки для промокода [ <code>$text</code> ] в виде целого числа:", $back_copen);
}
    
elseif ($user['step'] == 'send_percent') {
    if (is_numeric($text)) {
        step('send_count_use');
        file_put_contents('add_copen.txt', "$text\n", FILE_APPEND);
        sendMessage($from_id, "🔢 Сколько человек может использовать этот промокод? Отправьте целое число:", $back_copen);
    } else {
        sendMessage($from_id, "❌ Неверный ввод числа!", $back_copen);
    }
}

elseif ($user['step'] == 'send_count_use') {
    if (is_numeric($text)) {
        step('none');
        $copen = explode("\n", file_get_contents('add_copen.txt'));
        $sql->query("INSERT INTO `copens` (`copen`, `percent`, `count_use`, `status`) VALUES ('{$copen[0]}', '{$copen[1]}', '{$text}', 'active')");
        sendMessage($from_id, "✅ Ваш промокод успешно добавлен!", $back_copen);
        unlink('add_copen.txt');
    } else {
        sendMessage($from_id, "❌ Неверный ввод числа!", $back_copen);
    }
}

elseif ($data == 'manage_copens') {
    step('manage_copens');
    $copens = $sql->query("SELECT * FROM `copens`");
    if ($copens->num_rows > 0) {
        $key[] = [['text' => '▫️Удалить', 'callback_data' => 'null'], ['text' => '▫️Статус', 'callback_data' => 'null'], ['text' => '▫️Количество', 'callback_data' => 'null'], ['text' => '▫️Процент', 'callback_data' => 'null'], ['text' => '▫️Код', 'callback_data' => 'null']];
        while ($row = $copens->fetch_assoc()) {
            $key[] = [['text' => '🗑', 'callback_data' => 'delete_copen-'.$row['copen']], ['text' => ($row['status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_copen-'.$row['copen']], ['text' => $row['count_use'], 'callback_data' => 'change_countuse_copen-'.$row['copen']], ['text' => $row['percent'], 'callback_data' => 'change_percent_copen-'.$row['copen']], ['text' => $row['copen'], 'callback_data' => 'change_code_copen-'.$row['copen']]];
        }
        $key[] = [['text' => '🔙 Назад', 'callback_data' => 'back_copen']];
        $key = json_encode(['inline_keyboard' => $key]);
        editMessage($from_id, "✏️ Список всех промокодов:\n\n⬅️ Нажмите на каждый, чтобы изменить текущее значение.\n◽️@Proxygram", $message_id, $key);
    } else {
        alert('❌ Нет зарегистрированных промокодов!');
    }
}

elseif (strpos($data, 'delete_copen-') !== false) {
    $copen = explode('-', $data)[1];
    alert('🗑 Промокод успешно удален.', false);
    $sql->query("DELETE FROM `copens` WHERE `copen` = '$copen'");
    $copens = $sql->query("SELECT * FROM `copens`");
    if ($copens->num_rows > 0) {
        $key[] = [['text' => '▫️Удалить', 'callback_data' => 'null'], ['text' => '▫️Статус', 'callback_data' => 'null'], ['text' => '▫️Количество', 'callback_data' => 'null'], ['text' => '▫️Процент', 'callback_data' => 'null'], ['text' => '▫️Код', 'callback_data' => 'null']];
        while ($row = $copens->fetch_assoc()) {
            $key[] = [['text' => '🗑', 'callback_data' => 'delete_copen-'.$row['copen']], ['text' => ($row['status'] == 'active') ? '🟢' : '🔴', 'callback_data' => 'change_status_copen-'.$row['copen']], ['text' => $row['count_use'], 'callback_data' => 'change_countuse_copen-'.$row['copen']], ['text' => $row['percent'], 'callback_data' => 'change_percent_copen-'.$row['copen']], ['text' => $row['copen'], 'callback_data' => 'change_code_copen-'.$row['copen']]];
        }
        $key[] = [['text' => '🔙 Назад', 'callback_data' => 'back_copen']];
        $key = json_encode(['inline_keyboard' => $key]);
        editMessage($from_id, "✏️ Список всех промокодов:\n\n⬅️ Нажмите на каждый, чтобы изменить текущее значение.\n◽️@Proxygram", $message_id, $key);
    } else {
        editMessage($from_id, "❌ Нет других промокодов.", $message_id, $manage_copens);
    }
}

elseif (strpos($data, 'change_status_copen-') !== false) {
    $copen = explode('-', $data)[1];
    $copen_status = $sql->query("SELECT `status` FROM `copens` WHERE `copen` = '$copen'")->fetch_assoc();
    if ($copen_status['status'] == 'active') {
        $sql->query("UPDATE `copens` SET `status` = 'inactive' WHERE `copen` = '$copen'");    
    } else{
        $sql->query("UPDATE `copens` SET `status` = 'active' WHERE `copen` = '$copen'");
    }
    
    $copens = $sql->query("SELECT * FROM `copens`");
    if ($copens->num_rows > 0) {
        $key[] = [['text' => '▫️Удалить', 'callback_data' => 'null'], ['text' => '▫️Статус', 'callback_data' => 'null'], ['text' => '▫️Количество', 'callback_data' => 'null'], ['text' => '▫️Процент', 'callback_data' => 'null'], ['text' => '▫️Код', 'callback_data' => 'null']];
        while ($row = $copens->fetch_assoc()) {
            if ($row['copen'] == $copen) {
                $status = ($copen_status['status'] == 'active') ? '🔴' : '🟢';
            } else {
                $status = ($row['status'] == 'active') ? '🟢' : '🔴';
            }
            $key[] = [['text' => '🗑', 'callback_data' => 'delete_copen-'.$row['copen']], ['text' => $status, 'callback_data' => 'change_status_copen-'.$row['copen']], ['text' => $row['count_use'], 'callback_data' => 'change_countuse_copen-'.$row['copen']], ['text' => $row['percent'], 'callback_data' => 'change_percent_copen-'.$row['copen']], ['text' => $row['copen'], 'callback_data' => 'change_code_copen-'.$row['copen']]];
        }
        $key[] = [['text' => '🔙 Назад', 'callback_data' => 'back_copen']];
        $key = json_encode(['inline_keyboard' => $key]);
        editMessage($from_id, "✏️ Список всех промокодов:\n\n⬅️ Нажмите на каждый, чтобы изменить текущее значение.\n◽️@Proxygram", $message_id, $key);
    } else {
        editMessage($from_id, "❌ Нет других промокодов.", $message_id, $manage_copens);
    }
}

elseif (strpos($data, 'change_countuse_copen-') !== false) {
    $copen = explode('-', $data)[1];
    step('change_countuse_copen-'.$copen);
    editMessage($from_id, "🔢 Отправьте новое значение:", $message_id, $back_copen);
}

elseif (strpos($user['step'], 'change_countuse_copen-') !== false) {
    if (is_numeric($text)) {
        $copen = explode('-', $user['step'])[1];
        $sql->query("UPDATE `copens` SET `count_use` = '$text' WHERE `copen` = '$copen'");
        sendMessage($from_id, "✅ Операция успешно выполнена.", $manage_copens);
    } else {
        sendMessage($from_id, "❌ Неверный ввод числа!", $back_copen);
    }
}
   
elseif (strpos($data, 'change_percent_copen-') !== false) {
    $copen = explode('-', $data)[1];
    step('change_percent_copen-'.$copen);
    editMessage($from_id, "🔢 Введите новое значение:", $message_id, $back_copen);
}

elseif (strpos($user['step'], 'change_percent_copen-') !== false) {
    if (is_numeric($text)) {
        $copen = explode('-', $user['step'])[1];
        $sql->query("UPDATE `copens` SET `percent` = '$text' WHERE `copen` = '$copen'");
        sendMessage($from_id, "✅ Операция успешно выполнена.", $manage_copens);
    } else {
        sendMessage($from_id, "❌ Неверный ввод! Введите числовое значение.", $back_copen);
    }
}

elseif (strpos($data, 'change_code_copen-') !== false) {
    $copen = explode('-', $data)[1];
    step('change_code_copen-'.$copen);
    editMessage($from_id, "🔢 Введите новое значение:", $message_id, $back_copen);
}

elseif (strpos($user['step'], 'change_code_copen-') !== false) {
    $copen = explode('-', $user['step'])[1];
    $sql->query("UPDATE `copens` SET `copen` = '$text' WHERE `copen` = '$copen'");
    sendMessage($from_id, "✅ Операция успешно выполнена.", $manage_copens);
}

// -----------------управление текстами ----------------- //
elseif ($text == '◽Настройка текстов бота') {
    sendMessage($from_id, "⚙️️ Добро пожаловать в настройки текстов бота.\n\n👇🏻 Выберите одну из следующих опций:", $manage_texts);
}

elseif ($text == '✏️ Текст старта') {
    step('set_start_text');
    sendMessage($from_id, "👇 Отправьте текст старта:", $back_panel);
}

elseif ($user['step'] == 'set_start_text') {
    step('none');
    $texts['start'] = str_replace('
    ', '\n', $text);
    file_put_contents('texts.json', json_encode($texts));
    sendMessage($from_id, "✅ Текст старта успешно установлен!", $manage_texts);
}

elseif ($text == '✏️ Текст тарифов') {
    step('set_tariff_text');
    sendMessage($from_id, "👇 Отправьте текст тарифов услуг:", $back_panel);
}

elseif ($user['step'] == 'set_tariff_text') {
    step('none');
    $texts['service_tariff'] = str_replace('
    ', '\n', $text);
    file_put_contents('texts.json', json_encode($texts));
    sendMessage($from_id, "✅ Текст тарифов услуг успешно установлен!", $manage_text);
}

elseif ($text == '✏️ Текст инструкции по подключению') {
    step('none');
    sendMessage($from_id, "✏️ Выберите, какую часть инструкции по подключению вы хотите настроить?\n\n👇 Выберите один из вариантов:", $set_text_edu);
}

elseif (strpos($data, 'set_edu_') !== false) {
    $sys = explode('_', $data)[2];
    step('set_edu_'.$sys);
    sendMessage($from_id, "👇🏻Отправьте ваш текст правильно:\n\n⬅️ Выбранная операционная система: <b>$sys</b>", $back_panel);
}

elseif (strpos($user['step'], 'set_edu_') !== false) {
    step('none');
    $sys = explode('_', $user['step'])[2];
    $texts['edu_' . $sys] = str_replace('
    ', '\n', $text);
    file_put_contents('texts.json', json_encode($texts));
    sendMessage($from_id, "✅ Ваш текст успешно установлен.\n\n#️⃣ Операционная система: <b>$sys</b>", $manage_texts);
}

// ----------------- Управление администраторами ----------------- //

elseif ($text == '➕ Добавить админа') {
    step('add_admin');
    sendMessage($from_id, "🔰Отправьте идентификатор пользователя в виде числа:", $back_panel);
}

elseif ($user['step'] == 'add_admin' and $text != '⬅️ Назад к управлению') {
    $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
    if($user->num_rows != 0){
        step('none');
        $sql->query("INSERT INTO `admins` (`chat_id`) VALUES ('$text')");
        sendMessage($from_id, "✅ Пользователь <code>$text</code> успешно добавлен в список администраторов.", $manage_admin);
    } else {  
        sendMessage($from_id, "‼ Пользователь <code>$text</code> не является участником бота!", $back_panel);
    }
}

elseif ($text == '➖ Удалить админа') {
    step('rem_admin');
    sendMessage($from_id, "🔰Отправьте идентификатор пользователя в виде числа:", $back_panel);
}

elseif ($user['step'] == 'rem_admin' and $text != '⬅️ Назад к управлению') {
    $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
    if($user->num_rows > 0){
        step('none');
        $sql->query("DELETE FROM `admins` WHERE `chat_id` = '$text'");
        sendMessage($from_id, "✅ Пользователь <code>$text</code> успешно удален из списка администраторов.", $manage_admin);
    } else {
        sendMessage($from_id, "‼ Пользователь <code>$text</code> не является участником бота!", $back_panel);  
    }   
}
    
elseif ($text == '⚙️ Список администраторов') {
    $res = $sql->query("SELECT * FROM `admins`");
    if ($res->num_rows == 0) {
        sendMessage($from_id, "❌ Список администраторов робота пуст.");
        exit();
    }
    while ($row = $res->fetch_array()) {
        $key[] = [['text' => $row['chat_id'], 'callback_data' => 'delete_admin-'.$row['chat_id']]];
    }
    $count = $res->num_rows;
    $key = json_encode(['inline_keyboard' => $key]);
    sendMessage($from_id, "🔰Список администраторов робота:\n\n🔎 Общее количество администраторов: <code>$count</code>", $key);
}


/**
* Project name: Proxygram
* Channel: @Proxygram
* Group: @ProxygramHUB
 * Version: 2.5
**/
