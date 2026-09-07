<?php

namespace Botex\Bot\Middleware;

use Botex\Telegram\Update;

interface MiddlewareInterface
{
    /**
     * Return true to let the handler run, false to stop it.
     *
     * A middleware that returns false is responsible for telling
     * the user why the request was rejected.
     */
    public function handle(Update $update): bool;
}
