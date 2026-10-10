# Entrega para el encargado del Nodo 3

El Banco Central ya está preparado para conectar el cajero, conservando la compatibilidad con la sucursal.

- **URL:** `https://banco-central-nodo1.onrender.com/api`
- **Autenticación:** cabecera `X-API-KEY` del cajero. Omar tiene los datos de conexión en el archivo privado `.env.nodo3.local`. No se necesitan credenciales de Supabase.
- **ID del cajero:** `b1445613-4789-4588-8a2d-ed266bf11e35`.
- **Efectivo al cierre de las pruebas:** **$1,250.15**. Consultar el valor actual con `GET /nodes/me` antes de sincronizar el inventario local.

Ya funcionan `GET /nodes/me` y `GET /transactions/by-idempotency-key/{clave}`. Los retiros y depósitos actualizan saldo, efectivo e historial de forma atómica. Cada confirmación entrega `status`, `transaction`, `saldo_global`, `efectivo_disponible` y fecha. Los reintentos recuperan el resultado original; los rechazos definitivos también quedan guardados, sin movimientos financieros.

**Validado:** 43 pruebas PostgreSQL del Central, 36 pruebas de Nodo 2 y 54 solicitudes Postman con 107 comprobaciones aprobadas en Render. Se comprobó retiro $300 sobre apertura $1,000 → saldo $700, depósito, errores, aislamiento y recuperación después de reiniciar el Central.

**Siguiente paso del Nodo 3:** configurar URL y clave, crear una nueva cuenta de $1,000 desde [Nodo 2](https://sucursal-nodo2.onrender.com), retirar $300 desde su aplicación y comprobar saldo $700 e inventario local. Después probar depósito, falta de saldo/efectivo, respuesta perdida y recuperación de pendientes tras reiniciar el cajero. Estas pruebas de la aplicación ATM esperan su URL.

[Contrato completo](https://github.com/Shinra3245/EXAMEN_2_TOPWEB_NODO_1/blob/main/CONTRATO_NODO3.md) · [OpenAPI](https://github.com/Shinra3245/EXAMEN_2_TOPWEB_NODO_1/blob/main/openapi.yaml) · [Postman](postman_contrato_nodo3_collection.json) · [Entorno sin claves](postman_contrato_nodo3_environment.json).
