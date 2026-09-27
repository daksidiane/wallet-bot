<?php
/**
 * Диагностика связи бота с Telegram. Запускать через check_telegram.bat.
 * Использование: php check_telegram.php [clear]
 *   clear — сбросить старый webhook (если он мешает long polling).
 * Токен и секреты при выводе не показываются.
 */

require __DIR__ . '/src/Telegram.php';
$config = require __DIR__ . '/config.php';

/** Типовые локальные прокси VPN + прокси из системных настроек Windows. */
function commonProxyCandidates(): array
{
    $c = [
        'http://127.0.0.1:7890',
        'http://127.0.0.1:7897',
        'http://127.0.0.1:10809',
        'socks5://127.0.0.1:10808',
        'socks5://127.0.0.1:1080',
        'http://127.0.0.1:2080',
        'http://127.0.0.1:8080',
    ];
    if (function_exists('shell_exec')) {
        $regEnabled = @shell_exec('reg query "HKCU\Software\Microsoft\Windows\CurrentVersion\Internet Settings" /v ProxyEnable 2>nul');
        $regServer  = @shell_exec('reg query "HKCU\Software\Microsoft\Windows\CurrentVersion\Internet Settings" /v ProxyServer 2>nul');
        if (stripos((string)$regEnabled, '0x1') !== false && is_string($regServer)
            && preg_match('/^[ \t]*ProxyServer[ \t]+REG_SZ[ \t]+(.+)$/mi', $regServer, $m)) {
            foreach (preg_split('/[;=,]/', trim($m[1])) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (preg_match('#^(https?|socks[45])://#i', $part)) {
                    $c[] = $part;
                } else {
                    $c[] = 'http://' . $part;
                    $c[] = 'socks5://' . $part;
                }
            }
        }
    }
    return array_values(array_unique($c));
}

/** getMe через указанный прокси; возвращает адрес прокси при успехе, иначе null. */
function tryProxyGetMe(string $proxy, string $token): ?string
{
    $c = curl_init();
    curl_setopt_array($c, [
        CURLOPT_URL            => 'https://api.telegram.org/bot' . $token . '/getMe',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_PROXY          => $proxy,
    ]);
    $raw = curl_exec($c);
    if ($raw === false) {
        return null;
    }
    $d = json_decode($raw, true);
    return (($d['ok'] ?? false) === true) ? $proxy : null;
}

$proxy = $config['telegram']['proxy'] ?? '';
$tg = new Telegram($config['secrets']['telegram_bot_token'], $proxy);

echo "=== Проверка связи с Telegram ===\n";
if ($proxy !== '') {
    echo "Прокси: {$proxy}\n";
}

$me = $tg->getMe();
if ($me === null) {
    echo "СВЯЗИ НЕТ: api.telegram.org не ответил.\n";

    // Детали прямого подключения (без прокси), чтобы понять причину.
    $c = curl_init();
    curl_setopt_array($c, [
        CURLOPT_URL            => 'https://api.telegram.org/bot' . $config['secrets']['telegram_bot_token'] . '/getMe',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
    ]);
    curl_exec($c);
    $errno = curl_errno($c);
    $err = curl_error($c);
    if ($errno > 0) {
        // curl может вставить URL с токеном в текст ошибки — маскируем.
        $token = $config['secrets']['telegram_bot_token'];
        $err = str_replace($token, '[token]', $err);
        echo "curl errno=$errno: $err\n";
        if ($errno === 60) {
            echo "Частая причина: в PHP не настроен файл корневых сертификатов (curl.cainfo),\n";
            echo "поэтому PHP-curl отвергает ВСЕ https-сайты. Батник подкладывает cacert.pem\n";
            echo "автоматически - если после него ошибка осталась, только тогда это\n";
            echo "перехват/блок (VPN-инжектор и т.п.). Не отключай проверку сертификата.\n";
        }
    }

    // Поиск локального прокси VPN (типовые порты + системный прокси Windows).
    echo "\nИщу работающий локальный прокси...\n";
    $found = null;
    foreach (commonProxyCandidates() as $p) {
        $r = tryProxyGetMe($p, $config['secrets']['telegram_bot_token']);
        if ($r !== null) {
            $found = $p;
            break;
        }
        echo "  нет: $p\n";
    }

    if ($found !== null) {
        echo "\nНАЙДЕН работающий прокси: $found\n";
        echo "Впиши его в secrets.php:\n";
        echo "  'telegram_proxy' => '" . $found . "',\n";
        echo "и запусти start.bat заново.\n";
        exit(0);
    }

    if ($proxy !== '') {
        echo "Прокси из secrets.php ($proxy) тоже не работает.\n";
    }
    echo "\nЛокальный прокси не найден типовыми проверками.\n";
    echo "В настройках VPN-клиента найди поле 'HTTP-порт' или 'Смешанный порт'\n";
    echo "(у Clash/v2ray обычно 7890, 7897, 10809, 1080) и впиши его в secrets.php:\n";
    echo "  'telegram_proxy' => 'http://127.0.0.1:ПОРТ',\n";
    echo "Либо включи в VPN режим TUN / 'вся система' — тогда бот заработает и без прокси.\n";
    exit(1);
}
if (empty($me['ok'])) {
    echo "ОТВЕТ API ЕСТЬ, но токен отклонён:\n  " . ($me['description'] ?? 'no description') . "\n";
    echo "Подсказка: у @BotFather выполни /token и вставь актуальный токен в secrets.php.\n";
    exit(1);
}
$botUser = $me['result']['username'] ?? '?';
echo "OK: бот @" . $botUser . " доступен, токен принят.\n";

$wh = $tg->getWebhookInfo();
$whUrl = $wh['result']['url'] ?? null;
echo "\n=== Вебхук (старая доставка апдейтов) ===\n";
if ($wh === null) {
    echo "СВЯЗИ НЕТ при проверке webhook (см. выше).\n";
    exit(1);
}
if (empty($whUrl)) {
    echo "Вебхук не задан — long polling свободен. Хорошо.\n";
} else {
    echo "ВНИМАНИЕ: задан webhook:\n  " . $whUrl . "\n";
    echo "  pending_updates=" . ($wh['result']['pending_update_count'] ?? 0) . "\n";
    if (!empty($wh['result']['last_error'])) {
        echo "  last_error: " . $wh['result']['last_error'] . "\n";
    }
    echo "Именно из-за этого /start может не отвечать. Сбрось вебхук:\n";
    echo "  check_telegram.bat clear\n";
    if (($argv[1] ?? '') === 'clear') {
        $res = $tg->deleteWebhook();
        if (($res['ok'] ?? false) === true) {
            echo "Вебхук сброшен. Теперь запусти start.bat, /start заработает.\n";
        } else {
            echo "Не удалось сбросить: " . ($res['description'] ?? 'no response') . "\n";
        }
        exit(0);
    }
}