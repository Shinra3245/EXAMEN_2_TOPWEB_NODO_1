# Pruebas conjuntas en Postman

Render es el destino acordado con el profesor. Vercel y Coolify son opcionales. Primero se termina y valida cada nodo; después se ejecuta la integración completa.

Importar `postman_integracion_collection.json` y `postman_integracion_environment.json`. El entorno no contiene credenciales y las operaciones están desactivadas inicialmente.

## Preparación

1. En el panel del Banco Central, crear una sucursal y un cajero, copiar sus claves y asignar efectivo. Configurar ambos servidores para consumir la API del Nodo 1 con su propia clave.
2. Completar `central_url`, `sucursal_url` y `cajero_url` con las URL públicas reales, sin `/` final ni `/api`. La colección incluye `/api` donde corresponde. Guardar las claves en los valores privados locales de `sucursal_api_key` y `cajero_api_key`.
3. Configurar el efectivo local del ATM. La asignación del Banco Central y el inventario local son valores distintos: el Nodo 3 debe implementar su configuración y persistencia.
4. Al terminar el ATM, confirmar `atm_estado_path`, `atm_retiro_path` y `atm_saldo_path` con su OpenAPI. Las rutas incluyen `/` inicial; la consulta de saldo puede contener `{{numero_cuenta}}`. Completar los campos JSON `atm_estado_campo` y `atm_saldo_campo` según su respuesta real; admiten rutas como `data.saldo`.
5. Completar `atm_retiro_body` con el JSON que acepte realmente el ATM. Debe usar `{{numero_cuenta}}`, `{{monto_retiro}}` y `{{retiro_idempotency}}`. Ejemplo orientativo, pendiente del contrato del Nodo 3:

```json
{"numero_cuenta":"{{numero_cuenta}}","monto":{{monto_retiro}},"idempotency_key":"{{retiro_idempotency}}"}
```

Las rutas del ATM están vacías porque todavía no existe su implementación en esta carpeta. No completar esas rutas por suposición. La sucursal actual también debe adaptar su cliente a los endpoints `/api/accounts` y `/api/transactions` y los campos `nombre_titular` y `saldo_global` del Banco Central; actualmente usa rutas y nombres distintos.

## Ejecución

Seleccionar **una carpeta** en Collection Runner, el entorno importado y **una iteración**. Activar la opción de detener la ejecución ante errores. Cada carpeta termina su ejecución al completar la última petición. El control del flujo usa `pm.execution.setNextRequest()`, que funciona en Runner; al enviar peticiones individuales no controla la siguiente solicitud. [Documentación de Postman](https://learning.postman.com/docs/collections/running-collections/building-workflows).

| Carpeta | Preparación | Comprobaciones |
|---|---|---|
| 00 — Render | Solo `central_url` | Salud HTTP, formulario de acceso, API sin clave devuelve 401. |
| 01 — Nodo 1 | Claves reales; `permitir_operaciones=true` | Cuenta nueva con $1,000, retiro de $300, reintento, conflicto de idempotencia, fondos insuficientes, saldo $700 y un solo retiro registrado. No evalúa el efectivo físico del ATM. |
| 02 — Flujo conjunto | Tres nodos terminados; rutas/JSON confirmados; ambos indicadores en `true` | Sucursal sin datos simulados, efectivo inicial, apertura de $1,000, consultas desde ambos nodos, retiro de $300, reintento sin doble descuento, saldo $700 e historial único. |
| 03 — ATM sin efectivo | Después del flujo 02, configurar manualmente el efectivo local del ATM en cero | Retiro de $100 rechazado, saldo central intacto y ningún movimiento registrado para la operación rechazada. |

Los indicadores son `permitir_operaciones` y `contratos_conjuntos_confirmados`. Una configuración incompleta omite la petición y marca un error de preparación; eso no representa una prueba aprobada. Las pruebas pueden crear cuentas y movimientos de demostración que permanecerán en el ledger. Cada ejecución genera identificadores nuevos.

## Evidencia y pendientes

Guardar el resultado real del Runner con fecha, URL de cada nodo, número de pruebas y fallos. Capturar la cuenta, el retiro y el saldo final; no incluir API Keys ni contraseñas. El historial de `/api/transactions` devuelve exclusivamente operaciones del nodo autenticado. Para comprobar un retiro usar `cajero_api_key`; con `sucursal_api_key` se comprueba el depósito de apertura. Las carpetas 01 y 02 ya usan la clave del cajero para buscar el retiro. El historial solo demuestra que hay un movimiento: la protección SQL contra actualización, borrado y truncado se verifica además con las pruebas PostgreSQL del Nodo 1.

Estado de esta entrega: colección y scripts preparados; la ejecución conjunta de los nodos 2 y 3 está pendiente. No se han generado resultados simulados ni capturas de una integración todavía inexistente.

Validado el 9 de octubre de 2026: Nodo 1 en https://banco-central-nodo1.onrender.com, acceso administrativo y nodos de demostración creados. Carpetas 00 y 01 ejecutadas con Newman 6.2.2 (runtime de Postman): 13 solicitudes y 27 aserciones, cero fallos. Se comprobó el rechazo 401 con clave falsa y que el historial de cada nodo solo contiene sus propias operaciones. Carpetas 02 y 03 permanecen sin ejecutar.
