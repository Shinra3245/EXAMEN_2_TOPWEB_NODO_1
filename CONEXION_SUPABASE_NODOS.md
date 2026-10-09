# Conexión de los nodos con Supabase (Actualizado)

## Estado actual

El **Nodo 1 (Banco Central)** ya está completamente implementado y su API está lista para recibir peticiones.
Las tablas `bank_admins`, `bank_nodes`, `users_accounts` y `transactions` están aseguradas mediante RLS.

## Cómo debe conectarse cada nodo (Nodo 2 y Nodo 3)

**Nodo 2 (Sucursal) y Nodo 3 (Cajero)** no deben conectarse a Supabase directamente para manipular saldos. Deben hacer peticiones HTTP a la API REST de Laravel del Nodo 1.

### Credenciales necesarias en Nodo 2 y Nodo 3

Deberán configurar estas variables en sus respectivos archivos `.env`:

```dotenv
# URL de la API de Laravel (Sustituir por la de Render cuando termine el despliegue)
BANCO_CENTRAL_URL=https://banco-central-nodo1.onrender.com

# Clave exclusiva generada por el administrador en el panel del Nodo 1
BANCO_CENTRAL_API_KEY=tu_api_key_aqui
```

### Cabeceras HTTP Requeridas

Todas las peticiones a la API del Nodo 1 deben llevar las siguientes cabeceras:

```http
X-API-KEY: <tu_api_key_aqui>
Content-Type: application/json
Accept: application/json
```

## Guía Operativa para Compañeros de Equipo

1. **Obtener su API Key**: 
   El administrador del Nodo 1 debe entrar al panel web (por ejemplo, `https://banco-central-nodo1.onrender.com/admin/login`), registrar la sucursal o cajero, y copiar la clave secreta que aparecerá por pantalla **solo una vez**. Debe entregar esta clave de forma segura al Nodo 2 y 3.
   
2. **Consultar el Contrato API**:
   Revisar el archivo `openapi.yaml` (o `postman_collection.json`) dentro de la carpeta `NODE1` para ver exactamente la estructura del JSON que deben enviar. 
   **Importante:** Todas las peticiones `POST` de creación de cuenta o transacciones exigen un campo `idempotency_key` (un UUID único por cada operación) para evitar cobros dobles si el cajero pierde el internet.

3. **Flujo de Prueba Obligatorio**:
   Se debe probar conjuntamente el flujo: 
   - El Nodo 2 abre la cuenta con **$1,000**.
   - El Nodo 3 hace un retiro de **$300**.
   - El Nodo 1 procesa y deja el saldo en **$700**.
   
   *Nota para el Cajero (Nodo 3):* Recuerda verificar y descontar tu efectivo físico local independientemente del saldo lógico en la cuenta.
