<?php

namespace App\Services;

use App\Core\Plan;

/**
 * EvolutionService — Cliente OOP para Evolution API
 * Namespace: App\Services
 */
class EvolutionService
{
    private string $baseUrl;
    private string $apiKey;
    private string $instance;

    public function __construct(string $instance, ?string $url = null, ?string $key = null)
    {
        $url = $url ?? Plan::evoUrl();
        $key = $key ?? Plan::evoKey();

        if (empty($url)) {
            throw new \RuntimeException('Evolution API no configurada');
        }

        $this->baseUrl  = rtrim($url, '/');
        $this->apiKey   = $key;
        $this->instance = $instance;
    }

    // ==========================================
    // MENSAJES
    // ==========================================

    public function enviarTexto(string $numero, string $mensaje): array
    {
        return $this->request('POST', "/message/sendText/{$this->instance}", [
            'number' => $this->formatearNumero($numero),
            'text'   => $mensaje,
        ]);
    }

    public function enviarImagen(string $numero, string $imagenUrl, string $caption = ''): array
    {
        return $this->request('POST', "/message/sendMedia/{$this->instance}", [
            'number'    => $this->formatearNumero($numero),
            'mediatype' => 'image',
            'media'     => $imagenUrl,
            'caption'   => $caption,
        ]);
    }

    public function enviarImagenBase64(string $numero, string $base64, string $caption = '', string $mimetype = 'image/jpeg'): array
    {
        return $this->request('POST', "/message/sendMedia/{$this->instance}", [
            'number'    => $this->formatearNumero($numero),
            'mediatype' => 'image',
            'media'     => $base64,
            'mimetype'  => $mimetype,
            'caption'   => $caption,
        ]);
    }

    public function enviarDocumento(string $numero, string $documentoUrl, string $filename, string $caption = ''): array
    {
        return $this->request('POST', "/message/sendMedia/{$this->instance}", [
            'number'    => $this->formatearNumero($numero),
            'mediatype' => 'document',
            'media'     => $documentoUrl,
            'fileName'  => $filename,
            'caption'   => $caption,
        ]);
    }

    public function enviarAudio(string $numero, string $audioUrl): array
    {
        return $this->request('POST', "/message/sendWhatsAppAudio/{$this->instance}", [
            'number' => $this->formatearNumero($numero),
            'audio'  => $audioUrl,
        ]);
    }

    // ==========================================
    // CONTACTOS / CHATS
    // ==========================================

    public function obtenerContactos(int $count = 500): array
    {
        return $this->request('POST', "/chat/findContacts/{$this->instance}", []);
    }

    public function obtenerChats(): array
    {
        return $this->request('POST', "/chat/findChats/{$this->instance}", []);
    }

    public function obtenerGrupos(bool $conParticipantes = false): array
    {
        $qs = $conParticipantes ? '?getParticipants=true' : '?getParticipants=false';
        return $this->request('GET', "/group/fetchAllGroups/{$this->instance}{$qs}");
    }

    /**
     * Busca mensajes con filtros opcionales.
     * @param array  $where  Filtros: remoteJid, fromMe, etc.
     * @param int    $limit  Mensajes por página
     * @param int    $page   Página (1-indexed)
     */
    public function buscarMensajes(array $where = [], int $limit = 50, int $page = 1): array
    {
        $body = ['limit' => $limit, 'page' => $page];
        if (!empty($where)) $body['where'] = $where;
        return $this->request('POST', "/chat/findMessages/{$this->instance}", $body);
    }

    /**
     * Obtiene JIDs únicos de grupos extraídos de los mensajes almacenados.
     * Más confiable que fetchAllGroups cuando éste hace timeout.
     */
    public function obtenerGruposDesdeHistorial(int $limit = 100): array
    {
        $res  = $this->buscarMensajes([], 500, 1);
        $recs = $res['messages']['records'] ?? $res['records'] ?? [];
        $grupos = [];
        foreach ($recs as $m) {
            $jid = $m['key']['remoteJid'] ?? '';
            if (str_ends_with($jid, '@g.us') && !isset($grupos[$jid])) {
                $grupos[$jid] = [
                    'jid'    => $jid,
                    'nombre' => $m['key']['remoteJid'] ?? $jid, // sin metadatos aún
                ];
            }
        }
        return array_values($grupos);
    }

    public function obtenerParticipantesGrupo(string $groupJid): array
    {
        return $this->request('GET', "/group/participants/{$this->instance}?groupJid=" . urlencode($groupJid));
    }

    public function verificarNumero(string $numero): array
    {
        return $this->request('POST', "/chat/whatsappNumbers/{$this->instance}", [
            'numbers' => [$this->formatearNumero($numero)],
        ]);
    }

