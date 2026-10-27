<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Instalasi baru (database kosong) harus langsung punya akun admin yang
 * bisa login — database tidak ikut di Git, jadi akun dibuat oleh seeder.
 */
class SetupAdminTest extends TestCase
{
    use RefreshDatabase;

    private array $asli = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->asli = [$_SERVER['ADMIN_PASSWORD'] ?? null, $_ENV['ADMIN_PASSWORD'] ?? null];
    }

    protected function tearDown(): void
    {
        [$server, $env] = $this->asli;
        if ($server === null) unset($_SERVER['ADMIN_PASSWORD']); else $_SERVER['ADMIN_PASSWORD'] = $server;
        if ($env === null) unset($_ENV['ADMIN_PASSWORD']); else $_ENV['ADMIN_PASSWORD'] = $env;
        parent::tearDown();
    }

    /** env() membaca $_SERVER/$_ENV lebih dulu (nilai dari .env lokal ada di sana). */
    private function aturPasswordAdmin(string $password): void
    {
        $_SERVER['ADMIN_PASSWORD'] = $password;
        $_ENV['ADMIN_PASSWORD']    = $password;
    }

    private function loginBerhasil(string $password): void
    {
        $this->post('/login', ['email' => 'admin@prbkesehatan.id', 'password' => $password])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_db_seed_membuat_akun_admin_yang_bisa_login(): void
    {
        $this->aturPasswordAdmin('RahasiaAwal123');

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::where('email', 'admin@prbkesehatan.id')->count());
        $this->loginBerhasil('RahasiaAwal123');
    }

    public function test_seeder_admin_tidak_mengubah_password_akun_yang_sudah_ada(): void
    {
        $this->aturPasswordAdmin('RahasiaAwal123');
        $this->seed(AdminUserSeeder::class);

        $this->aturPasswordAdmin('PasswordLain456');
        $this->seed(AdminUserSeeder::class);

        $this->loginBerhasil('RahasiaAwal123');
    }
}
