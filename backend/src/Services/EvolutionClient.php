<?php

namespace App\Services;

class EvolutionClient
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    public function sendText(string $instance, string $phone, string $text): array
    {
        return $this->request('POST', "/message/sendText/$instance", [
            'number' => $phone,
            'text' => $text,
        ]);
    }

    public function sendMedia(string $instance, string $phone, string $mediaUrl, string $caption = '', string $mediaType = 'image'): array
    {
        return $this->request('POST', "/message/sendMedia/$instance", [
            'number' => $phone,
            'mediatype' => $mediaType,
            'media' => $mediaUrl,
            'caption' => $caption,
        ]);
    }

    public function getStatus(string $instance): array
    {
        return $this->request('GET', "/instance/connectionState/$instance");
    }

    public function getQR(string $instance): array
    {
        return $this->request('GET', "/instance/connect/$instance");
    }

    public function fetchContacts(string $instance): array
    {
        return $this->request('POST', "/chat/findContacts/$instance", []);
    }

    private function request(string $method, string $endpoint, ?array $body = null): array
    {
        $url = $this->baseUrl . $endpoint;

        $opts = [
            'http' => [
                'method' => $method,
                'header' => [
                    "Content-Type: application/json",
                    "apikey: {$this->apiKey}",
                ],
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ];

        if ($body !== null) {
            $opts['http']['content'] = json_encode($body);
        }

        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return ['error' => 'Connection failed', 'url' => $url];
        }

        return json_decode($result, true) ?? ['raw' => $result];
    }
}
