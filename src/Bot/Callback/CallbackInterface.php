<?php

namespace Botex\Bot\Callback;

use Botex\Telegram\Update;

interface CallbackInterface
{
    public static function callback(): string;

    public static function middleware(): array;

    public function handle(Update $update): void;
}
