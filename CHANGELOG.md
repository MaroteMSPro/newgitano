# CHANGELOG - elgitano.luxom.com.ar

## 2026-04-08 — Sesión de correcciones y nuevas funcionalidades

### Arquitectura

**Separación DB de licencias (CRÍTICO)**
- `License.php` ya NO maneja credenciales de DB
- DB se configura en `.env` local de cada cliente
- control.rocejo.com SOLO valida licencias (plan, límites, módulos, expiración)
- EVO_URL y EVO_KEY siguen viniendo de la licencia (Evolution API)

**Archivos modificados:**
- `src/Core/License.php` — eliminadas líneas `$_ENV['DB_HOST/NAME/USER/PASS']`
- `.env` — agregados DB_HOST, DB_NAME, DB_USER, DB_PASS

### Configuración actual

```
DB_HOST=localhost
DB_NAME=u695160153_elgitano
DB_USER=u695160153_elgitano
DB_PASS=@@Luxom010203

Instancia: ID 22, nombre "1", desc "Distribuidora", numero 5491140554463
Evolution API (global): 429683C4C977415CAAFCCE10F7D57E11
Evolution API (instancia): 9E162937-BD7E-4FA9-973B-9D7BF212D347
Webhook: https://elgitano.luxom.com.ar/api/webhook
```

### Correcciones

1. **Fix caché de instancia entre dominios**
   - `app.html`: script que detecta cambio de dominio y resetea localStorage
   - Evita que wm.luxom cache "Luxom Oficial" en elgitano

2. **Fix sync contactos — remoteJid vs id**
   - `src/Controllers/SyncController.php`: cambiado `$c['id']` → `$c['remoteJid']`
   - Antes importaba UUIDs de Evolution como números, ahora usa el JID real

3. **Fix sync participantes — phoneNumber vs id**
   - `src/Controllers/SyncController.php`: cambiado `$p['id']` → `$p['phoneNumber']`
   - Evolution devuelve LIDs (@lid) en id, pero el número real está en phoneNumber

4. **Webhook apuntaba a CRM equivocado**
   - Cambiado de `crm.luxom.com.ar/api/webhook` → `elgitano.luxom.com.ar/api/webhook`
   - Sin esto, los mensajes no llegaban al CRM de elgitano

5. **Eliminado botón Reiniciar (🔁) de Instancias**
   - Desconectaba la instancia en Evolution sin forma de reconectar fácil

6. **Eliminado card duplicado "Luxom Oficial" del Dashboard**
   - Era hardcodeado, el selector del sidebar ya muestra la instancia

7. **Contactos visible para usuarios normales**
   - `App.vue`: quitado `v-if="isAdmin"` de Contactos
   - Usuarios normales ven: Contactos, CRM, Estados, Difusiones, Auto-Respuesta, Respuestas Rápidas, Seguimiento, Recordatorios
   - Sección ADMINISTRACIÓN sigue solo para admin

### Nuevas funcionalidades

1. **Tabs en Contactos: Individuales | Grupos**
   - Tab Individuales: lista de contactos (como antes)
   - Tab Grupos: lista de grupos sincronizados desde Evolution
   - Cada grupo tiene:
     - 🔄 Sincronizar participantes (1 grupo a la vez)
     - 👥 Ver participantes (modal)
     - 📋 Exportar números (copia al clipboard o descarga .txt)

2. **Sync de grupos desde Evolution API**
   - Endpoint: `POST /api/sync/groups`
   - Importa grupos como contactos con tipo="grupo"
   - 122 grupos importados

3. **Sync participantes por grupo**
   - Endpoint: `POST /api/sync/group-participants`
   - Sincroniza participantes de UN grupo a la vez
   - No duplica (ON DUPLICATE KEY UPDATE)
   - Usa `phoneNumber` para obtener número real

4. **Pegar números en Difusiones**
   - Al crear lista de difusión: textarea para pegar números (uno por línea)
   - Valida formato (10-15 dígitos)
   - Si el número existe en contactos → lo vincula
   - Si no existe → crea contacto nuevo con origen "difusion"
   - Permite mezclar contactos existentes + números pegados

### DB — Cambios de esquema

```sql
-- Campo tipo en contactos
ALTER TABLE contactos ADD COLUMN tipo ENUM("individual","grupo","difusion") DEFAULT "individual";

-- Tabla participantes de grupos
CREATE TABLE grupo_participantes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contacto_id INT NOT NULL,
    numero VARCHAR(20) NOT NULL,
    nombre VARCHAR(255) DEFAULT NULL,
    es_admin TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_contacto (contacto_id),
    INDEX idx_numero (numero),
    UNIQUE KEY uk_grupo_numero (contacto_id, numero)
);
```

### Archivos modificados (resumen)

**Backend (servidor):**
- `src/Core/License.php` — quitadas líneas DB
- `src/Controllers/SyncController.php` — fix remoteJid/phoneNumber + nuevos métodos (groups, groupParticipantsSync, groupParticipants, exportGroupNumbers)
- `src/Controllers/ContactsController.php` — filtro por tipo (individual/grupo)
- `src/Controllers/BroadcastsController.php` — soporte numeros_raw en createList
- `index.php` — nuevas rutas sync/groups, groups/participants, groups/export, sync/group-participants
- `.env` — DB config local
- `license.php` — key correcta de elgitano
- `.htaccess` — restaurado desde wm
- `app.html` — script caché dominio + referencias assets actualizados

**Frontend (build local → deploy assets):**
- `src/App.vue` — Contactos sin v-if="isAdmin", fix caché dominio
- `src/views/Dashboard.vue` — eliminado card hardcodeado
- `src/views/Contacts.vue` — tabs Individuales/Grupos, sync participantes, exportar
- `src/views/Instances.vue` — eliminado botón Reiniciar
- `src/views/Broadcasts.vue` — textarea pegar números

### ⚠️ Lecciones aprendidas

1. **NO hacer cambios masivos sin confirmar** — preguntar antes de cada cambio
2. **NO subir dist de Vue sobre estructura PHP** — respetar la estructura existente
3. **NO tocar datos de DB sin preguntar** — instancias, contraseñas, etc.
4. **Trabajar directo en el servidor** cuando es código PHP
5. **Build local + deploy assets** para cambios de frontend
6. **Ciclos cortos** — un cambio, test, deploy, verificar
