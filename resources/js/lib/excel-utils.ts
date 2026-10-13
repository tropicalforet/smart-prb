import * as XLSX from 'xlsx';
import * as XLSX from 'xlsx';
import { PasienPRB } from '@/types';

// ─────────────────────────────────────────────────────────────────────────────
// KONSTANTA DETEKSI SHEET DATA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Kata kunci yang harus ada pada baris header agar sebuah sheet dianggap
 * sebagai sheet data (bukan sheet petunjuk/dokumentasi/lainnya).
 *
 * Pencocokan bersifat case-insensitive dan substring match.
 * Minimal SATU keyword harus cocok di baris yang sama.
 *
 * PENTING: Keyword ini hanya dipakai untuk mendeteksi BARIS HEADER,
 * bukan untuk memfilter baris data. Baris data dinilai berdasarkan
 * field wajib saja (nama_pasien, no_kartu_bpjs, dsb.).
 */
const DATA_SHEET_HEADER_KEYWORDS: string[] = [
    'nama pasien',
    'no. kartu bpjs',
    'no kartu bpjs',
    'no_kartu_bpjs',
    'nama_pasien',
    'no. faktur',
    'no faktur',
    'no_faktur',
    'tanggal mulai prb',
    'tanggal_mulai_prb',
    'fktp',
    'diagnosis',
];

/**
 * Periksa apakah suatu baris benar-benar kosong (semua nilai kosong/null).
 * Internal key yang ditambah parser (prefix "_") tidak ikut dihitung.
 */
function isEmptyRow(row: Record<string, any>): boolean {
    return Object.entries(row).every(([key, val]) => {
        if (key.startsWith('_')) return true; // abaikan meta-field
        if (val === null || val === undefined) return true;
        return String(val).trim() === '';
    });
}

/**
 * Deteksi baris header di worksheet.
 *
 * Cara kerja:
 * 1. Baca worksheet sebagai array-of-arrays (raw, tanpa interpretasi header).
 * 2. Cari baris pertama (dari atas, maks 10 baris) yang mengandung
 *    minimal DUA kata kunci kolom data PRB yang berbeda dalam baris yang sama.
 *    Dua keyword dipersyaratkan agar sheet "Petunjuk" yang kebetulan menyebut
 *    satu nama kolom dalam teks instruksi tidak salah dideteksi sebagai header.
 * 3. Kembalikan indeks baris tersebut (0-based).
 * 4. Kembalikan -1 jika tidak ditemukan → sheet dilewati.
 *
 * Tidak bergantung pada nama sheet atau posisi sheet dalam workbook.
 */
function detectHeaderRow(ws: XLSX.WorkSheet): number {
    const aoa: any[][] = XLSX.utils.sheet_to_json(ws, {
        header: 1,
        defval: '',
        blankrows: true, // sertakan baris kosong agar indeks tidak geser
    });

    for (let i = 0; i < Math.min(aoa.length, 20); i++) {
        const rowStr = aoa[i]
            .map((c: any) => String(c ?? '').toLowerCase().trim())
            .join('|');

        // Abaikan baris yang sepenuhnya kosong
        if (!rowStr.replace(/\|/g, '').trim()) continue;

        // Hitung keyword yang cocok di baris ini (minimal 2 berbeda)
        const matchCount = DATA_SHEET_HEADER_KEYWORDS.filter((kw) =>
            rowStr.includes(kw.toLowerCase())
        ).length;

        if (matchCount >= 2) return i;
    }

    return -1;
}

export interface ImportRow {
    no_faktur?: string;
    kode_pasien?: string;
    nama_pasien: string;
    no_kartu_bpjs: string;
    no_whatsapp?: string;
    fktp_asal: string;
    diagnosis_prb: string[];
    tanggal_mulai_prb: string;
    tanggal_berobat?: string;
    tanggal_pengambilan?: string;
}

export interface ValidationResult {
    row: number;
    sheet: string;          // nama worksheet sumber
    data: ImportRow;
    valid: boolean;
    errors: string[];
}

