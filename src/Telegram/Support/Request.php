<?php

namespace Botex\Telegram\Support;

class Request
{

    private $token;
    private $apiUrl = 'https://api.telegram.org/bot';

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

        $response = curl_exec($ch);

        return json_decode($response, true);
    }

}