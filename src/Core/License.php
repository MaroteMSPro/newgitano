<?php

namespace App\Core;

/**
 * License validation contra Control Rocejo.
 * - Lee credenciales DB del .env local
 * - Consulta control.rocejo.com cada 6hrs para límites, vencimiento, webhook_url
 */
class License
{
    private const CONTROL_URL = 'https://control.rocejo.com/api/validate';
    private const HMAC_SECRET = 'ControlRocejo_f81774962e22cf847ed01ded8e3eb679df727a21';
    private const CACHE_TTL   = 21600; // 6 horas

    public static function boot(): void
    {
        // 1. Las credenciales de DB vienen del .env (ya están seteadas)
        // No tocar DB_HOST, DB_NAME, DB_USER, DB_PASS — esos son locales

        // 2. Consultar control.rocejo.com para límites + webhook (cada 6hrs)
        $key = self::getKey();
        if (!$key) {
            self::fail('License key no configurada.');
        }

        $licenseData = self::getLicenseData($key);

        // Guardar en $_ENV todo de control.rocejo.com
        $_ENV['MAX_USERS']      = $licenseData['max_users']     ?? 5;
        $_ENV['MAX_INSTANCES']  = $licenseData['max_instances'] ?? 1;
        $_ENV['PLAN']           = $licenseData['plan']          ?? 'Básico';
        $_ENV['PLAN_KEY']       = $licenseData['plan_key']      ?? 'basico';
        $_ENV['EXPIRES_AT']     = $licenseData['expires_at']    ?? '';
        $_ENV['EVO_URL']        = $licenseData['evolution']['url'] ?? '';
        $_ENV['EVO_KEY']        = $licenseData['evolution']['key'] ?? '';
        $_ENV['WEBHOOK_URL']    = $licenseData['evolution']['webhook_url'] ?? ($_ENV['APP_URL'] . '/api/webhook');
    }

    private static function getKey(): string
    {
        $file = __DIR__ . '/../../license.php';
        if (!file_exists($file)) return '';

        $content = file_get_contents($file);
        if (preg_match("/define\s*\(\s*['\"]LICENSE_KEY['\"]\s*,\s*['\"]([a-f0-9]+)['\"]\s*\)/", $content, $m)) {
            return $m[1];
        }
        return '';
    }

    private static function getLicenseData(string $key): array
    {
        $cacheFile = sys_get_temp_dir() . '/crm_license_' . md5($key) . '.json';

        // Intenta cache primero (6 horas)
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && ($cached['ok'] ?? false)) {
                return $cached;
            }
        }

        $domain = $_SERVER['HTTP_HOST'] ?? 'unknown';

        // POST a control.rocejo.com/api/validate
        $payload = json_encode(['key' => $key, 'domain' => $domain]);

        $ch = curl_init(self::CONTROL_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 5,
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $httpCode >= 400) {
            // Graceful degradation: usar cache antiguo si está disponible
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

        // Verificar firma HMAC
        $sig = $data['sig'] ?? '';
        $sigPayload = $key . $domain . ($data['plan'] ?? 'basic') . ($data['expires_at'] ?? '');
        $expected = hash_hmac('sha256', $sigPayload, self::HMAC_SECRET);

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
