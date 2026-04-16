# CRM El Gitano - Versión 2 (v2gitano)

Este repositorio contiene la versión 2 del CRM El Gitano, una aplicación web PHP + Vue.js para gestión de leads y chats de WhatsApp.

## 🚀 Despliegue Rápido

### Requisitos
- PHP 8.2+
- MySQL 5.7+
- Node.js 18+ (solo para frontend)
- Servidor web (Apache/Nginx)

### Pasos

1. **Clonar repositorio**
   ```bash
   git clone https://github.com/MaroteMSPro/newgitano.git
   cd newgitano
   ```

2. **Configurar backend**
   ```bash
   cd backend
   cp .env.example .env
   # Editar .env con credenciales de DB
   ```

3. **Configurar base de datos**
   - Importar `database/schema.sql` (si existe)
   - O ejecutar migraciones (ver `database/migrations/`)

4. **Configurar frontend**
   ```bash
   cd ../frontend
   npm install
   npm run build
   # Los archivos compilados van a dist/
   ```

5. **Estructura de directorios en producción**
   ```
   public_html/
   ├── index.php          # backend (copiar desde backend/index.php)
   ├── src/               # backend (copiar desde backend/src/)
   ├── database/          # backend (copiar desde backend/database/)
   ├── app.html           # frontend (copiar desde frontend/dist/app.html)
   └── assets/            # frontend (copiar desde frontend/dist/assets/)
   ```

## 🏗️ Arquitectura

### Backend (PHP)
- **Framework**: Custom MVC ligero
- **Autenticación**: JWT (JSON Web Tokens)
- **Base de datos**: MySQL
- **API REST**: Rutas en `src/Controllers/`

### Frontend (Vue.js 3)
- **Build tool**: Vite
- **Estado**: Vue reactive store
- **Routing**: Vue Router
- **HTTP client**: Axios

## 🔧 Cambios Implementados (v2)

### 1. Etiquetas privadas
- Cada usuario ve solo las etiquetas que creó.
- Modificaciones en `CRMController.php` y tabla `crm_etiquetas`.

### 2. Renombrar cliente (alias)
- Click en el nombre del contacto en el chat para editar alias.
- Campo `nombre_personalizado` en tabla `crm_leads`.
- API endpoint: `PUT /api/crm/leads/:id/alias`.

### 3. Drag & drop de archivos
- Arrastrar fotos, videos, PDFs al área de chat para subir.
- Frontend: componente `ChatInput` con dropzone.
- Backend: endpoint `POST /api/crm/upload`.

### 4. Formato de fecha en mensajes
- Mensajes muestran `dd/mm HH:mm` en lugar de timestamp crudo.
- Frontend: filtro Vue `formatDate`.

### 5. Grabación de audio
- Botón de micrófono para grabar, pausar, reanudar y enviar audio.
- Frontend: componente `AudioRecorder`.
- Backend: endpoint `POST /api/crm/audio`.

## 📁 Estructura del Repositorio

```
newgitano/
├── frontend/                 # Código fuente Vue.js
│   ├── src/
│   │   ├── views/           # Vistas (Login, Dashboard, CRM, etc.)
│   │   ├── store.js         # Estado global
│   │   ├── router.js        # Rutas frontend
│   │   └── api.js           # Cliente HTTP
│   ├── package.json
│   ├── vite.config.js
│   └── app.html             # Template HTML
├── backend/                  # Código fuente PHP
│   ├── src/
│   │   ├── Controllers/     # Controladores API
│   │   ├── Models/          # Modelos DB
│   │   └── Middleware/      # Middleware (JWT, etc.)
│   ├── database/            # Migraciones y esquemas
│   ├── index.php            # Punto de entrada API
│   └── .env.example         # Variables de entorno
├── docs/                    # Documentación
│   ├── BACKEND_FIXES.md     # Fixes aplicados al backend
│   └── DEPLOYMENT.md        # Guía de despliegue
└── scripts/                 # Scripts de utilidad
```

## 🐛 Solución de Problemas Comunes

### CSS no se aplica
El CSS original tenía selectores con `[data-v-XXXXXX]` (Vue scoped styles). En esta versión se eliminaron esos atributos para que los estilos sean globales.

### Login no funciona
Verificar:
- Credenciales en `.env` (DB_HOST, DB_NAME, DB_USER, DB_PASS)
- Hash de contraseñas en tabla `usuarios` (bcrypt)
- Headers CORS en `index.php`

### API devuelve 404
- Asegurar que el `.htaccess` está correctamente configurado.
- Verificar que `RewriteEngine On` y `RewriteBase` estén configurados para el subdirectorio.

## 🔄 Migración desde versión original

1. Copiar base de datos original.
2. Ejecutar migraciones adicionales (ver `database/migrations/`).
3. Actualizar contraseñas si es necesario.
4. Desplegar frontend y backend en subdirectorio `/v2/` o subdominio.

## 📄 Licencia

Privado - Uso interno.

## 👥 Contribuidores

- **Nicolas Pacheco** - Desarrollo y arquitectura
- **Betapache** - Asistencia en implementación

---

**Estado**: ✅ En producción (v2gitano.luxom.com.ar)

---

## 📦 PAQUETE ADS MANAGER (PARA OTRA INSTANCIA OPENCLAW)

### Descripción
Archivo comprimido con todo el código fuente de AdsManager/AdsPro para que otra instancia OpenClaw trabaje en paralelo.

### Contenido
- `adsmanager-git/` - Código fuente completo (Laravel)
- `DEPLOY.md` - Instrucciones de despliegue y credenciales
- `WORKSPACE_SUMMARY.md` - Contexto completo del workspace
- `INSTALL_INSTRUCTIONS.txt` - Guía rápida

### Cómo usar
1. **Descargar el archivo:**
   ```bash
   wget https://raw.githubusercontent.com/MaroteMSPro/newgitano/main/adsmanager-complete.tar.gz
   ```

2. **Extraer:**
   ```bash
   tar -xzf adsmanager-complete.tar.gz
   ```

3. **Leer instrucciones:**
   ```bash
   cat INSTALL_INSTRUCTIONS.txt
   ```

### Estado actual del proyecto
- ✅ App desplegada en https://adsmanager.luxom.com.ar
- ✅ Login admin funciona (admin@adspro.com / admin)
- ✅ WhatsApp QR scanning OK
- ✅ CSRF 419 fixeado
- ❌ **PROBLEMA:** Meta Ads muestra "Conectar con Meta" en lugar de "Configurar Meta App"

### Credenciales servidor
- **Host:** 89.117.7.38:65002
- **User:** u695160153
- **Pass:** @@Luxom00102
- **Ruta:** `/home/u695160153/domains/luxom.com.ar/public_html/adsmanager/`

---

*Paquete AdsManager generado el 2026-04-16 por Betapache 🛠️*