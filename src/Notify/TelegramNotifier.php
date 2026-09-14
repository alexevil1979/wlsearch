<?php

declare(strict_types=1);

namespace Wlsearch\Notify;

use Wlsearch\Support\Env;
use Wlsearch\Support\HttpClient;
use Wlsearch\Support\Settings;

final class TelegramNotifier
{
    private HttpClient $http;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?? new HttpClient([], 20);
    }

    public function send(string $text): bool
    {
        $token = Env::get('TELEGRAM_BOT_TOKEN', '');
        $chat = Settings::get('TELEGRAM_CHAT_ID', Env::get('TELEGRAM_CHAT_ID', ''));
        if ($token === null || $token === '' || $chat === null || $chat === '') {
            return false;
        }

        try {
            $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
            $resp = $this->http->request('POST', $url, [
                'chat_id' => $chat,
                'text' => mb_substr($text, 0, 4000),
                'disable_web_page_preview' => true,
            ], ['Content-Type: application/json', 'Accept: application/json']);
            return $resp['status'] >= 200 && $resp['status'] < 300;
        } catch (\Throwable) {
            return false;
        }
    }
}
