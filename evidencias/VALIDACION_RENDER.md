# Validación real del Nodo 1 en Render

Fecha: 9 de octubre de 2026.

Servicio: https://banco-central-nodo1.onrender.com. Versión desplegada: `f51583f`. Plan Free, runtime Docker, estado Live.

## PostgreSQL aislado

`sh test-postgres.sh`: 14 pruebas aprobadas, 47 aserciones. Se comprobaron cuentas, fondos insuficientes sin cambios, retiro idempotente, conflicto de reintento, transferencia exacta con centavos, acceso administrativo, asignación de efectivo, HTTPS y rechazo de UPDATE/DELETE/TRUNCATE del ledger. La prueba HTTP concurrente confirmó un solo retiro de $800 sobre $1,000 y saldo final $200.

Las pruebas utilizaron contenedores temporales sin puertos públicos y su propia base `testing`; sus recursos se retiraron al finalizar. No se ejecutó migrate:fresh en Supabase.

## Postman en el servicio público

Newman 6.2.2 ejecutó la misma colección importable en Postman. Carpeta 00: 3 solicitudes, 6 aserciones aprobadas. Carpeta 01: 8 solicitudes, 16 aserciones aprobadas. Cero fallos.

| Prueba del Core | HTTP verificado |
|---|---|
| 01.01 - Sucursal abre cuenta con 1000 | 201 |
| 01.02 - Central confirma 1000 | 200 |
| 01.03 - Retiro central de 300 | 201 |
| 01.04 - Reintento idéntico | 200 |
| 01.05 - Reintento cambiado rechazado | 409 |
| 01.06 - Fondos insuficientes | 400 |
| 01.07 - Saldo final sin duplicados: 700 | 200 |
| 01.08 - Historial central sin duplicados | 200 |

Se abrió una cuenta de demostración con $1,000 y se retiraron $300. El reintento conservó el mismo identificador de transacción, el cambio de importe devolvió 409 y el retiro sin fondos devolvió 400. El saldo final confirmado fue $700; el historial contiene un solo retiro para la clave probada.

## Panel y migraciones

Acceso real del administrador autorizado mediante Supabase Auth. Se crearon una sucursal y un cajero de demostración, se recuperaron sus claves una sola vez y se guardaron únicamente en `.env.nodos.local`. La asignación central fue $1,000 para sucursal y $1,500 para cajero.

Las migraciones de Laravel reconocieron las tablas existentes y añadieron idempotency_key sin recrearlas. El administrador quedó activo en bank_admins. La contraseña temporal se conserva en `.env.admin.local`, excluido de Git.

Capturas reales: [Servicio Live](render_servicio_live.png) y [Panel de nodos](render_panel_nodos.png).

## Pendiente

La ejecución completa de los nodos 2 y 3 y el inventario físico del ATM. Las carpetas 02 y 03 de Postman están preparadas y desactivadas hasta confirmar las implementaciones y sus contratos. La prueba del Core no equivale a una retirada física realizada por el Nodo 3.
