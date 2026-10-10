# Contrato del cajero con el Banco Central

Implementación del contrato `central-contract.md` recibido el 9 de octubre de 2026. La ampliación está desplegada y validada en Render. El cajero real está disponible en https://node3-atm.onrender.com y su aceptación conjunta está completada: 46 solicitudes Postman / 49 aserciones, incluido reinicio del ATM. Las pruebas específicas del Core descritas abajo conservan su alcance original.

URL central: `https://banco-central-nodo1.onrender.com/api`. Enviar `X-API-KEY` del cajero en todas las solicitudes. El Nodo 3 no necesita credenciales de Supabase. Obtener la clave por el canal privado ya usado por el equipo; no publicarla en GitHub, capturas ni exportaciones de Postman.

| Ruta | Resultado |
| --- | --- |
| `GET /nodes/me` | `200 {data:{id,nombre,tipo,activo,efectivo_disponible}}`. El efectivo es el actual. Verificar `tipo=cajero`, `activo=true` e ID estable. |
| `GET /accounts/{numero}` | Cuenta directamente en la raíz: `numero_cuenta`, `nombre_titular`, `saldo_global`, `estado`. Mantiene ceros iniciales; consulta cuentas abiertas por la sucursal. |
| `POST /transactions` | Retiro o depósito atómico: cuenta, efectivo del cajero, ledger y comprobante se confirman juntos. Nueva operación 201; repetición idéntica 200 con el comprobante original. |
| `GET /transactions/by-idempotency-key/{clave}` | Recupera el comprobante o rechazo original, limitado al nodo autenticado. Un resultado de otro nodo no se revela. |
| `GET /transactions?cuenta={numero}&page=1` | Historial del nodo que procesó los movimientos; páginas de 50 con `data`, `next_page_url`, `last_page`. |

Retiro:

```json
{"tipo":"retiro","cuenta_origen":"00001","monto":"300.00","idempotency_key":"13cf9711-ef0a-4b58-b991-c1bdfd69cf53"}
```

Depósito: usar `tipo=deposito`, `cuenta_destino` y omitir `cuenta_origen`. El Nodo 3 genera un UUID por intento y conserva el cuerpo y la clave al reintentar. El importe acepta texto decimal exacto; se utiliza aritmética decimal, hasta `NUMERIC(15,2)`. La sucursal conserva sus claves `sucursal:...` y sus importes numéricos.

Confirmación del cajero:

```json
{
  "message":"Transacción exitosa",
  "status":"succeeded",
  "transaction":{
    "id":"c5f2d481-6f35-4281-afc0-1752989ab315",
    "nodo_id":"d6f9d760-e639-46ed-8dfb-74263740fdbe",
    "tipo":"retiro","cuenta_origen":"00001","cuenta_destino":null,
    "monto":"300.00","idempotency_key":"13cf9711-ef0a-4b58-b991-c1bdfd69cf53",
    "created_at":"2026-10-10T00:00:00.000000Z"
  },
  "saldo_global":"700.00",
  "efectivo_disponible":"1200.00"
}
```

Los IDs y saldos del ejemplo son ilustrativos. Los saldos del comprobante se conservan incluso si después existen otros movimientos. La fecha proviene de PostgreSQL y el comprobante ATM la presenta en UTC/ISO 8601.

Rechazo durable: POST 422; recuperación GET 200, ambos con el mismo cuerpo:

```json
{"status":"rejected","idempotency_key":"13cf9711-ef0a-4b58-b991-c1bdfd69cf53","error":{"code":"INSUFFICIENT_CASH","message":"Efectivo insuficiente."}}
```

Códigos: `INSUFFICIENT_FUNDS`, `INSUFFICIENT_CASH`, `ACCOUNT_BLOCKED`, `ACCOUNT_NOT_FOUND`, `VALIDATION_ERROR`. No modifican saldo/efectivo ni insertan transacciones financieras. Una petición sin clave válida no permite guardar un resultado recuperable.

La misma clave con distinto cuerpo o propietario produce 409 `IDEMPOTENCY_CONFLICT`; conserva `message` de texto. Los conflictos de sucursal añaden `code` en la raíz y mantienen sus errores legibles. La autenticación sigue devolviendo 401 con `error` de texto.

La ausencia autoritativa se responde exclusivamente como:

```json
{"error":{"code":"OPERATION_NOT_FOUND","message":"Operación no encontrada"}}
```

Solo ese 404 permite reenviar el mismo cuerpo y la misma clave. Un timeout, 5xx, 401, 409, HTML o un 404 genérico mantiene la operación incierta. Después de perder una respuesta, recuperar por clave; no deducir ausencia desde el historial paginado ni reconstruir un comprobante con el saldo actual.

Las operaciones nuevas se resuelven de forma síncrona. La consulta espera el bloqueo de una operación en curso. Los movimientos históricos anteriores a esta ampliación, sin saldos originales persistidos, devuelven `202 {status:"pending"}` y necesitan revisión manual: no se aplican nuevamente ni se inventa su comprobante.

Para sincronizar o recargar efectivo, el responsable del Nodo 3 confirma que no tiene operaciones locales pendientes. El panel central exige esa coordinación y rechaza una edición basada en efectivo anterior desactualizado. Rotar la API Key conserva el ID del cajero.

## Pruebas y entrega

Importar [postman_contrato_nodo3_collection.json](postman_contrato_nodo3_collection.json) y [postman_contrato_nodo3_environment.json](postman_contrato_nodo3_environment.json). Completar las claves en los valores privados de Postman. Cambiar `permitir_movimientos` a `true` únicamente en la demostración autorizada: la colección abre cuentas de prueba desde el Nodo 2 y realiza movimientos reales contra el Core.

La colección verifica identidad, apertura 1000, retiro 300, saldo 700, depósito, reintento, recuperación por clave, conflictos, saldo/efectivo insuficientes, validación, autenticación e historial por nodo. PostgreSQL aislado también comprueba cuenta bloqueada, desbordamiento, inmutabilidad y concurrencia real. El archivo [openapi.yaml](openapi.yaml) contiene las respuestas y campos.

La aceptación de la aplicación del Nodo 3 ya comprobó configuración, consulta de la cuenta creada en Nodo 2, retiro de 300, depósito, inventario, sesión y recuperación después de reiniciar Render. Una prueba adicional con el Core real recuperó una respuesta perdida sin duplicar el depósito. Consultar [ENTREGA_NODO3.md](ENTREGA_NODO3.md) y [FLUJO_POSTMAN.md](FLUJO_POSTMAN.md). Estas pruebas se distinguen de las llamadas directas al Core y del simulador.

Realtime del panel utiliza un canal privado con autorización de administrador. Referencias: [Broadcast](https://supabase.com/docs/guides/realtime/broadcast) y [Realtime Authorization](https://supabase.com/docs/guides/realtime/authorization). Las tablas financieras conservan RLS y el navegador no recibe la clave de servicio.

Validación registrada: **43 pruebas PostgreSQL**, **36 de Nodo 2** y **54 solicitudes Postman / 107 aserciones**, sin fallos. Se recuperó el mismo comprobante después de un reinicio real del Core. Evidencia: [VALIDACION_NODO3.md](evidencias/VALIDACION_NODO3.md). La colección completa abre cuatro cuentas de demostración e incluye la señal Realtime del panel.
