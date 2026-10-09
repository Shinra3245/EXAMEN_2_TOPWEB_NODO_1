# Nodo 1 en Render

El despliegue acordado es Render Free con Docker. Vercel y Coolify son opcionales según la aclaración del profesor. La base de datos permanece en Supabase, organización EXAMEN, proyecto Banco Central.

## Configuración

- PHP 8.5 y Laravel 13, compatibles con `composer.lock`; el contenedor anterior con PHP 8.3 no satisfacía las dependencias.
- Apache publica únicamente `public/`, escucha en `PORT` (10000 por defecto) y Laravel reconoce HTTPS detrás del proxy de Render.
- `APP_URL` toma la URL que Render entrega en `RENDER_EXTERNAL_URL`; no se presupone un subdominio.
- `APP_KEY` es una clave Laravel `base64:` de 32 bytes, mantenida entre despliegues. Se guarda como variable privada de Render.
- PostgreSQL usa el **Session pooler** verificado del proyecto: `aws-0-us-east-1.pooler.supabase.com:5432`, usuario `postgres.rtfdnrwcjwovpplmfthc`, base `postgres`, `DB_SSLMODE=require`. [Guía de Supabase para Laravel](https://supabase.com/docs/guides/getting-started/quickstarts/laravel).
- Sesiones cifradas en cookies, caché de archivos y cola síncrona. No requieren tablas Laravel de sesiones, caché o trabajos. Los datos bancarios y los administradores se conservan en Supabase.
- Los archivos `.env*`, las claves, las sesiones locales y los registros quedan excluidos de la imagen por `.dockerignore`.
- El arranque genera caché de configuración y vistas. No ejecuta migraciones ni recrea tablas automáticamente.

## Variables privadas

El Blueprint pide `APP_KEY`, `DB_PASSWORD`, `SUPABASE_PUBLISHABLE_KEY` y `SUPABASE_SECRET_KEY`. Los valores se prepararon localmente en `.env.render.local`, excluido de Git. Las claves nuevas `sb_publishable_...` y `sb_secret_...` se usan como tales; no son los antiguos JWT `anon` y `service_role`.

El administrador inicial usa el correo autorizado por el usuario. Su contraseña temporal está en `.env.admin.local`, con permisos 600 y excluido de Git. No incluir ese archivo en capturas, colecciones o repositorios.

## Publicación y actualización

Crear el Blueprint con el repositorio `Shinra3245/EXAMEN_2_TOPWEB_NODO_1`, rama `main` y archivo raíz `render.yaml`. El plan se declara explícitamente `free`. Si el repositorio no figura entre las integraciones, se puede seleccionar mediante su URL pública.

El Blueprint declara despliegue continuo con `autoDeployTrigger: commit`: cada push a `main` debe publicar una versión nueva. Este campo sustituye al antiguo `autoDeploy: true`. Render necesita acceso al repositorio mediante su integración de GitHub; seleccionar únicamente una URL pública no completa esa conexión. Comprobar en Settings que Auto-Deploy diga **On Commit** y verificar un despliegue provocado por un push. Ejecutar las pruebas antes de publicar cambios. [Referencia de Render](https://render.com/docs/blueprint-spec), [conexión del proveedor Git](https://render.com/docs/git-provider).

La migración inicial reconoce las cuatro tablas ya existentes sin repetir `001_schema.sql`. La migración siguiente añade `idempotency_key`; no borra datos ni elimina la protección del ledger. Nunca ejecutar `migrate:fresh` en Supabase.

## Validación

Comprobar `/up`, `/admin/login`, el rechazo 401 de la API sin clave, acceso administrativo, creación de nodos y asignación de efectivo. Las pruebas de cuentas, retiros, transferencias, reintentos y protección del ledger se ejecutan en PostgreSQL aislado.

La colección conjunta está en `postman_integracion_collection.json` y su guía en `FLUJO_POSTMAN.md`; desde EXAMEN también están en `INTEGRACION/`. La integración con los otros nodos se ejecuta al finalizar sus implementaciones.

Render Free puede suspender el servicio tras inactividad y usa disco efímero; las cookies evitan depender del disco para las sesiones del panel y Supabase conserva los datos bancarios. Abrir y verificar el servicio antes de la demostración. [Condiciones del plan gratuito](https://render.com/docs/free).

## Servicio publicado y validado

- URL pública: https://banco-central-nodo1.onrender.com
- Panel: https://banco-central-nodo1.onrender.com/admin/login
- Servicio: `srv-db4kj3s9v7es73a6vpr0`; Blueprint: `exs-db4kd68m7kps73c5vuo0`.
- Versión desplegada: `f51583f`. La construcción en Render terminó con estado Live.
- Administrador: `22030591@itcelaya.edu.mx`; contraseña en `.env.admin.local`.
- Nodos de demostración: sucursal con $1,000 asignados y cajero con $1,500 asignados; claves en `.env.nodos.local`. Ese efectivo central es una asignación y no sustituye el inventario local del ATM.
- Validación: 14 pruebas PostgreSQL y 22 aserciones Postman sobre Render aprobadas. Detalle en `evidencias/VALIDACION_RENDER.md`.
