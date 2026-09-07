<?php

namespace Botex\Bot\Command;

use Botex\Telegram\Update;

interface CommandInterface
{
    public static function command(): string;

    public static function button(): string;

    public static function middleware(): array;

    public function handle(Update $update): void;
}
