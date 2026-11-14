<?php

namespace Tests\Feature;

use Tests\TestCase;

class PratinjauLinkTest extends TestCase
{
    /**
     * Pratinjau link (WhatsApp dll.) dibaca dari HTML awal tanpa JavaScript:
     * judul dan deskripsi harus "Dashboard PRB", bukan nama lama.
     */
    public function test_html_awal_memakai_nama_dashboard_prb(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('<title inertia>Dashboard PRB</title>', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Dashboard PRB">', $html);
        $this->assertStringNotContainsString('SMART PRB', $html);
    }
}
