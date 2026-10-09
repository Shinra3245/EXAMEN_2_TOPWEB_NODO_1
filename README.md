# Nodo 1: Banco Central

Proyecto del examen Sistema Bancario Distribuido. Este nodo usará Laravel y Supabase.

## Supabase configurado

- Organización: **EXAMEN**, plan gratuito.
- Proyecto: **Banco Central**.
- Referencia: `rtfdnrwcjwovpplmfthc`.
- Panel: https://supabase.com/dashboard/project/rtfdnrwcjwovpplmfthc
- URL de servicios: https://rtfdnrwcjwovpplmfthc.supabase.co
- Región: East US (North Virginia), `us-east-1`.
- Se pausó `pizzas-pruebas` para liberar un proyecto activo.

El esquema `supabase/001_schema.sql` se aplicó el 9 de octubre de 2026. El script no es una migración repetible: no debe volver a ejecutarse sobre estas tablas existentes.

| Tabla | Función |
| --- | --- |
| `bank_admins` | Relaciona administradores autorizados con usuarios de Supabase Auth. |
| `bank_nodes` | Sucursales, cajeros, responsables, hash de API Key y efectivo disponible. |
| `users_accounts` | Número de cuenta, titular, saldo, estado y sucursal de origen. |
| `transactions` | Historial global relacionado con cada nodo y sus cuentas. |

Las tablas tienen RLS habilitado. Los roles `anon` y `authenticated` no tienen permisos directos sobre ellas. La API de Laravel usará una clave secreta guardada solo en el servidor y verificará la identidad del administrador o la API Key del nodo antes de permitir operaciones.

La clave secreta de Supabase no es la API Key de una sucursal o cajero. Cada nodo recibirá una clave propia generada por Laravel; en `bank_nodes` se almacenará su hash SHA-256.

`transactions` impide UPDATE, DELETE y TRUNCATE mediante triggers. La aplicación registra movimientos nuevos en lugar de modificar el historial. El propietario PostgreSQL conserva facultades administrativas sobre el esquema.

## Configuración privada

La contraseña de PostgreSQL y las claves del proyecto están en `.env.supabase.local`, con permisos de lectura y escritura solo para su propietario. Está excluido mediante `.gitignore`. `.env.example` documenta las variables sin secretos.

## Validación realizada

- El esquema se ejecutó en un PostgreSQL 16 temporal sin puertos publicados; el contenedor se retiró al terminar.
- Se comprobó que el historial rechaza edición, borrado y truncado.
- Se comprobaron restricciones de saldo negativo, monto cero y transferencia sin destino.
- La consulta de solo lectura `supabase/002_verificar_schema.sql` confirmó las cuatro tablas, RLS activo y los permisos en Supabase.

## Pendiente del backend

Todavía no existe la aplicación Laravel, una cuenta administradora de la aplicación, las API Keys de sucursales/cajeros ni un despliegue del nodo.

Laravel deberá implementar autenticación con Supabase Auth, alta y administración de nodos, apertura de cuentas, consulta de saldo/historial y reportes. También deberá actualizar el saldo y registrar cada movimiento en una misma transacción de base de datos, con protección ante solicitudes simultáneas y reintentos. No se deben realizar estas dos escrituras como llamadas REST independientes.

Las cuentas iniciales, depósitos y retiros de la demostración aún no se han ejecutado.
