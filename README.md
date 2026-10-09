# EXAMEN 2 TOPWEB: NODO 1 (Banco Central)

Este repositorio contiene la implementación del Nodo 1 (Banco Central) del Sistema Bancario Distribuido. 

## Arquitectura

El Nodo 1 es una API construida con **Laravel 11** que se conecta a una base de datos central en **Supabase** (PostgreSQL). Utiliza **Supabase Auth** para la autenticación del panel de administradores. 

- **Framework**: Laravel 11
- **Base de Datos**: PostgreSQL en Supabase, con seguridad RLS a nivel de tablas.
- **Autenticación**: Supabase Auth para usuarios administradores; API Keys hasheadas con SHA-256 para nodos sucursales y cajeros.
- **Operaciones Atómicas**: Se usa `DB::transaction()` con bloqueo pesimista (`lockForUpdate`) para garantizar la consistencia en depósitos y retiros.
- **Idempotencia**: Las transacciones usan `idempotency_key` para evitar transferencias o retiros duplicados ante problemas de red.

## Configuración e Instalación

### 1. Clonar el repositorio
```bash
git clone https://github.com/Shinra3245/EXAMEN_2_TOPWEB_NODO_1.git
cd EXAMEN_2_TOPWEB_NODO_1
```

### 2. Archivo de configuración (.env)
Copia el archivo de ejemplo:
```bash
cp .env.example .env
```
Añade tus variables secretas (deberás tener las credenciales de Supabase). El `.env` debe lucir así para producción:
```env
DB_CONNECTION=pgsql
DB_HOST=aws-0-us-east-1.pooler.supabase.com
DB_PORT=6543
DB_DATABASE=postgres
DB_USERNAME=postgres.tu-proyecto
DB_PASSWORD=TU_CONTRASEÑA

SUPABASE_URL=https://tu-proyecto.supabase.co
SUPABASE_SECRET_KEY=sb_secret_tu_clave
SUPABASE_PUBLISHABLE_KEY=sb_publishable_tu_clave
```

### 3. Instalar dependencias
```bash
composer install
```

### 4. Crear el administrador inicial
Ejecuta este comando usando la API de Supabase para registrar el primer administrador:
```bash
php artisan admin:create-initial admin@banco.com tu-contraseña-segura
```

### 5. Arranque
Para pruebas locales (sin docker):
```bash
php artisan serve
```

---

## Pruebas (Test Driven)

Las pruebas están configuradas para ejecutarse contra la base de datos PostgreSQL local en Sail (Docker), asegurando que NUNCA se ejecuten truncados sobre la base compartida en Supabase.

Para ejecutar las pruebas localmente:
```bash
# Iniciar Sail
./vendor/bin/sail up -d
# Ejecutar pruebas
./vendor/bin/sail artisan test
```
Las pruebas validan la concurrencia, idempotencia, fondos insuficientes y rechazo a peticiones no autorizadas.

---

## Integración y API (Postman / OpenAPI)

El sistema expone endpoints seguros. Encuentra el contrato en:
- `openapi.yaml` (Definición completa Swagger/OpenAPI)
- `postman_collection.json` (Colección lista para importar)
- `postman_environment.json` (Variables de entorno para Postman)

El flujo típico de un Nodo (Sucursal/Cajero) es:
1. El Administrador Central crea el nodo en el panel (`/admin`).
2. Se genera una `X-API-KEY` (sólo mostrada 1 vez).
3. El Nodo envía esta cabecera en peticiones a `/api/accounts` y `/api/transactions`.

---

## Despliegue

La configuración soporta despliegue en Vercel, Render o Coolify. Falta añadir las credenciales y ejecutar los despliegues automatizados en caso de contar con una plataforma preasignada. Se configuró correctamente en la rama principal.
