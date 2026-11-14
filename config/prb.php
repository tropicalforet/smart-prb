<?php

/*
|--------------------------------------------------------------------------
| Pengaturan penelitian PRB
|--------------------------------------------------------------------------
|
| periode_penelitian — bulan data penelitian (YYYY-MM). Membatasi pilihan
|   filter Periode, tampilan histori pengambilan, dan grafik tren. Bulan
|   terakhir juga dipakai untuk PRB Baru pasien tanpa tanggal mulai PRB:
|   pasien yang pertama kali ambil obat di bulan itu dianggap PRB Baru.
|   Kosongkan (PRB_PERIODE_PENELITIAN=) untuk menampilkan semua bulan.
|
*/

return [
    'periode_penelitian' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PRB_PERIODE_PENELITIAN', '2026-03,2026-04,2026-05'))
    ))),
];
