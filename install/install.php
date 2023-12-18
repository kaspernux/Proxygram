<?php

function request($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        error_log('Ошибка cURL: ' . curl_error($ch));
    }
    curl_close($ch);
    return $response;
}

if (isset($_POST['token']) and isset($_POST['admin-id']) and isset($_POST['db-name']) and isset($_POST['db-user']) and isset($_POST['db-pass']) and isset($_POST['install_location'])) {
    if (!file_exists('zanbor.install')) {
        if (file_exists('../config.php') and file_exists('../index.php') and file_exists('../texts.json') and file_exists('../sql/sql.php')) {
            $domain = 'https://' . $_SERVER['HTTP_HOST'] . '/' . explode('/', explode('public_html/', $_SERVER['SCRIPT_FILENAME'])[1])[0];
            
            $getTokenStatus = json_decode(request('https://api.telegram.org/bot' . $_POST['token'] . '/getMe'), true)['ok'];
            if ($getTokenStatus == true) {
                $config_file = file_get_contents('../config.php');
                
                $replace = str_replace(['[*TOKEN*]', '[*DEV*]', '[*DB-NAME*]', '[*DB-USER*]', '[*DB-PASS*]'], [$_POST['token'], $_POST['admin-id'], $_POST['db-name'], $_POST['db-user'], $_POST['db-pass']], file_get_contents('../config.php'));
                file_put_contents('../config.php', $replace);
                
                $webhook = json_decode(request('https://api.telegram.org/bot' . $_POST['token'] . '/setWebhook?url=' . $domain . '/index.php'), true);
                if ($webhook['ok'] == false) {
                    file_put_contents('../config.php', $config_file);
                    die('<h2 style="text-align: center; color: black; font-size: 32px; margin-top: 50px;">Ошибка установки вебхука ❌</h2>');
                }
                
                $connect = json_decode(request($domain . '/sql/sql.php?db_name=' . $_POST['db-name'] . '&db_username=' . $_POST['db-user'] . '&db_password=' . $_POST['db-pass']), true);
                if ($connect['status'] == false) {
                    file_put_contents('../config.php', $config_file);
                    die('<h2 style="text-align: center; color: black; font-size: 32px; margin-top: 50px;">Неверные данные для базы данных ❌</h2>');
                }
                
                file_put_contents('zanbor.install', json_encode(['development' => '@Proxygram', 'install_location' => $_POST['install_location'], 'main_domin' => $domain, 'token' => $_POST['token'], 'dev' => $_POST['admin-id'], 'db_name' => $_POST['db-name'], 'db_username' => $_POST['db-user'], 'db_password' => $_POST['db-pass']], 448));
                $send_message = json_decode(request('https://api.telegram.org/bot' . $_POST['token'] . '/sendMessage?chat_id=' . $_POST['admin-id'] . '&text=' . urlencode(base64_decode('CuKchSDYsdio2KfYqiDYqNinINmF2YjZgdmC24zYqiDZhti12Kgg2LTYry4KCvCfmoAg2LHYqNin2Kog2LHYpyAvc3RhcnQg2qnZhtuM2K8uCgrwn5CdIC0gQFphbmJvclBhbmVsIC0gQFphbmJvclBhbmVsR2FwCg=='))), true);
                print '<h2 style="text-align: center; color: black; font-size: 32px; margin-top: 50px;">Ваш бот успешно установлен ✅</h2>';
                
            } else {
                print '<h2 style="text-align: center; color: black; font-size: 32px; margin-top: 50px;">Неверный токен ❌</h2>';
            }
        } else {
            print '<h2 style="text-align: center; color: black; font-size: 32px; margin-top: 50px;">Основные файлы бота не найдены ❌</h2>';
        }
    } else {
        print '<h2 style="text-align: center; color: black; font-size: 32px; margin-top: 50px;">Бот уже установлен ранее ❌</h2>';
    }
} else {
    print '<h2 style="text-align: center; color: black; font-size: 40px; margin-top: 60px;">Обязательные значения не были правильно отправлены ❌</h2>';
}

?>
