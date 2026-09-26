const XLSX = require('xlsx');

const data = [
    {
        'No. Transaksi Apotek': 'INV-08-001',
        'No. Kartu BPJS': '0001112223334',
        'Nama Pasien': 'Maman',
        'No. WhatsApp': '081200000001',
        'FKTP Asal': 'Puskesmas A',
        'Diagnosis PRB': 'Diabetes Melitus',
        'Tanggal Mulai PRB': '01/01/2026',
        'Tanggal Berobat': '10/08/2026',
        'Tanggal Pengambilan': '10/08/2026'
    },
    {
        'No. Transaksi Apotek': 'INV-08-002',
        'No. Kartu BPJS': '0001112223335',
        'Nama Pasien': 'Nia',
        'No. WhatsApp': '081200000002',
        'FKTP Asal': 'Puskesmas A',
        'Diagnosis PRB': 'Hipertensi',
        'Tanggal Mulai PRB': '01/01/2026',
        'Tanggal Berobat': '11/08/2026',
        'Tanggal Pengambilan': '11/08/2026'
    },
    {
        'No. Transaksi Apotek': 'INV-08-003',
        'No. Kartu BPJS': '0001112223336',
        'Nama Pasien': 'Oki',
        'No. WhatsApp': '081200000003',
        'FKTP Asal': 'Klinik B',
        'Diagnosis PRB': 'Asma',
        'Tanggal Mulai PRB': '01/01/2026',
        'Tanggal Berobat': '12/08/2026',
        'Tanggal Pengambilan': '12/08/2026'
    },
    {
        'No. Transaksi Apotek': 'INV-08-004',
        'No. Kartu BPJS': '0001112223337',
        'Nama Pasien': 'Fajar',
        'No. WhatsApp': '081200000004',
        'FKTP Asal': 'Klinik B',
        'Diagnosis PRB': 'Penyakit Jantung Kronis',
        'Tanggal Mulai PRB': '01/01/2026',
        'Tanggal Berobat': '15/08/2026',
        'Tanggal Pengambilan': '15/08/2026'
    },
    {
        'No. Transaksi Apotek': 'INV-08-005',
        'No. Kartu BPJS': '0001112223338',
        'Nama Pasien': 'Gina',
        'No. WhatsApp': '081200000005',
        'FKTP Asal': 'Puskesmas C',
        'Diagnosis PRB': 'Stroke',
        'Tanggal Mulai PRB': '01/01/2026',
        'Tanggal Berobat': '20/08/2026',
        'Tanggal Pengambilan': '20/08/2026'
    },

    // --- SEPTEMBER 2026 ---
    {
        'No. Transaksi Apotek': 'INV-09-001',
        'No. Kartu BPJS': '0001112223334',
        'Nama Pasien': 'Maman',
        'No. WhatsApp': '081200000001',
        'FKTP Asal': 'Puskesmas A',
        'Diagnosis PRB': 'Diabetes Melitus',
        'Tanggal Mulai PRB': '01/01/2026',
        'Tanggal Berobat': '09/09/2026',
        'Tanggal Pengambilan': '09/09/2026'
    },
    {
        'No. Transaksi Apotek': 'INV-09-002',
        'No. Kartu BPJS': '0001112223335',
        'Nama Pasien': 'Nia',
        'No. WhatsApp': '081200000002',
        'FKTP Asal': 'Puskesmas A',
        'Diagnosis PRB': 'Hipertensi',
        'Tanggal Mulai PRB': '01/01/2026',
        'Tanggal Berobat': '10/09/2026',
        'Tanggal Pengambilan': '10/09/2026'
    },
    {
        'No. Transaksi Apotek': 'INV-09-003',
        'No. Kartu BPJS': '0001112223336',
        'Nama Pasien': 'Oki',
        'No. WhatsApp': '081200000003',
        'FKTP Asal': 'Klinik B',
        'Diagnosis PRB': 'Asma',
        'Tanggal Mulai PRB': '01/01/2026',
        'Tanggal Berobat': '11/09/2026',
        'Tanggal Pengambilan': '11/09/2026'
    },
    {
        'No. Transaksi Apotek': 'INV-09-004',
        'No. Kartu BPJS': '0001112223337',
        'Nama Pasien': 'Fajar',
        'No. WhatsApp': '081200000004',
        'FKTP Asal': 'Klinik B',
        'Diagnosis PRB': 'Penyakit Jantung Kronis',
        'Tanggal Mulai PRB': '01/01/2026',
        'Tanggal Berobat': '20/09/2026',
        'Tanggal Pengambilan': '20/09/2026'
    },

    // --- OKTOBER 2026 ---
    {
        'No. Transaksi Apotek': 'INV-10-001',
        'No. Kartu BPJS': '0001112223334',
        'Nama Pasien': 'Maman',
        'No. WhatsApp': '081200000001',
        'FKTP Asal': 'Puskesmas A',
        'Diagnosis PRB': 'Diabetes Melitus',
        'Tanggal Mulai PRB': '01/01/2026',
        'Tanggal Berobat': '08/10/2026',
        'Tanggal Pengambilan': '08/10/2026'
    },
    {
        'No. Transaksi Apotek': 'INV-10-002',
        'No. Kartu BPJS': '0001112223335',
        'Nama Pasien': 'Nia',
        'No. WhatsApp': '081200000002',
        'FKTP Asal': 'Puskesmas A',
        'Diagnosis PRB': 'Hipertensi',
        'Tanggal Mulai PRB': '01/01/2026',
        'Tanggal Berobat': '11/10/2026',
        'Tanggal Pengambilan': '11/10/2026'
    },
    {
        'No. Transaksi Apotek': 'INV-10-003',
        'No. Kartu BPJS': '0001112223336',
        'Nama Pasien': 'Oki',
        'No. WhatsApp': '081200000003',
        'FKTP Asal': 'Klinik B',
        'Diagnosis PRB': 'Asma',
        'Tanggal Mulai PRB': '01/01/2026',
        'Tanggal Berobat': '13/10/2026',
        'Tanggal Pengambilan': '13/10/2026'
    }
];

const ws = XLSX.utils.json_to_sheet(data);
const wb = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(wb, ws, "Data PRB");
XLSX.writeFile(wb, "Data_Test_PRB_2026.xlsx");

console.log("XLSX generated successfully");