/**
 * Metadata hasil parsing — dilampirkan ke baris pertama melalui
 * field `_parse_meta` agar ImportExcelModal dapat menampilkan info
 * sheet yang diproses vs dilewati tanpa perlu mengubah interface lain.
 */
export interface ParseMeta {
    totalSheets: number;
    processedSheets: string[];
    skippedSheets: string[];
    totalDataRows: number;
}

/**
 * =========================================================
 * PARSE FILE EXCEL — MULTI-SHEET, STRUCTURE-BASED DETECTION
 * =========================================================
 *
 * Prinsip utama (berlaku global, bukan hanya untuk file tertentu):
 *
 * 1. SEMUA sheet dalam workbook diperiksa — tidak ada asumsi nama/posisi.
 * 2. Sheet diidentifikasi sebagai sheet DATA jika memiliki baris header
 *    yang mengandung minimal satu kata kunci kolom PRB (detectHeaderRow).
 * 3. Sheet tanpa header data (Petunjuk, kosong, chart, dsb.) → DILEWATI.
 * 4. Baris kosong total → DILEWATI.
 * 5. Baris dengan isi → DITERUSKAN ke validasi, TERMASUK baris contoh
 *    yang memenuhi field wajib. Filter "baris contoh" tidak dilakukan
 *    di layer parsing — sistem tidak membuang data berdasarkan konten
 *    bebas. Validasi field wajib di validateImportData() yang menentukan
 *    apakah sebuah baris Valid atau Invalid.
 *
 * Setiap baris diberi field `_sheet_name` (sumber sheet) dan
 * baris pertama juga diberi `_parse_meta` untuk info UI.
 */
export function parseExcelFile(
    file: File
): Promise<Record<string, any>[]> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onload = (e) => {
            try {
                const buffer = new Uint8Array(
                    e.target?.result as ArrayBuffer
                );

                const workbook = XLSX.read(buffer, {
                    type: 'array',
                    cellDates: true,
                });

                const allRows: Record<string, any>[]  = [];
                const processedSheets: string[]        = [];
                const skippedSheets: string[]          = [];

                for (const sheetName of workbook.SheetNames) {
                    const ws = workbook.Sheets[sheetName];

                    // ── Langkah 1: Deteksi baris header ─────────────────────
                    // Tidak bergantung pada nama sheet atau urutannya.
                    const headerRowIndex = detectHeaderRow(ws);

                    if (headerRowIndex === -1) {
                        // Sheet tidak memiliki header kolom PRB yang dikenali
                        // (mis. sheet Petunjuk, sheet kosong, sheet dokumentasi).
                        skippedSheets.push(sheetName);
                        continue;
                    }

                    // ── Langkah 2: Parse baris data ──────────────────────────
                    // Gunakan sheet_to_json dengan header dari baris yang terdeteksi.
                    // TIDAK memodifikasi ws['!ref'] (mencegah efek samping).
                    // Sebagai gantinya, baca semua baris lalu slice mulai headerRowIndex.
                    const aoa: any[][] = XLSX.utils.sheet_to_json(ws, {
                        header: 1,
                        defval: '',
                        blankrows: true,
                    });

                    // Baris header ada di indeks headerRowIndex,
                    // baris data mulai dari headerRowIndex + 1
                    const headerRow: any[]   = aoa[headerRowIndex] ?? [];
                    const dataAoa: any[][]   = aoa.slice(headerRowIndex + 1);

                    // Konversi array-of-arrays → array-of-objects
                    // menggunakan headerRow sebagai key
                    let dataRowCount = 0;
                    for (const rawRow of dataAoa) {
                        const obj: Record<string, any> = {};
                        for (let c = 0; c < headerRow.length; c++) {
                            const key = String(headerRow[c] ?? '').trim() || `col_${c}`;
                            obj[key]  = rawRow[c] ?? '';
                        }

                        // ── Filter baris kosong total ───────────────────────
                        // Hanya lewati jika SEMUA nilai (kecuali meta) kosong.
                        if (isEmptyRow(obj)) continue;

                        // ── Baris dengan isi → diteruskan ──────────────────
                        // Tidak ada filter konten bebas di sini.
                        // Validasi field wajib dilakukan oleh validateImportData().
                        obj['_sheet_name'] = sheetName;
                        allRows.push(obj);
                        dataRowCount++;
                    }

                    if (dataRowCount > 0) {
                        processedSheets.push(sheetName);
                    } else {
                        // Header ada tapi tidak ada baris data → lewati
                        skippedSheets.push(sheetName);
                    }
                }

                if (allRows.length === 0) {
                    const detail = skippedSheets.length > 0
                        ? `Sheet yang diperiksa: ${[...processedSheets, ...skippedSheets].join(', ')}. ` +
                          `Sheet dilewati (tanpa header data PRB): ${skippedSheets.join(', ')}.`
                        : 'Workbook tidak memiliki sheet dengan header data PRB.';

                    reject(new Error(
                        `Tidak ditemukan baris data yang dapat diimpor.\n${detail}\n` +
                        `Pastikan baris data sudah diisi di bawah baris header kolom.`
                    ));
                    return;
                }

                // Lampirkan meta ke baris pertama
                const meta: ParseMeta = {
                    totalSheets: workbook.SheetNames.length,
                    processedSheets,
                    skippedSheets,
                    totalDataRows: allRows.length,
                };
                allRows[0]['_parse_meta'] = meta;

                resolve(allRows);

            } catch (err: any) {
                // Teruskan error dari reject() di atas tanpa wrap ulang
                if (err instanceof Error) {
                    reject(err);
                } else {
                    reject(new Error(
                        'Gagal membaca file Excel. Pastikan format file benar (.xlsx atau .xls).'
                    ));
                }
            }
        };

        reader.onerror = () =>
            reject(new Error('Gagal membaca file.'));

        reader.readAsArrayBuffer(file);
    });
}

