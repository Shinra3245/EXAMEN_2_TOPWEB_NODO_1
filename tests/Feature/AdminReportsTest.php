<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminReportsTest extends TestCase
{
    use RefreshDatabase;

    private function history(): array
    {
        $branch = (string) Str::uuid();
        $atm = (string) Str::uuid();
        foreach ([$branch => ['sucursal', '=Sucursal <script>alert(1)</script>'], $atm => ['cajero', 'Cajero']] as $id => [$type, $name]) {
            DB::table('bank_nodes')->insert(['id' => $id, 'nombre' => $name, 'tipo' => $type, 'responsable' => 'Prueba', 'api_key_hash' => hash('sha256', $id), 'efectivo_disponible' => '1000.00']);
        }
        DB::table('users_accounts')->insert(['numero_cuenta' => 'REPORT', 'nombre_titular' => 'Prueba', 'sucursal_id' => $branch]);
        foreach ([[$branch, '2026-10-09 05:59:59+00'], [$branch, '2026-10-09 06:00:00+00'], [$branch, '2026-10-10 05:59:59.999999+00'], [$branch, '2026-10-10 06:00:00+00'], [$atm, '2026-10-09 18:00:00+00']] as [$node, $date]) {
            DB::table('transactions')->insert(['nodo_id' => $node, 'tipo' => 'deposito', 'cuenta_destino' => 'REPORT', 'monto' => '10.00', 'created_at' => $date]);
        }

        return [$branch, $atm];
    }

    public function test_admin_report_filters_node_and_includes_the_complete_local_day_in_html_and_csv(): void
    {
        [$branch, $atm] = $this->history();
        $query = http_build_query(['nodo_id' => $branch, 'cuenta' => 'REPORT', 'fecha_inicio' => '2026-10-09', 'fecha_fin' => '2026-10-09']);

        $this->withSession(['admin_authenticated' => true])->get('/admin/transactions?'.$query)->assertOk()->assertViewHas('transactions', fn ($rows): bool => $rows->total() === 2 && $rows->every(fn ($row): bool => $row->nodo_id === $branch))->assertSee('2026-10-09 23:59:59')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $csv = $this->withSession(['admin_authenticated' => true])->get('/admin/transactions?'.$query.'&export=csv')->assertOk()->assertStreamed()->streamedContent();
        $rows = array_map(fn (string $row): array => str_getcsv($row, escape: ''), array_filter(explode("\n", trim($csv))));
        $this->assertCount(3, $rows);
        $this->assertSame('Nodo ID', $rows[0][7]);
        $this->assertSame($branch, $rows[1][7]);
        $this->assertSame("'=Sucursal <script>alert(1)</script>", $rows[1][8]);
        $this->assertStringNotContainsString($atm, $csv);
    }

    public static function oneSidedDates(): array
    {
        return [['fecha_inicio=2026-10-09', 4], ['fecha_fin=2026-10-09', 4]];
    }

    #[DataProvider('oneSidedDates')]
    public function test_admin_report_accepts_each_date_boundary_independently(string $query, int $expected): void
    {
        $this->history();

        $this->withSession(['admin_authenticated' => true])->get('/admin/transactions?'.$query)->assertOk()->assertViewHas('transactions', fn ($rows): bool => $rows->total() === $expected);
    }

    public function test_invalid_node_and_reversed_dates_are_rejected_instead_of_exporting_unfiltered_history(): void
    {
        $this->history();

        $this->withSession(['admin_authenticated' => true])->getJson('/admin/transactions?nodo_id='.Str::uuid().'&export=csv')->assertUnprocessable()->assertJsonValidationErrors('nodo_id');
        $this->withSession(['admin_authenticated' => true])->getJson('/admin/transactions?fecha_inicio=2026-10-10&fecha_fin=2026-10-09')->assertUnprocessable()->assertJsonValidationErrors('fecha_fin');
    }

    public function test_report_pagination_keeps_node_account_and_date_filters(): void
    {
        [$branch] = $this->history();
        for ($i = 0; $i < 21; $i++) {
            DB::table('transactions')->insert(['nodo_id' => $branch, 'tipo' => 'deposito', 'cuenta_destino' => 'REPORT', 'monto' => '0.01', 'created_at' => '2026-10-09 18:00:00+00']);
        }

        $response = $this->withSession(['admin_authenticated' => true])->get('/admin/transactions?nodo_id='.$branch.'&cuenta=REPORT&fecha_inicio=2026-10-09&fecha_fin=2026-10-09')->assertOk();
        parse_str(parse_url($response->viewData('transactions')->nextPageUrl(), PHP_URL_QUERY), $params);
        $this->assertSame(['nodo_id' => $branch, 'cuenta' => 'REPORT', 'fecha_inicio' => '2026-10-09', 'fecha_fin' => '2026-10-09', 'page' => '2'], $params);
    }

    public function test_cash_assignment_requires_coordination_and_rejects_a_stale_balance(): void
    {
        [, $atm] = $this->history();

        $this->withSession(['admin_authenticated' => true])->post('/admin/nodes/'.$atm.'/cash', ['efectivo_asignado' => '1500.00', 'efectivo_anterior' => '1000.00'])->assertSessionHasErrors('confirmar_sin_pendientes');
        $this->withSession(['admin_authenticated' => true])->post('/admin/nodes/'.$atm.'/cash', ['efectivo_asignado' => '1500.00', 'efectivo_anterior' => '900.00', 'confirmar_sin_pendientes' => '1'])->assertSessionHasErrors('efectivo_asignado');
        $this->assertDatabaseHas('bank_nodes', ['id' => $atm, 'efectivo_disponible' => '1000.00']);
    }

    public function test_realtime_configuration_is_private_admin_only_and_never_exposes_the_service_key(): void
    {
        $userId = (string) Str::uuid();
        DB::table('auth.users')->insert(['id' => $userId]);
        DB::table('bank_admins')->insert(['user_id' => $userId, 'nombre' => 'Admin']);
        config(['services.supabase.url' => 'https://supabase.example.test', 'services.supabase.publishable_key' => 'public-key', 'services.supabase.secret_key' => 'private-service-secret']);
        $session = ['admin_authenticated' => true, 'admin_user_id' => $userId, 'supabase_access_token' => 'user-jwt', 'supabase_expires_at' => now()->timestamp + 3600];

        $this->withSession($session)->getJson('/admin/realtime')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['url' => 'https://supabase.example.test', 'publishable_key' => 'public-key', 'access_token' => 'user-jwt', 'expires_at' => $session['supabase_expires_at'], 'topic' => 'bank-admin']);
        DB::table('bank_admins')->where('user_id', $userId)->update(['activo' => false]);
        $this->withSession($session)->getJson('/admin/realtime')->assertForbidden();
    }

    public function test_realtime_configuration_refreshes_expired_user_tokens(): void
    {
        $userId = (string) Str::uuid();
        DB::table('auth.users')->insert(['id' => $userId]);
        DB::table('bank_admins')->insert(['user_id' => $userId, 'nombre' => 'Admin']);
        config(['services.supabase.url' => 'https://supabase.example.test', 'services.supabase.publishable_key' => 'public-key']);
        Http::preventStrayRequests();
        Http::fake(['https://supabase.example.test/auth/v1/token?grant_type=refresh_token' => Http::response(['user' => ['id' => $userId], 'access_token' => 'renewed-jwt', 'refresh_token' => 'renewed-refresh', 'expires_in' => 3600])]);

        $this->withSession(['admin_authenticated' => true, 'admin_user_id' => $userId, 'supabase_access_token' => 'expired', 'supabase_refresh_token' => 'old-refresh', 'supabase_expires_at' => 0])->getJson('/admin/realtime')->assertOk()->assertJsonPath('access_token', 'renewed-jwt')->assertSessionHas('supabase_refresh_token', 'renewed-refresh');
        Http::assertSent(fn ($request): bool => $request['refresh_token'] === 'old-refresh' && $request->header('apikey') === ['public-key']);

    }
}
