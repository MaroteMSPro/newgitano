<?php

namespace App\Core;

/**
 * License validation against ControlCRM panel.
 * - Verifies HMAC signature of the response (prevents fake servers)
 * - Sends current domain for domain binding validation
 * - Caches valid response 5 minutes (graceful degradation if panel unreachable)
 */
class License
{
    private const CONTROL_URL = 'https://controlcrm.luxom.com.ar/api/license/check.php';
    private const HMAC_SECRET = 'hmac_luxom_2026_9xPqR4mTvB7wYeH3jFkN8sZcL1dQ';
    private const CACHE_TTL   = 300;

    public static function boot(): void
    {
        $key = self::getKey();
        if (!$key) {
            self::fail('License key no configurada.');
        }

        $config = self::getConfig($key);

        $_ENV['DB_HOST']        = $config['db']['host'] ?? 'localhost';
        $_ENV['DB_NAME']        = $config['db']['name'] ?? '';
        $_ENV['DB_USER']        = $config['db']['user'] ?? '';
        $_ENV['DB_PASS']        = $config['db']['pass'] ?? '';
        $_ENV['MAX_USERS']      = $config['max_users']     ?? 5;
        $_ENV['MAX_INSTANCES']  = $config['max_instances'] ?? 1;
        $_ENV['TENANT_NAME']    = $config['tenant']        ?? '';
        $_ENV['PLAN']           = $config['plan']          ?? 'Básico';
        $_ENV['PLAN_KEY']       = $config['plan_key']      ?? 'basico';
        $_ENV['EXPIRES_AT']     = $config['expires_at']    ?? '';
        $_ENV['EVO_URL']        = $config['evolution']['url'] ?? '';
        $_ENV['EVO_KEY']        = $config['evolution']['key'] ?? '';
        // Módulos como JSON string para consulta posterior
        $_ENV['MODULOS']        = json_encode($config['modulos'] ?? []);
    }

    private static function getKey(): string
    {
        $file = __DIR__ . '/../../license.php';
        if (!file_exists($file)) return '';

        // Evitar que se ejecute código arbitrario — solo leer la constante
        $content = file_get_contents($file);
        if (preg_match("/define\s*\(\s*['\"]LICENSE_KEY['\"]\s*,\s*['\"]([a-f0-9]+)['\"]\s*\)/", $content, $m)) {
            return $m[1];
        }
        return '';
    }

    private static function getConfig(string $key): array
    {
        $cacheFile = sys_get_temp_dir() . '/crm_license_' . md5($key) . '.json';

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && ($cached['ok'] ?? false)) {
                return $cached;
            }
        }

        $domain = $_SERVER['HTTP_HOST'] ?? 'unknown';

        // Leer versión local
        $versionFile = __DIR__ . '/../../version.json';
        $version = 'unknown';
        if (file_exists($versionFile)) {
            $vj = json_decode(file_get_contents($versionFile), true);
            $version = $vj['version'] ?? 'unknown';
        }

        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => "X-License-Key: $key\r\nX-Domain: $domain\r\nX-CRM-Version: $version\r\n",
            'timeout' => 5,
        ]]);

        $raw = @file_get_contents(self::CONTROL_URL, false, $ctx);

        if ($raw === false) {
            if (file_exists($cacheFile)) {
                $cached = json_decode(file_get_contents($cacheFile), true);
                if ($cached) return $cached;
            }
            self::fail('No se pudo contactar el servidor de licencias.');
        }

        $data = json_decode($raw, true);

        if (!($data['ok'] ?? false)) {
            self::fail($data['message'] ?? 'Error de licencia', $data['status'] ?? 'error');
        }

        // Verificar firma HMAC — previene servidores falsos
        $sig     = $data['sig'] ?? '';
        $payload = $data;
        unset($payload['sig']);
        $expected = hash_hmac('sha256', json_encode($payload), self::HMAC_SECRET);

        if (!hash_equals($expected, $sig)) {
            self::fail('Firma de licencia inválida.', 'invalid_signature');
        }

        file_put_contents($cacheFile, $raw);

        return $data;
    }

    private static function fail(string $message, string $status = 'error'): void
    {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => true, 'status' => $status, 'message' => $message]);
        exit;
    }
}