/**
 * =========================================================
 * VALIDASI DATA IMPORT
 * =========================================================
 *
 * Menerima baris mentah hasil parseExcelFile() dan
 * mengembalikan ValidationResult[] untuk setiap baris.
 *
 * Lapisan filter kedua (defense-in-depth):
 * - Baris kosong total → dilewati (tidak masuk hasil)
 * - Baris contoh/instruksi → dilewati
 *
 * Mapping kolom bersifat multi-alias: header dalam berbagai
 * variasi nama (dengan spasi, snake_case, kapitalisasi) tetap
 * dikenali selama mengandung kata kunci yang sama.
 */
export function validateImportData(
    rows: Record<string, any>[]
): ValidationResult[] {
    const results: ValidationResult[] = [];

    rows.forEach((row, idx) => {
        // ── Lapisan 2: filter baris kosong (defense-in-depth) ─────────────
        // Baris kosong sudah difilter di parseExcelFile, ini hanya jaga-jaga.
        if (isEmptyRow(row)) return;

        const errors: string[] = [];

        // ── Mapping kolom (toleran terhadap variasi nama header) ───────

        const tanggalMulai =
            formatDateValue(
                row['Tanggal Mulai PRB'] ??
                    row['tanggal_mulai_prb']
            );

        const tanggalPengambilan =
            formatDateValue(
                row['Tanggal Pengambilan'] ??
                    row['tanggal_pengambilan'] ??
                    row['Pengambilan Terakhir'] ??
                    row['tanggal_pengambilan_terakhir']
            );

        const noFaktur =
            String(
                row['No. Faktur / No. Transaksi Apotek'] ??
                    row['No. Faktur'] ??
                    row['No. Transaksi'] ??
                    row['No. Faktur / No. Transaksi'] ??
                    row['no_faktur'] ??
                    row['no_transaksi'] ??
                    ''
            ).trim() || undefined;

        const tanggalBerobat =
            formatDateValue(
                row['Tanggal Berobat'] ??
                    row['tanggal_berobat']
            ) || undefined;

        // No. BPJS: hapus spasi dan tanda baca yang tidak perlu,
        // pertahankan leading zero
        const rawBpjs = String(
            row['No. Kartu BPJS'] ??
                row['No Kartu BPJS'] ??
                row['no_kartu_bpjs'] ??
                ''
        ).trim().replace(/[\s\-\.]/g, '');

        // Diagnosis: terima array (dari backend round-trip) atau string
        let rawDiagnosis = row['Diagnosis PRB'] ?? row['diagnosis_prb'] ?? '';
        let diagnosisList: string[];
        if (Array.isArray(rawDiagnosis)) {
            diagnosisList = rawDiagnosis.map(String).map(d => d.trim()).filter(Boolean);
        } else {
            diagnosisList = String(rawDiagnosis).split(',').map(d => d.trim()).filter(Boolean);
        }

        const mapped: ImportRow = {
            no_faktur: noFaktur,

            kode_pasien:
                String(
                    row['Kode Pasien'] ??
                        row['kode_pasien'] ??
                        ''
                ).trim() || undefined,

            nama_pasien:
                String(
                    row['Nama Pasien'] ??
                        row['nama_pasien'] ??
                        ''
                ).trim(),

            no_kartu_bpjs: rawBpjs,

            no_whatsapp:
                String(
                    row['No. WhatsApp'] ??
                        row['No WhatsApp'] ??
                        row['no_whatsapp'] ??
                        ''
                ).trim() || undefined,

            fktp_asal:
                String(
                    row['FKTP Asal'] ??
                        row['fktp_asal'] ??
                        ''
                ).trim(),

            diagnosis_prb: diagnosisList,

            tanggal_mulai_prb: tanggalMulai,

            tanggal_berobat: tanggalBerobat,

            tanggal_pengambilan: tanggalPengambilan || undefined,
        };

        // ── Field wajib ────────────────────────────────────────────────

        if (!mapped.nama_pasien) {
            errors.push('Nama Pasien wajib diisi');
        }

        if (!mapped.no_kartu_bpjs) {
            errors.push('No. Kartu BPJS wajib diisi');
        }

        if (!mapped.fktp_asal) {
            errors.push('FKTP Asal wajib diisi');
        }

        if (!mapped.diagnosis_prb || mapped.diagnosis_prb.length === 0) {
            errors.push('Diagnosis PRB wajib diisi');
        }

        if (!mapped.tanggal_mulai_prb) {
            errors.push('Tanggal Mulai PRB wajib diisi');
        }

        // ── Validasi tanggal ───────────────────────────────────────────

        if (mapped.tanggal_mulai_prb && !isValidDateString(mapped.tanggal_mulai_prb)) {
            errors.push('Tanggal Mulai PRB tidak valid. Gunakan format DD/MM/YYYY (contoh: 01/08/2026).');
        }

        if (mapped.tanggal_pengambilan && !isValidDateString(mapped.tanggal_pengambilan)) {
            errors.push('Tanggal Pengambilan tidak valid. Gunakan format DD/MM/YYYY (contoh: 04/10/2026).');
        }

        if (mapped.tanggal_berobat && !isValidDateString(mapped.tanggal_berobat)) {
            errors.push('Tanggal Berobat tidak valid. Gunakan format DD/MM/YYYY (contoh: 01/10/2026).');
        }

        // Tanggal Pengambilan tidak boleh lebih awal dari Tanggal Mulai PRB
        if (
            mapped.tanggal_mulai_prb &&
            mapped.tanggal_pengambilan &&
            isValidDateString(mapped.tanggal_mulai_prb) &&
            isValidDateString(mapped.tanggal_pengambilan)
        ) {
            if (new Date(mapped.tanggal_pengambilan) < new Date(mapped.tanggal_mulai_prb)) {
                errors.push('Tanggal Pengambilan tidak boleh lebih awal dari Tanggal Mulai PRB.');
            }
        }

        results.push({
            row: idx + 1,
            sheet: String(row['_sheet_name'] ?? 'Sheet1'),
            data: mapped,
            valid: errors.length === 0,
            errors,
        });
    });

    return results;
}

