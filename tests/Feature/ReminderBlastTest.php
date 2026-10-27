<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReminderBlastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Modul Reminder WhatsApp dinonaktifkan karena No. HP pasien tidak boleh
     * disimpan (ketentuan etik penelitian).
     */
    public function test_modul_reminder_whatsapp_dinonaktifkan(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/reminder')->assertNotFound();
        $this->postJson('/reminder/blast', ['pasien_ids' => ['x']])->assertNotFound();
    }
}
