<?php

namespace App\Core;

/**
 * Helper para verificar módulos habilitados según el plan del tenant.
 * Usar en controllers para bloquear acceso a funciones no disponibles.
 */
class Plan
{
    private static ?array $modulos = null;

    public static function modulos(): array
    {
        if (self::$modulos === null) {
            self::$modulos = json_decode($_ENV['MODULOS'] ?? '{}', true) ?? [];
        }
        return self::$modulos;
    }

    /**
     * Verifica si un módulo está habilitado para este tenant.
     * Si no hay plan configurado (plan_key = basico o null), usa defaults restrictivos.
     */
    public static function habilitado(string $modulo): bool
    {
        $modulos = self::modulos();
        // Si no hay config de módulos, bloquear todo excepto lo básico
        if (empty($modulos)) {
            $defaults = ['dashboard', 'crm', 'contactos', 'estados', 'respuestas_rapidas'];
            return in_array($modulo, $defaults);
        }
        return (bool)($modulos[$modulo] ?? false);
    }

    /**
     * Aborta con 403 si el módulo no está habilitado.
     */
    public static function requerir(string $modulo): void
    {
        if (!self::habilitado($modulo)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'error'   => true,
                'code'    => 'MODULE_DISABLED',
                'message' => "El módulo '$modulo' no está disponible en tu plan actual.",
                'plan'    => $_ENV['PLAN'] ?? 'Básico',
            ]);
            exit;
        }
    }

    public static function maxUsuarios(): int    { return (int)($_ENV['MAX_USERS']     ?? 5); }
    public static function maxInstancias(): int  { return (int)($_ENV['MAX_INSTANCES'] ?? 1); }
    public static function planKey(): string     { return $_ENV['PLAN_KEY']  ?? 'basico'; }
    public static function planLabel(): string   { return $_ENV['PLAN']      ?? 'Básico'; }
    public static function evoUrl(): string      { return $_ENV['EVO_URL']   ?? ''; }
    public static function evoKey(): string      { return $_ENV['EVO_KEY']   ?? ''; }
}
