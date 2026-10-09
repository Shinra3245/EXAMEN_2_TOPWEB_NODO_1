-- Verificación de solo lectura del esquema y los permisos.
SELECT
  c.relname AS tabla,
  c.relrowsecurity AS rls_activado,
  has_table_privilege('anon', c.oid, 'SELECT') AS anon_puede_leer,
  has_table_privilege('authenticated', c.oid, 'UPDATE') AS usuario_puede_modificar,
  has_table_privilege('service_role', c.oid, 'SELECT') AS backend_puede_leer,
  has_table_privilege('service_role', c.oid, 'DELETE') AS backend_puede_borrar,
  (SELECT count(*) FROM pg_trigger t
   WHERE t.tgrelid = c.oid AND NOT t.tgisinternal) AS protecciones_trigger
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public'
  AND c.relname IN ('bank_admins', 'bank_nodes', 'users_accounts', 'transactions')
ORDER BY c.relname;
