<?php

/**
 * Global error + exception safety net.
 *
 * Registered once, as early as possible (from Env.php, which every DB/auth
 * code path pulls in). Turns an uncaught throwable or a fatal error into:
 *   - a logged entry (always), and
 *   - a themed 500 response — public/errors/500.html for a browser, a small
 *     JSON body for an API request — instead of a white page or a raw stack
 *     trace.
 *
 * Deliberately dependency-free: it must still work when the rest of the app
 * failed to load.
 */
class ErrorHandler
{
    private static bool $registered = false;
    private static bool $handling = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // display_errors is decided lazily in applyDisplayPolicy() — at
        // register time (top of Env.php) the .env has not been parsed yet, so
        // APP_ENV is not yet known. Until then, keep internals out of the
        // response and rely on the log.
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Called once from Env::load() after the .env is parsed: on a local box we
     * want errors inline; on a live box they stay in the log only.
     */
    public static function applyDisplayPolicy(): void
    {
        $isLocal = ((string)($_ENV['APP_ENV'] ?? 'local') === 'local');
        ini_set('display_errors', $isLocal ? '1' : '0');
    }

    public static function handleException(\Throwable $e): void
    {
        error_log(
            'Uncaught ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine()
        );
        self::render();
    }

    public static function handleShutdown(): void
    {
        $err = error_get_last();
        if ($err === null) {
            return;
        }

        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if (($err['type'] & $fatal) === 0) {
            return;
        }

        error_log('Fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        self::render();
    }

    private static function render(): void
    {
        // Guard against a second pass (uncaught exception → handler → shutdown)
        // or a fault raised inside the handler itself.
        if (self::$handling) {
            return;
        }
        self::$handling = true;

        // The response is already on the wire — the fault happened mid-render.
        // Can't send a clean page now; a short note is the most we can add.
        if (headers_sent()) {
            echo "\n<!-- request aborted: internal error -->\n";
            return;
        }

        http_response_code(500);
        header_remove('Location'); // a half-finished redirect must not win

        // Drop any partial output the failed request had already buffered.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (self::wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo '{"success":false,"error":"Internal server error","code":500}';
            return;
        }

        header('Content-Type: text/html; charset=utf-8');

        $page = __DIR__ . '/../../public/errors/500.html';
        if (is_file($page)) {
            readfile($page);
            return;
        }

        echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
           . '<p style="font:600 16px system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1.5rem">'
           . 'Something went wrong on our end. Please try again shortly.</p>';
    }

    private static function wantsJson(): bool
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (strpos($uri, '/api/') !== false) {
            return true;
        }

        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        return stripos($accept, 'application/json') !== false
            && stripos($accept, 'text/html') === false;
    }
}
