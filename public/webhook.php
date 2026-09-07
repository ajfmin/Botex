<?php

use Botex\Bot\Router;
use Botex\Support\Log\Level;
use Botex\Support\Log\Log;
use Botex\Telegram\Update;

try {

    /** @var Botex\Bot\Feeder $feeder */
    $feeder = require __DIR__ . '/../bootstrap/app.php';

    // Load telegram updates
    $raw = file_get_contents('php://input');
    $data = json_decode(json: $raw, associative: true);

    if (!is_array($data)) {
        http_response_code(200);
        exit;
    }

    // handle telegram update
    $update = new Update($data);

    // Bot is registered by the bootstrap, so the worker and console get it
    // too rather than only the webhook.

    Log::debug('Update received', [
        'type' => $update->isCallback() ? 'callback' : 'message',
        'user' => $update->fromId(),
        'chat' => $update->chatId(),
    ]);

    // feed router by handled update
    $router = $feeder->make(Router::class);
    $router->handle($update);

    http_response_code(200);

} catch (\Throwable $e) {

    // Last line of defence. A handler failure is already on record, with
    // more context, by the time it rethrew its way here, so only anything
    // that died before a handler was reached gets logged at this level.
    if (!Log::seen($e)) {
        Log::exception($e, 'Unhandled webhook failure', Level::Critical, ['type' => 'webhook']);
    }

    http_response_code(400);
    exit;
}