/**
 * =========================================================
 * FORMAT TANGGAL
 * =========================================================
 *
 * Output selalu:
 *
 * YYYY-MM-DD
 *
 * Contoh:
 *
 * 15-01-2024
 * ↓
 * 2024-01-15
 *
 * 15/01/2024
 * ↓
 * 2024-01-15
 */
function formatDateValue(
    val: any
): string {
    if (
        val === null ||
        val === undefined ||
        val === ''
    ) {
        return '';
    }

    /*
     * Jika XLSX sudah membaca sebagai Date.
     */
    if (val instanceof Date) {
        if (isNaN(val.getTime())) {
            return '';
        }

        const year =
            val.getFullYear();

        const month =
            String(
                val.getMonth() + 1
            ).padStart(2, '0');

        const day =
            String(
                val.getDate()
            ).padStart(2, '0');

        return `${year}-${month}-${day}`;
    }

    const str =
        String(val).trim();

    if (!str) {
        return '';
    }

    /*
     * Format YYYY-MM-DD
     */
    if (
        /^\d{4}-\d{2}-\d{2}$/.test(
            str
        )
    ) {
        return str;
    }

    /*
     * Format DD-MM-YYYY
     */
    const ddmmyyyy =
        str.match(
            /^(\d{1,2})-(\d{1,2})-(\d{4})$/
        );

    if (ddmmyyyy) {
        const day =
            Number(ddmmyyyy[1]);

        const month =
            Number(ddmmyyyy[2]);

        const year =
            Number(ddmmyyyy[3]);

        return buildDateString(
            year,
            month,
            day
        );
    }

    /*
     * Format DD/MM/YYYY
     */
    const ddmmyyyySlash =
        str.match(
            /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/
        );

    if (ddmmyyyySlash) {
        const day =
            Number(
                ddmmyyyySlash[1]
            );

        const month =
            Number(
                ddmmyyyySlash[2]
            );

        const year =
            Number(
                ddmmyyyySlash[3]
            );

        return buildDateString(
            year,
            month,
            day
        );
    }

    /*
     * Excel serial number.
     */
    if (
        /^\d+(\.\d+)?$/.test(str)
    ) {
        const serial =
            Number(str);

        if (
            serial > 20000 &&
            serial < 100000
        ) {
            const excelDate =
                XLSX.SSF.parse_date_code(
                    serial
                );

            if (excelDate) {
                return buildDateString(
                    excelDate.y,
                    excelDate.m,
                    excelDate.d
                );
            }
        }
    }

    return '';
}

