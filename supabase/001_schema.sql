-- Nodo 1: Banco Central. Ejecutar SOLO en el proyecto nuevo de la organización Examen.
-- Aplicado el 9 de octubre de 2026 al proyecto rtfdnrwcjwovpplmfthc (Banco Central).
-- Las claves secretas de Supabase se usan exclusivamente en el backend Laravel.
-- Las sucursales y cajeros reciben sus propias API Keys, nunca la service_role.

BEGIN;

CREATE TABLE public.bank_admins (
  user_id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
  nombre TEXT NOT NULL CHECK (length(trim(nombre)) > 0),
  activo BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE public.bank_nodes (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  nombre TEXT NOT NULL CHECK (length(trim(nombre)) > 0),
  tipo TEXT NOT NULL CHECK (tipo IN ('sucursal', 'cajero')),
  responsable TEXT NOT NULL CHECK (length(trim(responsable)) > 0),
  api_key_hash TEXT NOT NULL UNIQUE CHECK (api_key_hash ~ '^[0-9a-f]{64}$'),
  efectivo_disponible NUMERIC(15,2) NOT NULL DEFAULT 0 CHECK (efectivo_disponible >= 0),
  activo BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE public.users_accounts (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  numero_cuenta TEXT UNIQUE NOT NULL CHECK (length(trim(numero_cuenta)) > 0),
  nombre_titular TEXT NOT NULL CHECK (length(trim(nombre_titular)) > 0),
  saldo_global NUMERIC(15,2) NOT NULL DEFAULT 0 CHECK (saldo_global >= 0),
  estado TEXT NOT NULL DEFAULT 'activa' CHECK (estado IN ('activa', 'bloqueada')),
  sucursal_id UUID NOT NULL REFERENCES public.bank_nodes(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE public.transactions (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  nodo_id UUID NOT NULL REFERENCES public.bank_nodes(id),
  cuenta_origen TEXT REFERENCES public.users_accounts(numero_cuenta),
  cuenta_destino TEXT REFERENCES public.users_accounts(numero_cuenta),
  monto NUMERIC(15,2) NOT NULL CHECK (monto > 0),
  tipo TEXT NOT NULL CHECK (tipo IN ('retiro', 'deposito', 'transferencia')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT transactions_cuentas_segun_tipo CHECK (
    (tipo = 'retiro' AND cuenta_origen IS NOT NULL AND cuenta_destino IS NULL)
    OR (tipo = 'deposito' AND cuenta_origen IS NULL AND cuenta_destino IS NOT NULL)
    OR (tipo = 'transferencia' AND cuenta_origen IS NOT NULL
        AND cuenta_destino IS NOT NULL AND cuenta_origen <> cuenta_destino)
  )
);

CREATE INDEX transactions_nodo_fecha_idx ON public.transactions(nodo_id, created_at DESC);
CREATE INDEX transactions_origen_fecha_idx ON public.transactions(cuenta_origen, created_at DESC);
CREATE INDEX transactions_destino_fecha_idx ON public.transactions(cuenta_destino, created_at DESC);
CREATE INDEX users_accounts_sucursal_idx ON public.users_accounts(sucursal_id);

-- Bloqueo de acceso directo desde clientes públicos o usuarios autenticados.
-- Laravel verifica Supabase Auth, bank_admins y las API Keys de los nodos.
ALTER TABLE public.bank_admins ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.bank_nodes ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.users_accounts ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.transactions ENABLE ROW LEVEL SECURITY;

REVOKE ALL ON TABLE public.bank_admins, public.bank_nodes,
  public.users_accounts, public.transactions FROM PUBLIC, anon, authenticated;
REVOKE ALL ON TABLE public.bank_admins, public.bank_nodes,
  public.users_accounts, public.transactions FROM service_role;
GRANT SELECT, INSERT, UPDATE ON TABLE public.bank_admins,
  public.bank_nodes, public.users_accounts TO service_role;
GRANT SELECT, INSERT ON TABLE public.transactions TO service_role;

-- El historial es inmutable para operaciones normales de la aplicación.
-- El propietario PostgreSQL conserva la capacidad administrativa de alterar el esquema.
CREATE FUNCTION public.reject_transaction_changes()
RETURNS TRIGGER
LANGUAGE plpgsql
SET search_path = ''
AS $$
BEGIN
  RAISE EXCEPTION 'El historial de transacciones no permite modificaciones ni borrados.';
END;
$$;

REVOKE ALL ON FUNCTION public.reject_transaction_changes()
  FROM PUBLIC, anon, authenticated, service_role;

CREATE TRIGGER transactions_no_update_delete
BEFORE UPDATE OR DELETE ON public.transactions
FOR EACH ROW EXECUTE FUNCTION public.reject_transaction_changes();

CREATE TRIGGER transactions_no_truncate
BEFORE TRUNCATE ON public.transactions
FOR EACH STATEMENT EXECUTE FUNCTION public.reject_transaction_changes();

COMMIT;
