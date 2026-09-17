<?php

namespace Botex\Telegram\Support;

/**
 * One HTTP call to the Bot API.
 *
 * Always answers with an array, whatever happened. Telegram's own
 * failures already arrive as JSON -- `{"ok":false,"error_code":403,...}`
 * -- and the only thing that did not was a request that never got an
 * answer at all: a timeout, a DNS failure, a truncated body. Those used
 * to come back as `null` from json_decode and blow up in the caller's
 * `: array` return type, which turned a network hiccup into a fatal
 * error in the middle of a webhook.
 *
 * So a transport failure is shaped like an API failure, with
 * error_code 0 -- a code Telegram never uses. Anything that has to tell
 * "the user blocked us" from "the network blinked" can read error_code
 * and get a truthful answer either way; see [[Botex\Broadcast\Outcome]],
 * which is the first thing that had to.
 *
 * The timeouts matter for the same reason. Without them curl waits
 * forever, and a single unanswered request holds a webhook open until
 * PHP gives up on it.
 */
class Request
{
    /** error_code for "there was no answer". Telegram never sends 0. */
    public const NO_RESPONSE = 0;

    /** Seconds to wait for the connection, then for the whole call. */
    public const CONNECT_TIMEOUT = 10;

    public const TIMEOUT = 30;

    private $token;
    private $apiUrl = 'https://api.telegram.org/bot';

    public function __construct($token)
    {
        $this->token = $token;
    }

    public function execute(string $method, array $params = []): array
    {
        $url = $this->apiUrl . $this->token . '/' . $method;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);

        $response = curl_exec($ch);
        $transport = $response === false ? curl_error($ch) : '';

        curl_close($ch);

        if ($transport !== '') {
            return self::noResponse($transport);
        }

        $decoded = json_decode((string) $response, true);

        // A 200 with a body Telegram did not write -- a proxy's error
        // page, a truncated read. Not an API answer, so not reported as
        // one.
        return is_array($decoded) ? $decoded : self::noResponse('unreadable response from the Bot API');
    }

    /** @return array{ok:bool, error_code:int, description:string} */
    private static function noResponse(string $why): array
    {
        return [
            'ok' => false,
            'error_code' => self::NO_RESPONSE,
            'description' => $why,
        ];
    }
}
