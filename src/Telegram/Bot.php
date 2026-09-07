<?php

namespace Botex\Telegram;

class Bot
{

    use \Botex\Telegram\Support\Method;
    protected \Botex\Telegram\Support\Request $request;

    public function __construct($token)
    {
        $this->request = new \Botex\Telegram\Support\Request($token);
    }


}