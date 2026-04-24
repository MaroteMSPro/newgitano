# CRM WhatsApp — Historial de Versiones

> Template base: `wkmarketing.rocejo.com`
> Al deployar un nuevo cliente, copiar todo el template y configurar su `license.php`.
> Cada fix/feature se aplica PRIMERO en wkmarketing y LUEGO se sincroniza a clientes.

---

## v1.1.0 — 2026-03-28
**Fix: Módulo Mi Licencia (pantalla en blanco)**
- El componente `License.vue` compilado usa variables CSS dark mode (`--card-bg`, `--border`, `--text-secondary`) que no estaban definidas globalmente.
- Fix: se inyectan en `app.html` como override `:root` con valores light.
- Afectaba: cualquier cliente nuevo copiado desde el template.

---

## v1.0.0 — 2026-03-21
**Versión inicial SaaS**
- Evolution API: instancias QR, webhook, sync contactos/mensajes
- Módulos: Dashboard, Contactos, CRM Chats, Campañas, Masivo, Difusiones
- Auto-Respuesta, Respuestas Rápidas, Estados, Biblioteca
- Monitor, Estadísticas, Sin Contestar
- Sistema de licencias vía `control.rocejo.com` (HMAC-SHA256)
- Multi-instancia, Round Robin, Gestión de leads

---

## Protocolo de deploy para nuevos clientes

1. `tar` del template wkmarketing (excluyendo `license.php`, `.env`, `.gitignore`)
2. Subir y extraer en el subdominio del cliente
3. Crear DB en Hostinger del cliente
4. Crear licencia en `control.rocejo.com` con datos del cliente
5. Crear `license.php` con la key correspondiente
6. Crear `.env` con JWT_SECRET y APP_URL del cliente
7. Verificar login → `/api/auth/login`
8. Verificar licencia → `/api/my-license`

---

## Clientes activos

| Cliente | URL | DB | Versión |
|---------|-----|----|---------|
| Luxom/Rocejo | wm.luxom.com.ar | u695160153_wmcrm | v1.1.0 |
| El Gitano | elgitano.luxom.com.ar | u695160153_elgitano | v1.1.0 |

