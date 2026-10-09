# Prompt para implementar el Nodo 1: Banco Central

Actúa como el agente responsable de completar el **Nodo 1 del examen Sistema Bancario Distribuido**, con **Laravel y Supabase**. Implementa, ejecuta y verifica el trabajo; avanza por bloques hasta completar el nodo. Una explicación, un plan o una maqueta no sustituyen la implementación.

## 1. Carpeta y contexto que debes leer

Trabaja dentro de:

```text
/home/omarbolanos/Documentos/SEMESTRE 9/TOPWEB/Parcial2/EXAMEN/NODE1
```

Antes de editar, revisa instrucciones locales, estado de Git, versiones de PHP/Composer/Node, puertos disponibles y estos archivos:

- `README.md`, `CONEXION_SUPABASE_NODOS.md` y `.env.example` del Nodo 1.
- `supabase/001_schema.sql` y `supabase/002_verificar_schema.sql`.
- El PDF `Examen 2 Práctico Sistema Bancario Distribuido.pdf`, el archivo `tareas_sistema_bancario.md` y el borrador `openapi.yaml`, ubicados en la carpeta padre `EXAMEN`.

Preserva los archivos, credenciales, evidencias y cambios existentes. Hay otros integrantes trabajando en `NODE2` y `NODE3`: puedes consultar sus contratos, pero implementa y escribe únicamente dentro de `NODE1`. Consulta documentación oficial actual cuando necesites confirmar compatibilidad o comportamiento de Laravel/Supabase.

## 2. Estado de Supabase que debes respetar

- Organización: **EXAMEN**, plan gratuito.
- Proyecto: **Banco Central**, referencia `rtfdnrwcjwovpplmfthc`.
- URL: `https://rtfdnrwcjwovpplmfthc.supabase.co`.
- Región: `us-east-1`.
- Tablas existentes: `bank_admins`, `bank_nodes`, `users_accounts`, `transactions`.
- Las cuatro tablas tienen RLS. `anon` y `authenticated` no tienen acceso directo a ellas.
- `transactions` tiene dos triggers que impiden UPDATE, DELETE y TRUNCATE.
- La configuración privada está en `.env.supabase.local`, excluida de Git. La clave secreta activa se llama `laravel_core`.

Verifica el estado actual antes de asumir que las tablas siguen vacías. El SQL inicial ya se ejecutó y **no es repetible**: no lo vuelvas a aplicar. Cualquier cambio necesario debe hacerse mediante una migración incremental, conservando los datos y las protecciones.

La clave secreta de Supabase permanece en el servidor del Banco Central. Las sucursales y cajeros usarán **API Keys propias emitidas por Laravel**, con cabecera `X-API-KEY`, para consumir la API del Nodo 1.

## 3. Implementación requerida

### Proyecto Laravel y persistencia

- Crea una aplicación Laravel compatible con el entorno disponible. Como la carpeta ya contiene archivos y Git, prepara el scaffolding aparte e intégralo sin sobrescribir el trabajo previo.
- Configura Composer, el frontend y NPM si corresponde, entornos de desarrollo/pruebas y un arranque reproducible.
- Integra Supabase PostgreSQL y Supabase Auth. Obtén los datos de conexión reales que falten; no deduzcas credenciales o direcciones de pooler sin verificarlas.
- Para las operaciones monetarias usa una transacción PostgreSQL con bloqueo de filas, o una única función RPC transaccional con permisos restringidos. No actualices saldo e historial como peticiones REST independientes.
- Representa dinero con decimales exactos o centavos enteros; evita cálculos con flotantes. Valida precisión, límites, montos positivos y cuentas existentes/activas.
- Implementa idempotencia en solicitudes que puedan repetirse. Una solicitud repetida debe conservar un único efecto; reutilizar su identificador con datos diferentes debe producir un conflicto documentado.

### Autenticación y administración

