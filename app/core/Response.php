<?php

class Response
{
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function ok(array $data = []): void
    {
        self::json(['success' => true] + $data);
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::json(['success' => false, 'message' => $message] + $extra, $status);
    }
}