/**
 * =========================================================
 * BENTUKKAN YYYY-MM-DD
 * =========================================================
 */
function buildDateString(
    year: number,
    month: number,
    day: number
): string {
    const date =
        new Date(
            year,
            month - 1,
            day
        );

    /*
     * Cegah tanggal seperti:
     * 31-02-2024
     */
    if (
        date.getFullYear() !== year ||
        date.getMonth() !== month - 1 ||
        date.getDate() !== day
    ) {
        return '';
    }

    return [
        year,
        String(month).padStart(
            2,
            '0'
        ),
        String(day).padStart(
            2,
            '0'
        ),
    ].join('-');
}

/**
 * =========================================================
 * VALIDASI STRING TANGGAL
 * =========================================================
 */
function isValidDateString(
    value: string
): boolean {
    if (
        !/^\d{4}-\d{2}-\d{2}$/.test(
            value
        )
    ) {
        return false;
    }

    const [year, month, day] =
        value
            .split('-')
            .map(Number);

    return (
        buildDateString(
            year,
            month,
            day
        ) === value
    );
}

/**
 * =========================================================
 * EXPORT EXCEL
 * =========================================================
 */
export function exportToExcel(
    data: Record<string, any>[],
    filename: string =
        'laporan-smart-prb.xlsx'
): void {
    const worksheet =
        XLSX.utils.json_to_sheet(
            data
        );

    const colWidths =
        Object.keys(
            data[0] || {}
        ).map((key) => ({
            wch:
                Math.max(
                    key.length,
                    ...data.map((row) =>
                        String(
                            row[key] ?? ''
                        ).length
                    )
                ) + 2,
        }));

    worksheet['!cols'] =
        colWidths;

    const workbook =
        XLSX.utils.book_new();

    XLSX.utils.book_append_sheet(
        workbook,
        worksheet,
        'Laporan PRB'
    );

    XLSX.writeFile(
        workbook,
        filename
    );
}