    public function marcarLeido(string $numero): array
    {
        return $this->request('POST', "/chat/markMessageAsRead/{$this->instance}", [
            'readMessages' => [[
                'remoteJid' => $this->formatearNumero($numero) . '@s.whatsapp.net',
            ]],
        ]);
    }

    // ==========================================
    // INSTANCIA
    // ==========================================

    public function obtenerQR(): array
    {
        $res = $this->request('GET', "/instance/connect/{$this->instance}");
        // Devolver data directamente (contiene base64, code, etc.)
        return $res['data'] ?? $res;
    }

    public function verificarConexion(): array
    {
        $res   = $this->request('GET', "/instance/connectionState/{$this->instance}");
        $data  = $res['data'] ?? [];
        $state = $data['instance']['state'] ?? $data['state'] ?? 'unknown';

        return [
            'conectado' => $state === 'open',
            'estado'    => $state,
        ];
    }

    public function obtenerInfoInstancia(): array
    {
        return $this->request('GET', "/instance/fetchInstances?instanceName={$this->instance}");
    }

    public function crearInstancia(string $nombre, ?string $webhookUrl = null): array
    {
        $payload = [
            'instanceName' => $nombre,
            'integration'  => 'WHATSAPP-BAILEYS',
        ];
        if ($webhookUrl) {
            $payload['webhook'] = [
                'enabled'        => true,
                'url'            => $webhookUrl,
                'webhookByEvents'=> false,
                'events'         => ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'CONNECTION_UPDATE', 'SEND_MESSAGE'],
            ];
        }
        return $this->request('POST', '/instance/create', $payload);
    }

    public function desconectar(): array
    {
        return $this->request('DELETE', "/instance/logout/{$this->instance}");
    }

    public function reiniciar(): array
    {
        return $this->request('POST', "/instance/restart/{$this->instance}", []);
    }

    // ==========================================
    // ESTADOS / STORIES
    // ==========================================

    /**
     * Publicar un estado (Story) via Evolution API.
     * Endpoint: POST /message/sendStatus/{instance}
     * @param string $tipo      'text' | 'image'
     * @param string $contenido Texto del estado o base64/URL de imagen
     * @param string $caption   Caption opcional (para imagen)
     * @param array  $jidList   Lista de JIDs a quienes mostrar el estado
     */
    public function publicarEstado(string $tipo, string $contenido, string $caption = '', array $jidList = []): array
    {
        $body = ['type' => $tipo, 'content' => $contenido];
        if ($caption) {
            $body['caption'] = $caption;
        }
        if (!empty($jidList)) {
            $body['statusJidList'] = $jidList;
        }
        if ($tipo === 'text') {
            $body['backgroundColor'] = '#075E54';
            $body['font'] = 0;
        }
        return $this->request('POST', "/message/sendStatus/{$this->instance}", $body);
    }

    // ==========================================
    // WEBHOOKS
    // ==========================================

    public function configurarWebhook(string $url, array $eventos = []): array
    {
        if (empty($eventos)) {
            $eventos = ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'CONNECTION_UPDATE', 'SEND_MESSAGE'];
        }

        return $this->request('POST', "/webhook/set/{$this->instance}", [
            'webhook' => [
                'enabled'        => true,
                'url'            => $url,
                'webhookByEvents'=> false,
                'events'         => $eventos,
            ],
        ]);
    }

    // ==========================================
    // UTILIDADES
    // ==========================================

    public function formatearNumero(string $numero): string
    {
        $numero = preg_replace('/[\s\-\+\(\)]/', '', $numero);

        if (strlen($numero) == 10 && $numero[0] !== '0') {
            $numero = '54' . $numero;
        }

        if (strlen($numero) == 11 && $numero[0] === '0') {
            $numero = '54' . substr($numero, 1);
        }

        return $numero;
    }

    // ==========================================
    // REQUEST PRIVADO (cURL)
    // ==========================================

    private function request(string $method, string $endpoint, ?array $body = null): array
    {
        $url    = $this->baseUrl . $endpoint;
        $method = strtoupper($method);

        $headers = [
            'Content-Type: application/json',
            'apikey: ' . $this->apiKey,
        ];

        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ];

        switch ($method) {
            case 'POST':
                $opts[CURLOPT_POST]       = true;
                $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? []);
                break;
            case 'PUT':
                $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
                $opts[CURLOPT_POSTFIELDS]    = json_encode($body ?? []);
                break;
            case 'DELETE':
                $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                break;
            case 'GET':
            default:
                // noop
                break;
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error, 'http_code' => $httpCode];
        }

        $decoded = json_decode($response, true);

        return [
            'success'   => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'data'      => $decoded,
        ];
    }
}
