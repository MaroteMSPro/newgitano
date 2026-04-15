# Fixes de Backend — ElGitano CRM

## Punto 1 — Etiquetas: POST /api/crm/tags sin requerir isAdmin

### Problema
En `src/Controllers/CRMController.php`, el método `createTag` usa `$admin`
en lugar de `$auth` para obtener el usuario autenticado. Eso hace que falle
para usuarios no-admin.

### Fix en CRMController.php
```php
// ❌ ANTES (bug del bundle original — memoria confirmada: $admin→$auth)
public function createTag() {
    $user = $this->auth->getUser();  // OK
    // pero internamente se hacía: if (!$admin) return 403
}

// ✅ DESPUÉS — remover el guard de admin, cualquier user autenticado puede crear
public function createTag() {
    $user = $this->auth->getUser();
    // NO verificar $user['rol'] === 'admin'
    
    $body  = json_decode(file_get_contents('php://input'), true);
    $nombre = trim($body['nombre'] ?? '');
    $color  = trim($body['color'] ?? '#25D366');
    
    if (!$nombre) {
        http_response_code(400);
        echo json_encode(['error' => 'Nombre requerido']);
        return;
    }
    
    $stmt = $this->db->prepare(
        "INSERT INTO crm_tags (nombre, color, activa, created_by) VALUES (?, ?, 1, ?)"
    );
    $stmt->execute([$nombre, $color, $user['id']]);
    
    echo json_encode(['success' => true, 'id' => $this->db->lastInsertId()]);
}
```

### Fix en Router (src/Controllers/index.php o Router.php)
```php
// Asegurarse de que la ruta usa ->auth() y NO ->admin()
$router->post('/crm/tags',    [CRMController::class, 'createTag'],    'auth');   // ✅
// ❌ NO: 'admin'
```

---

## Punto 2 — Alias: PUT /crm/leads/:id/alias → "Not Found"

### Problema
La ruta `PUT /crm/leads/:id/alias` es NUEVA (no existía en el bundle original).
Hay que agregarla tanto en el Router como en CRMController.

### Fix 1: Agregar ruta en index.php / Router
```php
// En el bloque de rutas CRM:
$router->put('/crm/leads/:id/alias',   [CRMController::class, 'updateAlias'],  'auth');
```

### Fix 2: Agregar método en CRMController.php
```php
public function updateAlias(int $id) {
    $user = $this->auth->getUser();
    $body  = json_decode(file_get_contents('php://input'), true);
    $alias = trim($body['alias'] ?? '');
    
    if (!$alias) {
        http_response_code(400);
        echo json_encode(['error' => 'Alias requerido']);
        return;
    }
    
    // Verificar que el lead pertenece a la instancia del user (o es admin)
    $stmt = $this->db->prepare(
        "UPDATE crm_leads SET nombre = ? WHERE id = ?"
    );
    $stmt->execute([$alias, $id]);
    
    echo json_encode(['success' => true, 'nombre' => $alias]);
}
```

> **Nota:** El frontend usa `PUT` (no `POST`). Verificar que el Router soporta
> el método PUT para rutas con parámetro `:id`.

---

## Punto 3 — Drag & Drop / Attach: múltiples archivos

### Estado actual
El endpoint `POST /crm/leads/:id/attach` **ya existe** y funciona.
El frontend envía `FormData` con campos:
- `archivo` — el archivo (File)
- `lead_id` — ID del lead
- `caption` — texto opcional

### Para múltiples archivos
El frontend actual envía de a uno por drop (el bundle original también).
Si se quiere soportar múltiples, el backend necesita aceptar `archivo[]`:

```php
// En attachFile():
// CASO 1: archivo único (comportamiento actual — no tocar)
$file = $_FILES['archivo'] ?? null;

// CASO 2: múltiples (extensión futura)
// $files = $_FILES['archivo'] — cuando es array
```

**Recomendación:** No cambiar el backend por ahora. El frontend ya envía
de a un archivo por vez (primer archivo del drop). Si se necesita multi-attach,
se itera desde el frontend haciendo un POST por cada archivo.

### Verificar soporte de audio
```php
// En attachFile(), asegurarse de que estos MIME types están permitidos:
$allowedTypes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'video/mp4', 'video/webm',
    'audio/ogg', 'audio/mpeg', 'audio/webm',   // ← AGREGAR si no están
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];
```

---

## Punto 4 — Audio grabado: type audio/ogg

### El frontend envía
```
Content-Type: multipart/form-data
archivo: File { name: "audio_1234567890.ogg", type: "audio/ogg; codecs=opus" }
lead_id: 123
caption: ""
```

### Fix en attachFile() si filtra por extensión
```php
// ❌ Problema: algunos backends filtran por extensión y rechazan .ogg
// ✅ Agregar .ogg y .webm a la whitelist de extensiones permitidas:
$allowedExtensions = ['jpg','jpeg','png','gif','pdf','doc','docx',
                       'mp4','webm','ogg','oga','mp3'];  // ← agregar ogg
```

### Para que WhatsApp lo acepte como audio de voz
La Evolution API necesita que el audio sea enviado como `audioMessage`, no
como documento. Verificar que en el método de envío el backend detecta
`audio/*` y usa el tipo correcto:
```php
if (str_starts_with($mimeType, 'audio/')) {
    // Usar endpoint de audio de Evolution API, no documento
    $payload['audio'] = base64_encode(file_get_contents($filePath));
} else {
    $payload['document'] = ...;
}
```

---

## Punto 5 — Build y Deploy

### Opción A: Docker (recomendada para /v2/ limpio)
```bash
# 1. En el servidor, clonar/copiar el proyecto
cd /var/www/elgitano-v2

# 2. Editar nginx.conf: cambiar proxy_pass al puerto real de XAMPP
# Si XAMPP corre en el host en puerto 80:
# proxy_pass http://host-gateway:80/api/;

# 3. Build y levantar
docker-compose up -d --build

# 4. El frontend queda en http://servidor:3080
# Configurar Apache/Nginx del host para proxy-pass desde /v2/ → :3080
```

### Opción B: Build estático (sin Docker, deploy directo)
```bash
# En local o CI:
npm ci
npm run build
# Subir carpeta dist/ al servidor

# En el servidor (Apache):
# Alias /v2 /var/www/elgitano-v2/dist
# <Directory /var/www/elgitano-v2/dist>
#   FallbackResource /v2/app.html
# </Directory>
```

### Opción C: Hostinger / cPanel (deploy estático)
```bash
npm run build
# Subir dist/* a public_html/v2/
# Crear .htaccess en dist/:
```
```apache
RewriteEngine On
RewriteBase /v2/
RewriteRule ^index\.html$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /v2/app.html [L]
```

---

## Resumen de cambios en backend

| Archivo | Cambio | Urgencia |
|---|---|---|
| `Router/index.php` | Agregar `PUT /crm/leads/:id/alias` con middleware `auth` | 🔴 Requerido |
| `CRMController.php` | Agregar método `updateAlias(int $id)` | 🔴 Requerido |
| `CRMController.php` | Cambiar `createTag`: remover guard admin, usar solo `auth` | 🔴 Requerido |
| `AttachController.php` o similar | Agregar `audio/ogg`, `.ogg` a whitelist | 🟡 Para audio |
| Evolution API integration | Detectar `audio/*` y usar endpoint de audio, no documento | 🟡 Para audio WA |