/**
 * =========================================================
 * DOWNLOAD TEMPLATE IMPORT
 * =========================================================
 *
 * 10 kolom sesuai struktur backend import.
 * - No. BPJS dan No. WhatsApp diformat sebagai TEXT agar leading zero terjaga.
 * - Kolom tanggal diberi format DD/MM/YYYY.
 * - Sheet "Petunjuk" disertakan untuk panduan petugas.
 * - Baris contoh di-style abu-abu agar mudah dibedakan dari data nyata.
 */
export function downloadImportTemplate(): void {
    const workbook = XLSX.utils.book_new();

    // ── Sheet 1: Template Import ──────────────────────────────────────────

    // Header dan baris contoh
    const headers = [
        'No. Faktur / No. Transaksi Apotek',
        'Kode Pasien',
        'Nama Pasien',
        'No. Kartu BPJS',
        'No. WhatsApp',
        'FKTP Asal',
        'Diagnosis PRB',
        'Tanggal Mulai PRB',
        'Tanggal Berobat',
        'Tanggal Pengambilan',
    ];

    // Baris contoh — petugas diinstruksikan menghapus/mengganti baris ini.
    // Tanggal menggunakan tahun saat ini agar selalu relevan.
    const tahunIni  = new Date().getFullYear();
    const contohRow = [
        'CONTOH-INV-001',
        'PRB-001',
        '** CONTOH — HAPUS BARIS INI **',
        '0001234567890',
        '081234567890',
        'Puskesmas Kecamatan X',
        'Hipertensi',
        `01/01/${tahunIni}`,
        `01/03/${tahunIni}`,
        `03/03/${tahunIni}`,
    ];

    // Buat worksheet dari array-of-arrays agar bisa mengontrol tipe cell
    const wsData: any[][] = [headers, contohRow];
    const worksheet = XLSX.utils.aoa_to_sheet(wsData);

    // ── Cell formatting ───────────────────────────────────────────────────
    // Lebar kolom
    worksheet['!cols'] = [
        { wch: 28 }, // A — No. Faktur
        { wch: 14 }, // B — Kode Pasien
        { wch: 32 }, // C — Nama Pasien
        { wch: 20 }, // D — No. BPJS (TEXT)
        { wch: 18 }, // E — No. WA   (TEXT)
        { wch: 32 }, // F — FKTP
        { wch: 26 }, // G — Diagnosis
        { wch: 20 }, // H — Tgl Mulai PRB
        { wch: 18 }, // I — Tgl Berobat
        { wch: 20 }, // J — Tgl Pengambilan
    ];

    // Paksa kolom No. BPJS (D) dan No. WA (E) sebagai TEXT
    // agar leading zero tidak hilang ketika Excel membaca file
    // Kolom D = index 3, Kolom E = index 4
    // Format '@' = Text di Excel
    const textColumns = [3, 4]; // D, E
    for (let r = 1; r < 200; r++) { // baris data (baris 2–200, index 1–199)
        textColumns.forEach(c => {
            const cellRef = XLSX.utils.encode_cell({ r, c });
            if (!worksheet[cellRef]) {
                // Buat cell kosong bertipe text agar Excel tidak auto-convert
                worksheet[cellRef] = { t: 's', v: '', z: '@' };
            } else {
                worksheet[cellRef].z = '@';
                worksheet[cellRef].t = 's';
                // Pastikan value tetap string
                worksheet[cellRef].v = String(worksheet[cellRef].v ?? '');
            }
        });
    }

    // Format kolom tanggal (G, H, I = index 7, 8, 9) sebagai text DD/MM/YYYY
    // Catatan: kita simpan sebagai string karena Excel date serial bisa
    // berubah-ubah tergantung regional setting. Frontend parser sudah
    // menangani format DD/MM/YYYY dan DD-MM-YYYY.
    const dateColumns = [7, 8, 9]; // H = Tgl Mulai PRB, I = Tgl Berobat, J = Tgl Pengambilan
    for (let r = 1; r < 200; r++) {
        dateColumns.forEach(c => {
            const cellRef = XLSX.utils.encode_cell({ r, c });
            if (!worksheet[cellRef]) {
                worksheet[cellRef] = { t: 's', v: '', z: '@' };
            } else {
                worksheet[cellRef].z = '@';
                worksheet[cellRef].t = 's';
                worksheet[cellRef].v = String(worksheet[cellRef].v ?? '');
            }
        });
    }

    // Extend sheet range agar 200 baris data kosong tersedia
    const range = XLSX.utils.decode_range(worksheet['!ref'] ?? 'A1');
    range.e.r = Math.max(range.e.r, 200);
    worksheet['!ref'] = XLSX.utils.encode_range(range);

    XLSX.utils.book_append_sheet(workbook, worksheet, 'Template Import');

    // ── Sheet 2: Petunjuk Pengisian ───────────────────────────────────────
    const petunjukData: string[][] = [
        ['PETUNJUK PENGISIAN TEMPLATE IMPORT PASIEN PRB'],
        [''],
        ['1. Isi data pasien mulai dari BARIS 3 (baris 2 adalah contoh — hapus atau ganti).'],
        ['2. JANGAN mengubah nama kolom pada baris 1 (header).'],
        [''],
        ['KOLOM & ATURAN PENGISIAN:'],
        [''],
        ['No. Faktur / No. Transaksi Apotek',
         'Identifier transaksi dari sumber data apotek.',
         'Boleh kosong jika tidak tersedia.',
         'Format bebas — isi sesuai data yang diberikan apotek.',
        ],
        ['Kode Pasien', 'Kode pasien di sistem PRB. Boleh kosong untuk pasien baru.'],
        ['Nama Pasien', 'Nama lengkap pasien. WAJIB DIISI.'],
        ['No. Kartu BPJS',
         'Nomor BPJS 13 digit. WAJIB DIISI.',
         'Ketik sebagai teks. Pastikan angka 0 di depan tidak hilang.',
         'Contoh: 0001234567890',
        ],
        ['No. WhatsApp',
         'Nomor WhatsApp pasien. Boleh kosong.',
         'Ketik sebagai teks agar angka 0 di depan tidak hilang.',
         'Contoh: 081234567890',
        ],
        ['FKTP Asal', 'Nama Fasilitas Kesehatan Tingkat Pertama. WAJIB DIISI.'],
        ['Diagnosis PRB',
         'Diagnosis pasien. WAJIB DIISI. Boleh bebas sesuai data apotek.',
         'Contoh: Hipertensi, Diabetes Melitus, Jantung Koroner',
         'Untuk lebih dari satu diagnosis, pisahkan dengan koma.',
        ],
        ['Tanggal Mulai PRB',
         'Tanggal pasien mulai program PRB. WAJIB DIISI.',
         'Format: DD/MM/YYYY',
         'Contoh: 01/08/2026',
        ],
        ['Tanggal Berobat',
         'Tanggal kunjungan berobat. Boleh kosong.',
         'Format: DD/MM/YYYY',
        ],
        ['Tanggal Pengambilan',
         'Tanggal pasien mengambil obat di apotek. Boleh kosong jika belum diambil.',
         'Format: DD/MM/YYYY',
         'Catatan: Jika kosong dan Tanggal Berobat diisi, sistem menggunakan Tanggal Berobat sebagai fallback.',
        ],
        [''],
        ['CATATAN PENTING:'],
        ['- Simpan file sebagai .xlsx sebelum upload.'],
        ['- Satu baris = satu transaksi pengambilan obat.'],
        ['- Pasien yang sama boleh muncul di beberapa baris (bulan berbeda).'],
        ['- Sistem mengenali pasien berdasarkan No. Kartu BPJS, bukan nama.'],
        ['- Import bersifat kumulatif: data bulan sebelumnya tidak akan terhapus.'],
        ['- Jika No. Faktur sama dengan yang sudah ada, data akan di-UPDATE (bukan duplikat).'],
    ];

    const wsPetunjuk = XLSX.utils.aoa_to_sheet(petunjukData);
    wsPetunjuk['!cols'] = [
        { wch: 40 },
        { wch: 60 },
        { wch: 50 },
        { wch: 50 },
    ];
    XLSX.utils.book_append_sheet(workbook, wsPetunjuk, 'Petunjuk');

    XLSX.writeFile(workbook, 'template-import-pasien-prb.xlsx');
}