- Implementa inicio y cierre de sesión mediante Supabase Auth. Verifica la sesión o token con un mecanismo válido; decodificar un JWT sin verificarlo no autentica a nadie.
- Autoriza el panel comprobando `bank_admins.user_id` y `activo`. Un usuario autenticado sin autorización administrativa no debe acceder al panel ni administrar nodos.
- Prepara un procedimiento seguro y reproducible para crear el primer administrador. Guarda sus credenciales solo en configuración privada y no las imprimas en registros o documentación.
- Protege sesiones, formularios y endpoints administrativos: cookies seguras según el entorno, CSRF donde corresponda, validación y límites de intentos.
- Implementa un panel funcional en español para crear/consultar sucursales y cajeros, designar responsables, asignar/ver efectivo, consultar cuentas y ver historial global filtrado por nodo, cuenta y fecha.
- Genera API Keys con aleatoriedad criptográfica. Guarda solo su hash SHA-256 en `bank_nodes`; muestra la clave completa únicamente al emitirla o sustituirla.
- Permite desactivar nodos y sustituir sus claves, invalidando las anteriores. Deriva la identidad del nodo desde su clave validada; no confíes en un `nodo_id` enviado por el cliente.
- Implementa reportes administrativos de operaciones, al menos consultables y exportables en CSV.

### API bancaria e integración

Implementa y documenta operaciones para:

1. Apertura de cuentas por sucursales, con número único, titular, estado y sucursal de origen. Registra el saldo inicial como un movimiento trazable.
2. Consulta del saldo central.
3. Depósitos, retiros y transferencias, con actualización atómica del saldo y registro del movimiento.
4. Historial por cuenta/nodo y reportes. El administrador puede consultar el historial global; define y documenta el alcance permitido a cada tipo de nodo conforme al examen.
5. Gestión administrativa de nodos, responsables, efectivo y claves.

Conserva como punto de partida `POST /api/accounts` y `POST /api/transactions` del borrador. Define las rutas restantes y publica el **contrato real** en `NODE1/openapi.yaml`, incluyendo cuerpos, respuestas, errores, autenticación, filtros e idempotencia. El contrato previo es un borrador, no una API ya implementada.

Distingue el saldo de las cuentas del efectivo físico. El Nodo 1 administra la asignación/disponibilidad requerida; el Nodo 3 verifica y gestiona su inventario local. Documenta cómo comunicar y conciliar estos valores sin duplicar descuentos. No desarrolles el software del ATM dentro del Nodo 1.

Expón a los otros agentes la URL real de Laravel, el contrato definitivo y el procedimiento para obtener su clave propia. Mantén secretos fuera del navegador, respuestas públicas, capturas, logs y Git. Actualiza la guía de conexión del Nodo 1 con el comportamiento implementado.

Si falta una decisión funcional del examen —por ejemplo, una política no definida sobre efectivo o permisos— identifica lo pendiente y solicita solo la aclaración indispensable mientras avanzas con el trabajo independiente. No añadas NIP, comisiones, préstamos u otras reglas bancarias como requisitos confirmados.

## 4. Pruebas obligatorias

Usa PHPUnit/Pest o el sistema de pruebas elegido para Laravel. Las pruebas automáticas que escriban, limpien datos o apliquen migraciones deben usar **PostgreSQL aislado**, preferentemente en un contenedor temporal sin exposición pública. Nunca ejecutes `migrate:fresh`, truncados o limpieza de fixtures contra el proyecto compartido de Supabase. SQLite no sustituye las pruebas de bloqueo y concurrencia PostgreSQL.

Comprueba con resultados reales:

