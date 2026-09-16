<?php

namespace Botex\Telegram\Support;

use Botex\Support\Log\Log;

/**
 * One call to the Bot API.
 *
 * A refused call is **logged, not thrown**. Almost every send in this
 * codebase happens through [[MessageBuilder]]'s destructor, and throwing
 * out of a destructor turns a failed notification into a fatal in the
 * middle of unrelated work. But discarding the answer entirely is worse
 * than either: a screen that silently fails to redraw looks to an admin
 * like a button that does nothing, and there is nothing anywhere to say
 * otherwise. So the response is inspected and anything that is not `ok`
 * ends up in the log with the method that produced it.
 */
class Request
{

    private $token;
    private $apiUrl = 'https://api.telegram.org/bot';

    /** Seconds to wait, so a hung API cannot hold a webhook open. */
    private const TIMEOUT = 20;

    public function __construct($token)
    {
        $this->token = $token;
    }

    public function execute(string $method, array $params = [])
    {
        $url = $this->apiUrl . $this->token . '/' . $method;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);

        $response = curl_exec($ch);
        $error = $response === false ? curl_error($ch) : '';

        curl_close($ch);

        if ($error !== '') {
            Log::error('Telegram ' . $method . ' could not be sent: ' . $error, [
                'method' => $method,
                'chat' => $params['chat_id'] ?? null,
            ]);

            return ['ok' => false, 'description' => $error];
        }

        $decoded = json_decode((string) $response, true);

        if (!is_array($decoded)) {
            Log::error('Telegram ' . $method . ' returned something that was not JSON.', [
                'method' => $method,
                'chat' => $params['chat_id'] ?? null,
            ]);

            return ['ok' => false, 'description' => 'unreadable response'];
        }

        if (($decoded['ok'] ?? false) !== true) {
            // The description is Telegram's own sentence and is usually
            // the whole diagnosis -- "message is not modified", "message
            // to edit not found", "can't parse entities". Without this
            // line those never reach anybody.
            Log::error('Telegram refused ' . $method . ': '
                . (string) ($decoded['description'] ?? 'no description'), [
                    'method' => $method,
                    'chat' => $params['chat_id'] ?? null,
                    'error_code' => $decoded['error_code'] ?? null,
                ]);
        }

        return $decoded;
    }

}
