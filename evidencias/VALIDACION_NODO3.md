# Validación del contrato ATM y correcciones del Banco Central

9 de octubre de 2026, America/Mexico_City. Core: https://banco-central-nodo1.onrender.com. Sucursal: https://sucursal-nodo2.onrender.com. Cajero: https://node3-atm.onrender.com. Las cifras de la tabla documentan la validación específica del Core; la aceptación posterior de la aplicación ATM está descrita al final.

El Core incorpora identidad del nodo, efectivo actual, comprobantes con saldos originales, rechazos durables y recuperación por clave. Saldo, efectivo del cajero, ledger y comprobante se guardan dentro de la misma transacción PostgreSQL. Los rechazos no son transacciones financieras. Las operaciones de sucursal, la apertura con cero, claves `sucursal:...`, errores compartidos y paginación mantienen compatibilidad.

El panel incorpora filtro por sucursal/cajero, fechas completas en horario de México, filtros persistentes al paginar y CSV con identidad del nodo. La edición de efectivo exige coordinación sin pendientes y comprueba que el valor anterior sigue vigente. Realtime avisa de nuevos movimientos mediante un canal privado; las tablas financieras conservan sus restricciones RLS.

## Resultados

| Verificación | Resultado |
| --- | --- |
| PostgreSQL aislado, HTTP y reglas del Core | 40 pruebas / 269 aserciones aprobadas |
| Concurrencia HTTP real, Apache + PostgreSQL aislado | 3 pruebas / 21 aserciones aprobadas |
| Total Core | **43 pruebas / 290 aserciones** |
| Regresión original de Nodo 2 | **36 pruebas aprobadas** |
| Postman en Render: contrato y compatibilidad | 49 solicitudes / 97 aserciones aprobadas |
| Postman: nuevo depósito y señal Realtime | 1 solicitud / 2 aserciones aprobadas |
| Postman después del reinicio del Core | 4 solicitudes / 8 aserciones aprobadas |
| Total Postman | **54 solicitudes / 107 aserciones; cero fallos** |
| Formato PHP, JavaScript y esquema Postman | Pint, `node --check` y colección 2.1 válidos |

La suite aislada comprueba los cinco códigos de rechazo, cuenta bloqueada, límite monetario, comprobantes inmutables, identidad estable después de rotar la clave y rollback de saldo/efectivo/ledger si falla la persistencia del comprobante. Las pruebas HTTP concurrentes cubren retiro sobre saldo compartido, efectivo compartido entre cuentas y reintentos simultáneos de una misma clave.

Antes del despliegue, ambas migraciones se ejecutaron dentro de una transacción de Supabase que se revirtió: se validó el SQL sin alterar los datos. Render aplicó las migraciones incrementales al iniciar. Los despliegues `3bb90bd` y `d22cb3e` se activaron automáticamente mediante push y alcanzaron Live. Después del segundo se recuperó el mismo comprobante; el saldo de prueba siguió en $750.15 tras el depósito posterior, y el efectivo en $1,250.15.

El CSV real de la cuenta de prueba devuelve tres movimientos al consultar el día completo: uno de sucursal y dos de cajero. Cada filtro por nodo devuelve únicamente sus movimientos. El panel recibió el aviso de Realtime después de una apertura real de $0.01. La suscripción anónima al canal privado fue rechazada y el administrador conectó correctamente después del nuevo despliegue.

Las pruebas de Postman abrieron cuatro cuentas nuevas de demostración. Los únicos movimientos ATM efectuados fueron un retiro de $300 y un depósito de $50.15 sobre una cuenta creada en esta validación. Los reintentos no cambiaron nuevamente el saldo ni el efectivo. No se modificaron cuentas anteriores.

## Evidencias

- [Contrato y compatibilidad en Render](nodo3_contrato_render_resultados.json).
- [Postman para señal Realtime](nodo3_realtime_postman.json).
- [Recuperación después del reinicio](nodo3_recuperacion_reinicio.json).
- [Filtros reales del panel](nodo1_filtros_corregidos.json) y [captura](nodo1_filtros_corregidos.png).
- [Autorización y aviso Realtime](nodo3_realtime_panel.json) y [captura](nodo3_realtime_panel.png).

Los reportes publicados contienen nombres de solicitudes, códigos HTTP y aserciones; los entornos y reportes completos con claves permanecen privados.

## Aceptación completada de la aplicación ATM

La aplicación real del Nodo 3 aprobó 46 solicitudes Postman y 49 aserciones, incluidas consultas después de reiniciar Render. Se verificaron configuración, sesión técnica, retiro de $300 sobre una apertura de $1,000, saldo $700 en los tres nodos, depósito de $50.15, rechazos, reintentos, comprobantes e inventario. Las 39 pruebas PostgreSQL del cajero se registran por separado; una prueba adicional con el Core real recuperó un depósito de $0.01 después de perder su respuesta, sin duplicarlo. Ver [reporte conjunto](../ENTREGA_NODO3.md).

La colección específica del contrato tiene 50 solicitudes en una ejecución completa, incluida la prueba Realtime. La cifra 54 corresponde a esa validación más cuatro consultas/reintento posteriores al reinicio del Core. La colección conjunta de la aplicación ATM es distinta: `postman_integracion_collection.json`, documentada en [FLUJO_POSTMAN.md](../FLUJO_POSTMAN.md).
