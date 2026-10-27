<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $password = 'rahasia123'): User
    {
        return User::factory()->create([
            'email'    => 'petugas@example.com',
            'password' => Hash::make($password),
        ]);
    }

    public function test_login_berhasil_dicatat_di_audit_log(): void
    {
        $this->user();

        $this->post('/login', ['email' => 'petugas@example.com', 'password' => 'rahasia123'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('audit_logs', ['modul' => 'auth', 'aksi' => 'login']);
    }

    public function test_login_dikunci_setelah_5_percobaan_gagal(): void
    {
        $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'petugas@example.com', 'password' => 'salah'])
                ->assertSessionHasErrors(['email' => 'Email atau password salah.']);
        }

        // Percobaan ke-6 ditolak meskipun password benar
        $this->post('/login', ['email' => 'petugas@example.com', 'password' => 'rahasia123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString(
            'Terlalu banyak percobaan login',
            session('errors')->first('email')
        );
    }

    public function test_logout_dicatat_di_audit_log(): void
    {
        $this->actingAs($this->user())->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['modul' => 'auth', 'aksi' => 'logout']);
    }

    public function test_ganti_password_berhasil(): void
    {
        $user = $this->user();

        $this->actingAs($user)->put('/akun/password', [
            'password_lama'         => 'rahasia123',
            'password'              => 'passwordBaru99',
            'password_confirmation' => 'passwordBaru99',
        ])->assertRedirect(route('password.edit'))->assertSessionHas('success');

        $this->assertTrue(Hash::check('passwordBaru99', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['modul' => 'auth', 'aksi' => 'ganti_password']);
    }

    public function test_ganti_password_ditolak_jika_password_lama_salah(): void
    {
        $user = $this->user();

        $this->actingAs($user)->put('/akun/password', [
            'password_lama'         => 'bukanIni1',
            'password'              => 'passwordBaru99',
            'password_confirmation' => 'passwordBaru99',
        ])->assertSessionHasErrors(['password_lama' => 'Password lama salah.']);

        $this->assertTrue(Hash::check('rahasia123', $user->fresh()->password));
    }

    public function test_ganti_password_ditolak_jika_terlalu_lemah(): void
    {
        $this->actingAs($this->user())->put('/akun/password', [
            'password_lama'         => 'rahasia123',
            'password'              => 'pendek',
            'password_confirmation' => 'pendek',
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, AuditLog::where('aksi', 'ganti_password')->count());
    }
}