- **Acceso:** sin autenticación, token inválido/expirado, usuario sin rol administrativo y administrador inactivo. Claves de nodos inexistentes, sustituidas y nodos desactivados deben rechazarse.
- **Permisos:** un cajero no puede crear cuentas ni administrar nodos. Un nodo no puede suplantar a otro enviando su ID ni consultar información fuera del alcance autorizado.
- **Apertura:** número de cuenta único, sucursal válida/activa, saldo inicial válido y registro del depósito inicial. Un reintento no crea otra cuenta ni otro abono.
- **Dinero:** depósitos, retiros y transferencias correctos; rechazo de monto cero/negativo, precisión inválida, cuenta inexistente/bloqueada, fondos insuficientes y transferencia a la misma cuenta.
- **Atomicidad:** si falla la inserción del movimiento, se revierte el cambio de saldo. En una transferencia no puede quedar debitado solo el origen o acreditado solo el destino.
- **Concurrencia:** con saldo de $1,000, dos retiros simultáneos de $800 permiten como máximo un retiro, dejan $200 y registran solo un retiro exitoso. Incluye una prueba real con conexiones concurrentes, no llamadas secuenciales.
- **Idempotencia:** repetir el mismo retiro, incluso simultáneamente, produce un único descuento y movimiento. Cambiar el cuerpo usando el mismo identificador se rechaza sin otro efecto.
- **Historial:** UPDATE, DELETE y TRUNCATE se rechazan; no existe una ruta pública que permita modificar el historial.
- **Supabase:** RLS y permisos conservados; el cliente público no obtiene datos bancarios y el backend autorizado puede operar. No afirmes que RLS restringe una clave de servicio: la autorización del backend también debe comprobarse.
- **Panel/reportes:** login, protección de rutas, alta de sucursal/cajero, responsables, efectivo, entrega de clave, filtros, exportación y saldo visible correcto. Obtén capturas del flujo ejecutado y comprueba que no contengan secretos.

Entrega una **colección Postman importable** y un entorno de ejemplo sin credenciales reales. Incluye el flujo de $1,000 → retiro de $300 → saldo de $700, consultas y casos de rechazo importantes. Documenta cómo configurar las claves privadas antes de ejecutarlo.

La integración automática puede usar clientes de prueba que simulen la sucursal y el cajero llamando a la API real de Laravel. Identifica estas simulaciones. La integración con los nodos reales solo se marca completada cuando se haya ejecutado con ellos.

En Supabase realiza una demostración controlada con entidades de prueba claramente identificadas, preservando el historial. No vuelvas a cargar automáticamente los $1,000 cada vez que se inicia la aplicación. Deja preparado el flujo conjunto para la presentación del equipo.

## 5. Documentación, ejecución y despliegue

- Completa `README.md`: instalación, configuración sin secretos, arranque, administrador inicial, pruebas, arquitectura, API, integración y resultados verificados.
- Incluye `.env.example`, OpenAPI, Postman, scripts útiles y un resumen de pruebas con comandos ejecutados, resultados y limitaciones reales.
- Prepara Docker y una configuración de despliegue adecuada para Laravel, incluyendo HTTPS, secretos, logs sin credenciales y migraciones incrementales.
- Prepara CI vinculada al repositorio: pruebas, validación del contrato y build. Ejecuta los controles aplicables, incluidos `composer validate`, `composer audit` y build/auditoría NPM si usas dependencias JavaScript. Revisa compatibilidad antes de corregir dependencias.
- El equipo debe demostrar Vercel y Coolify; Render también figura en el material. El destino concreto del Nodo 1 sigue sin asignarse. Si existe un destino y acceso autorizado, realiza y verifica el despliegue continuo. Si faltan, deja la configuración lista y explica exactamente qué dato o acceso falta, sin inventar un enlace ni declarar un despliegue realizado.
- Preserva el repositorio del Nodo 1 y sus cambios existentes. No mezcles modificaciones de otros agentes ni alteres repositorios o despliegues ajenos.

## 6. Criterio de entrega

El Nodo 1 debe arrancar de forma reproducible, tener panel y API operativos, autenticar administradores/nodos y ejecutar el flujo **$1,000 → $300 → $700** conservando saldo e historial consistentes. Sus pruebas de permisos, atomicidad, concurrencia e idempotencia deben pasar.

Avanza por bloques: contrato y estructura; persistencia/seguridad; API monetaria; panel/reportes; integración/pruebas; documentación/despliegue. Corrige los fallos que encuentres antes de dar por terminado un bloque.

Al terminar informa qué quedó implementado, los comandos y resultados de las pruebas, dónde están OpenAPI/Postman/evidencias, cómo conectar los otros nodos y qué integración o despliegue continúa pendiente por falta de acceso. No presentes funciones propuestas, simulaciones o pruebas no ejecutadas como resultados completos.
