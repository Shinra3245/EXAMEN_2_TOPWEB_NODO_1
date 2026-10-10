# Unión de los tres nodos completada

**Servicios:** [Banco Central](https://banco-central-nodo1.onrender.com), [Sucursal](https://sucursal-nodo2.onrender.com) y [Cajero](https://node3-atm.onrender.com).

**Corregido en Nodo 3:** recuperación con API Key rotada sin liberar reservas; inicio de sesión en Chrome detrás del proxy; configuración persistente y documentación del contrato. GitHub Actions despliega cada push a `main` después de pasar las pruebas.

**Prueba real:** cuenta `13447350977369660092`, creada en la sucursal con $1,000; retiro de $300 desde el cajero; saldo $700 confirmado en los tres nodos. Los reintentos no duplicaron el retiro. Se probaron rechazos y un depósito de $50.15 en otra cuenta. Efectivo del cajero al terminar esa primera validación: $1,000.31.

**Resultados:** 39 pruebas del cajero sin omisiones; dependencias sin vulnerabilidades; 46 solicitudes Postman y 49 aserciones aprobadas. Tras reiniciar Render se conservaron comprobantes, efectivo y sesión. Una prueba adicional recuperó un depósito de $0.01 después de perder la respuesta del Central, sin duplicarlo.

**Para el compañero:** actualizar `main`, importar los archivos de `postman/` y seguir `docs/flujo-postman.md` del repositorio del Nodo 3. Credenciales técnicas privadas: `NODE3_INTEGRACION/.local-admin.render.txt`, en el workspace de Omar. No cambiar la clave de cifrado ni liberar operaciones pendientes.

La base gratuita del cajero vence el **8 de noviembre de 2026**. Preparar respaldo o renovación si seguirá usándose. La unión funcional está completa; la entrega ya incluye guion de 10 minutos, evidencias y respaldo verificado.

El ensayo adicional de cierre volvió a aprobar 46 solicitudes / 49 aserciones, con otra cuenta y un reinicio real del cajero. Efectivo después de ese ensayo: $750.46; sin operaciones pendientes. La cuenta original conserva $700. La exposición ante el profesor corresponde al equipo.
