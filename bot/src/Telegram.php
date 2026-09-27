<?php
/**
 * Минимальный клиент Telegram Bot API (curl, без внешних зависимостей).
 */

class Telegram
{
    private string $token;
    private string $apiBase = 'https://api.telegram.org';
    private $curl;

    public function __construct(string $token, string $proxy = '')
    {
        $this->token = $token;
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10, // быстро отдавать ошибку, если сеть недоступна
            CURLOPT_TIMEOUT        => 65, // больше, чем polling_timeout из config
            CURLOPT_SSL_VERIFYPEER => true, // проверка SSL-сертификата Telegram
        ]);
        if ($proxy !== '') {
            curl_setopt($this->curl, CURLOPT_PROXY, $proxy);
        }
    }

    public function getUpdates(?int $offset = null, int $timeout = 50): array
    {
        $params = ['timeout' => $timeout];
        if ($offset !== null) {
            $params['offset'] = $offset;
        }
        $res = $this->request('getUpdates', $params, true);
        if ($res === null) {
            return null; // физически не достучались (сеть/прокси) — пусть об этом узнает вызывающий код
        }
        return ($res['ok'] ?? false) ? ($res['result'] ?? []) : [];
    }

    public function sendMessage(int $chatId, string $text, array $extra = []): ?array
    {
        return $this->request('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text'    => $text,
        ], $extra));
    }

    public function answerCallbackQuery(string $callbackQueryId): ?array
    {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
        ]);
    }

    /** Замена текста и клавиатуры у существующего сообщения (история/фильтры). */
    public function editMessage(int $chatId, int $messageId, string $text, array $inlineKeyboard): ?array
    {
        return $this->request('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** Информация о боте (диагностика). */
    public function getMe(): ?array
    {
        return $this->request('getMe', []);
    }

    /** Активна ли старая доставка через вебхук (мешает long polling). */
    public function getWebhookInfo(): ?array
    {
        return $this->request('getWebhookInfo', []);
    }

    /** Сброс вебхука, если он остался от прежнего размещения бота. */
    public function deleteWebhook(): ?array
    {
        return $this->request('deleteWebhook', ['drop_pending_updates' => true]);
    }

    /** Снятие inline-клавиатуры с обработанного сообщения (защита от двойного нажатия). */
    public function editMessageReplyMarkup(int $chatId, int $messageId, array $inlineKeyboard = []): ?array
    {
        return $this->request('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function request(string $method, array $params, bool $asQuery = false): ?array
    {
        $url = sprintf('%s/bot%s/%s', $this->apiBase, $this->token, $method);

        if ($asQuery) {
            $url .= '?' . http_build_query($params);
            $body = null;
        } else {
            $body = json_encode($params, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_setopt($this->curl, CURLOPT_POST, true);
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, $body ?? '');
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, $body !== null ? ['Content-Type: application/json'] : []);

        $raw = curl_exec($this->curl);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['ok'])) {
            return null;
        }
        return $decoded;
    }
}