#!/bin/sh
set -eu

test_root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
test_suffix=$$
test_network="examen-nodo1-test-$test_suffix"
test_database="examen-nodo1-pg-$test_suffix"
test_server="examen-nodo1-http-$test_suffix"
test_image=examen-nodo1:validation
test_key='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='

cleanup() {
    docker rm -f "$test_server" "$test_database" >/dev/null 2>&1 || true
    docker network rm "$test_network" >/dev/null 2>&1 || true
}
trap cleanup EXIT HUP INT TERM

cd "$test_root"
composer install --no-interaction
docker build -t "$test_image" .
docker network create "$test_network" >/dev/null
docker run -d --name "$test_database" --network "$test_network" --network-alias pgsql \
    -e POSTGRES_USER=sail -e POSTGRES_PASSWORD=password -e POSTGRES_DB=testing \
    postgres:16-alpine >/dev/null

test_attempt=0
until docker exec "$test_database" pg_isready -h 127.0.0.1 -U sail -d testing >/dev/null 2>&1; do
    test_attempt=$((test_attempt + 1))
    [ "$test_attempt" -lt 25 ] || exit 1
    sleep 1
done

docker run -d --name "$test_server" --network "$test_network" --network-alias web \
    -e APP_ENV=testing -e APP_DEBUG=false -e APP_KEY="$test_key" \
    -e DB_CONNECTION=pgsql -e DB_HOST=pgsql -e DB_PORT=5432 -e DB_DATABASE=testing \
    -e DB_USERNAME=sail -e DB_PASSWORD=password -e DB_SSLMODE=disable -e DB_URL= \
    -e SESSION_DRIVER=cookie -e CACHE_STORE=file -e QUEUE_CONNECTION=sync \
    -e SUPABASE_URL=https://supabase.example.test -e SUPABASE_PUBLISHABLE_KEY=test-key -e SUPABASE_SECRET_KEY=test-key \
    --mount "type=bind,source=$test_root,target=/var/www/html,readonly" \
    --tmpfs /var/www/html/storage --tmpfs /var/www/html/bootstrap/cache \
    "$test_image" sh -c 'mkdir -p storage/framework/views storage/framework/sessions storage/framework/cache/data storage/logs; sh docker-entrypoint.sh' >/dev/null

test_attempt=0
until docker exec "$test_server" curl --fail --silent http://localhost:10000/up >/dev/null 2>&1; do
    test_attempt=$((test_attempt + 1))
    [ "$test_attempt" -lt 25 ] || { docker logs "$test_server"; exit 1; }
    sleep 1
done

docker run --rm --network "$test_network" \
    -e APP_ENV=testing -e APP_KEY="$test_key" \
    -e DB_CONNECTION=pgsql -e DB_HOST=pgsql -e DB_PORT=5432 -e DB_DATABASE=testing \
    -e DB_USERNAME=sail -e DB_PASSWORD=password -e DB_URL= -e DB_SSLMODE=disable \
    -e SESSION_DRIVER=array -e CACHE_STORE=array -e QUEUE_CONNECTION=sync \
    -e SUPABASE_URL=https://supabase.example.test -e SUPABASE_PUBLISHABLE_KEY=test-key -e SUPABASE_SECRET_KEY=test-key \
    -e BANK_CONCURRENCY_URL=http://web:10000 \
    --mount "type=bind,source=$test_root,target=/var/www/html,readonly" \
    --tmpfs /var/www/html/storage --tmpfs /var/www/html/bootstrap/cache \
    "$test_image" sh -c 'mkdir -p storage/framework/views storage/framework/sessions storage/framework/cache/data storage/logs; php artisan test --compact --do-not-cache-result tests/Feature/BankApiTest.php tests/Feature/DeploymentCompatibilityTest.php tests/Feature/AdminPanelTest.php tests/Feature/AtmContractTest.php tests/Feature/AdminReportsTest.php && php artisan test --compact --do-not-cache-result tests/Feature/ConcurrencyTest.php'
