<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    public function test_login_redirects_if_unauthenticated()
    {
        $response = $this->get('/admin');
        $response->assertRedirect(route('admin.login'));
    }
    
    public function test_can_view_login_form()
    {
        $response = $this->get('/admin/login');
        $response->assertStatus(200);
        $response->assertSee('Iniciar Sesión');
    }
}
