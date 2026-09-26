<?php

$headers = [
    'No. Transaksi Apotek',
    'No. Kartu BPJS',
    'Nama Pasien',
    'No. WhatsApp',
    'FKTP Asal',
    'Diagnosis PRB',
    'Tanggal Berobat',
    'Tanggal Pengambilan'
];

$data = [
    // --- AGUSTUS 2026 ---
    ['INV-08-001', '0001112223334', 'Maman', '081200000001', 'Puskesmas A', 'Diabetes Melitus', '10/08/2026', '10/08/2026'],
    ['INV-08-002', '0001112223335', 'Nia', '081200000002', 'Puskesmas A', 'Hipertensi', '11/08/2026', '11/08/2026'],
    ['INV-08-003', '0001112223336', 'Oki', '081200000003', 'Klinik B', 'Asma', '12/08/2026', '12/08/2026'],
    ['INV-08-004', '0001112223337', 'Fajar', '081200000004', 'Klinik B', 'Jantung', '15/08/2026', '15/08/2026'],
    ['INV-08-005', '0001112223338', 'Gina', '081200000005', 'Puskesmas C', 'Stroke', '20/08/2026', '20/08/2026'],

    // --- SEPTEMBER 2026 ---
    ['INV-09-001', '0001112223334', 'Maman', '081200000001', 'Puskesmas A', 'Diabetes Melitus', '09/09/2026', '09/09/2026'],
    ['INV-09-002', '0001112223335', 'Nia', '081200000002', 'Puskesmas A', 'Hipertensi', '10/09/2026', '10/09/2026'],
    ['INV-09-003', '0001112223336', 'Oki', '081200000003', 'Klinik B', 'Asma', '11/09/2026', '11/09/2026'],
    ['INV-09-004', '0001112223337', 'Fajar', '081200000004', 'Klinik B', 'Jantung', '20/09/2026', '20/09/2026'],

    // --- OKTOBER 2026 ---
    ['INV-10-001', '0001112223334', 'Maman', '081200000001', 'Puskesmas A', 'Diabetes Melitus', '08/10/2026', '08/10/2026'],
    ['INV-10-002', '0001112223335', 'Nia', '081200000002', 'Puskesmas A', 'Hipertensi', '11/10/2026', '11/10/2026'],
    ['INV-10-003', '0001112223336', 'Oki', '081200000003', 'Klinik B', 'Asma', '13/10/2026', '13/10/2026'],
];

$filename = 'Data_Test_PRB_2026.csv';
$fp = fopen($filename, 'w');
fputcsv($fp, $headers);
foreach ($data as $row) {
    fputcsv($fp, $row);
}
fclose($fp);

echo "File CSV berhasil dibuat: " . realpath($filename);
