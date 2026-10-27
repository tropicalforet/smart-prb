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
 * field wajib saja (noka, fktp_asal, dsb.).
 */
const DATA_SHEET_HEADER_KEYWORDS: string[] = [
    'no. kartu bpjs',
    'no kartu bpjs',
    'no_kartu_bpjs',
    'noka tersandi',
    'noka / noka tersandi',
    'noka',
    'noka sandi',
    'faskes',
    'diagnosa',
    'tgl mulai',
    'no. faktur',
    'no faktur',
    'no_faktur',
    'tanggal mulai prb',
    'tanggal_mulai_prb',
    'fktp',
    'fktp / faskes asal',
    'faskes asal',
    'diagnosis',
    'tanggal pengambilan obat terakhir',
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
    noka: string;
    fktp_asal: string;
    diagnosis_prb: string[];
    tanggal_mulai_prb?: string;
    tanggal_berobat?: string;
    tanggal_pengambilan?: string;
}

export interface ValidationResult {
    row: number;
    sheet: string;          // nama worksheet sumber
    data: ImportRow;
    pengambilan: string[];  // tanggal ambil (ISO), urut — bisa >1 dari kolom bulan
    valid: boolean;
    errors: string[];
    warnings: string[];     // tidak memblokir impor, hanya untuk dicek petugas
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

                // cellDates: false → sel tanggal dibaca sebagai serial Excel lalu
                // dikonversi tepat oleh formatDateValue (SSF.parse_date_code).
                // Dengan cellDates: true, SheetJS 0.18 membuat objek Date yang
                // meleset beberapa detik sebelum tengah malam di zona WIB
                // (mis. 06/09/2018 23:59:48 untuk 07/09/2018) → tanggal mundur 1 hari.
                const workbook = XLSX.read(buffer, {
                    type: 'array',
                    cellDates: false,
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

// ─────────────────────────────────────────────────────────────────────────
// PENGENALAN HEADER LONGGAR
// ─────────────────────────────────────────────────────────────────────────
// Data apotek memakai nama kolom yang berbeda-beda per sheet
// ("NOKA TERSANDI", "NOKA SANDI", "FASKES", "DIAGNOSA", "TGL MULAI ", ...).
// Header dinormalisasi (huruf kecil, tanpa spasi/tanda baca) lalu dicocokkan
// dengan pola, sehingga variasi penulisan tetap dikenali.

function normKey(key: string): string {
    return key.toLowerCase().replace(/[^a-z0-9]/g, '');
}

/** Nilai pertama yang tidak kosong dari kolom yang header-nya cocok. */
function pickByHeader(row: Record<string, any>, cocok: (nk: string) => boolean): any {
    for (const [key, val] of Object.entries(row)) {
        if (key.startsWith('_')) continue;
        if (val === null || val === undefined || String(val).trim() === '') continue;
        if (cocok(normKey(key))) return val;
    }
    return undefined;
}

const isHeaderNoka      = (nk: string) => nk.startsWith('noka') || nk.includes('kartubpjs');
const isHeaderFktp      = (nk: string) => nk.startsWith('fktp') || nk.startsWith('faskes');
const isHeaderDiagnosis = (nk: string) => nk.startsWith('diagnos');
const isHeaderMulai     = (nk: string) => /^(tanggal|tgl)mulai/.test(nk);

/** Nilai kosong / tanda "tidak ada" ("-") dianggap kosong. */
function kosong(val: any): boolean {
    if (val === null || val === undefined) return true;
    const s = String(val).trim();
    return s === '' || /^[-–—]+$/.test(s);
}

// ─────────────────────────────────────────────────────────────────────────
// KOLOM BULAN (format data apotek: MARET | APRIL | MEI berisi tanggal ambil)
// ─────────────────────────────────────────────────────────────────────────

const BULAN_HEADER: [RegExp, number][] = [
    [/^(januari|january|jan)$/, 1],   [/^(februari|february|feb)$/, 2],     [/^(maret|march|mar)$/, 3],
    [/^(april|apr)$/, 4],             [/^(mei|may)$/, 5],                   [/^(juni|june|jun)$/, 6],
    [/^(juli|july|jul)$/, 7],         [/^(agustus|august|agu|agt|aug)$/, 8], [/^(september|sept|sep)$/, 9],
    [/^(oktober|october|okt|oct)$/, 10], [/^(november|nov)$/, 11],          [/^(desember|december|des|dec)$/, 12],
];

/**
 * Judul kolom bulan → nomor bulan + tahun (jika ditulis di judul).
 *
 *   "MARET", "APR TGL"                    → { bulan: 3/4, tahun: null }
 *   "JANUARI 2027", "Januari-2027", "TGL JAN 2027" → { bulan: 1, tahun: 2027 }
 *   "DES 26", "Des'26"                    → { bulan: 12, tahun: 2026 }
 *   header lain                           → null
 */
export function kolomBulan(header: string): { bulan: number; tahun: number | null } | null {
    const nk = normKey(header).replace(/tanggal|tgl/g, '');
    const m = nk.match(/^([a-z]+)(\d{2}|\d{4})?$/);
    if (!m) return null;

    const pola = BULAN_HEADER.find(([p]) => p.test(m[1]));
    if (!pola) return null;

    const tahun = m[2] ? (m[2].length === 2 ? 2000 + Number(m[2]) : Number(m[2])) : null;
    return { bulan: pola[1], tahun };
}

/** "MARET", "JANUARI 2027" → nomor bulan; header lain → null. */
export function bulanDariHeader(header: string): number | null {
    return kolomBulan(header)?.bulan ?? null;
}

/**
 * Judul yang memuat nama bulan tetapi tidak dikenali kolomBulan()
 * (mis. "Pengambilan Januari", "01/2027", "JAN 2027 (ambil)") — isinya
 * tidak terbaca, jadi petugas perlu diberi tahu, bukan diabaikan diam-diam.
 */
const NAMA_BULAN_PENUH = /januari|january|februari|february|maret|march|april|juni|june|juli|july|agustus|august|september|oktober|october|november|desember|december/;
function miripKolomBulan(header: string): boolean {
    const nk = normKey(header);
    return NAMA_BULAN_PENUH.test(nk) || /^(jan|feb|mar|apr|mei|may|jun|jul|agu|agt|aug|sep|okt|oct|nov|des|dec)\d/.test(nk)
        || /^\d{1,2}\d{4}$/.test(nk);
}

/**
 * Isi sel kolom bulan → daftar tanggal (hari) pengambilan.
 *
 *   20            → [20]
 *   "1, 29"       → [1, 29]     (ambil 2x dalam sebulan)
 *   "2 + 7", "9 &11", "01 dan 31" → [2, 7] / [9, 11] / [1, 31]
 *   8.27          → [8, 27]     (koma terketik sebagai desimal) + peringatan
 *   "-" / kosong  → []          (tidak ambil obat)
 */
export function parseHariAmbil(val: any): { hari: number[]; peringatan: string[] } {
    const peringatan: string[] = [];
    if (kosong(val)) return { hari: [], peringatan };

    let token: string[];

    if (typeof val === 'number' && !Number.isInteger(val)) {
        // Excel menyimpan "8,27" (locale Indonesia) sebagai angka 8.27.
        const [depan, belakangRaw] = String(val).split('.');
        let belakang = Number(belakangRaw);
        // Satu digit di belakang: "21,30" tersimpan sebagai 21.3 → pilih 30
        // bila valid & lebih besar dari tanggal pertama, selain itu 3.
        if (belakangRaw.length === 1 && belakang * 10 <= 31 && belakang * 10 > Number(depan)) {
            belakang = belakang * 10;
        }
        token = [depan, String(belakang)];
        peringatan.push(`Nilai ${val} dibaca sebagai tanggal ${token.join(' dan ')}`);
    } else {
        token = String(val)
            .toLowerCase()
            .replace(/dan|&|\+/g, ',')
            .split(/[,;\s]+/)
            .filter(Boolean);
    }

    const hari: number[] = [];
    for (const t of token) {
        if (!/^\d{1,2}$/.test(t) || Number(t) < 1 || Number(t) > 31) {
            peringatan.push(`"${t}" bukan tanggal yang valid, dilewati`);
            continue;
        }
        hari.push(Number(t));
    }
    return { hari, peringatan };
}

// ─────────────────────────────────────────────────────────────────────────
// NORMALISASI DIAGNOSIS & FKTP
// ─────────────────────────────────────────────────────────────────────────

/** Singkatan umum di data apotek → nama baku (sama dengan pilihan form). */
const ALIAS_DIAGNOSIS: Record<string, string> = {
    HT: 'Hipertensi', HIPERTENSI: 'Hipertensi',
    DM: 'Diabetes Melitus', 'DIABETES MELITUS': 'Diabetes Melitus',
    JANTUNG: 'Penyakit Jantung', PJK: 'Penyakit Jantung', 'PENYAKIT JANTUNG': 'Penyakit Jantung',
    ASMA: 'Asma', PPOK: 'PPOK',
    STROKE: 'Stroke', SRTOKE: 'Stroke',
    EPI: 'Epilepsi', EPILEPSI: 'Epilepsi',
    SKIZO: 'Skizofrenia', SKIZOFREN: 'Skizofrenia', SKIZOFRENIA: 'Skizofrenia', SCZ: 'Skizofrenia',
    SLE: 'SLE',
};

/**
 * "DM - HT", "DM + HT", "DM_HT_ASMA", "ASMA/PPOK", "Hipertensi, DM"
 * → dipecah per diagnosis, singkatan umum dibakukan, duplikat dibuang.
 */
export function pecahDiagnosis(raw: any): string[] {
    const list = Array.isArray(raw) ? raw.map(String) : String(raw ?? '').split(/[,;+/&_-]/);
    const hasil: string[] = [];
    for (const item of list) {
        const t = item.replace(/\s+/g, ' ').trim();
        if (!t) continue;
        const baku = ALIAS_DIAGNOSIS[t.toUpperCase()] ?? t;
        if (!hasil.includes(baku)) hasil.push(baku);
    }
    return hasil;
}

/** "B. SARI" dan "B SARI" → "B SARI" (titik & spasi ganda diseragamkan). */
function normalisasiFktp(raw: any): string {
    return String(raw ?? '').replace(/\./g, ' ').replace(/\s+/g, ' ').trim();
}

/**
 * Tanggal mulai PRB. Selain format yang dikenali formatDateValue(),
 * teks berformat bulan/tanggal (gaya Excel AS, mis. "11/19/2025") yang
 * tidak valid sebagai DD/MM dicoba sebagai MM/DD dan diberi peringatan.
 */
function parseTanggalMulai(raw: any): { tanggal: string; peringatan?: string; gagal?: boolean } {
    if (kosong(raw)) return { tanggal: '' };

    const iso = formatDateValue(raw);
    if (iso) return { tanggal: iso };

    const m = String(raw).trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (m) {
        const tukar = buildDateString(Number(m[3]), Number(m[1]), Number(m[2]));
        if (tukar) {
            return { tanggal: tukar, peringatan: `Tanggal mulai "${raw}" dibaca sebagai bulan/tanggal (${tukar})` };
        }
    }
    return { tanggal: '', gagal: true };
}

/**
 * =========================================================
 * VALIDASI DATA IMPORT
 * =========================================================
 *
 * Menerima baris mentah hasil parseExcelFile() dan
 * mengembalikan ValidationResult[] untuk setiap baris (= satu pasien).
 *
 * Mendukung dua bentuk file:
 *   1. Template sistem — satu kolom "Tanggal Pengambilan Obat Terakhir".
 *   2. Data apotek per bulan — kolom MARET / APRIL / MEI berisi tanggal
 *      (hari) pengambilan. Tahun dari judul kolom ("JANUARI 2027") bila
 *      ada, selain itu dari opsi `tahunBulan`.
 *
 * Mapping kolom bersifat multi-alias: header dalam berbagai
 * variasi nama (dengan spasi, snake_case, kapitalisasi) tetap
 * dikenali selama mengandung kata kunci yang sama.
 *
 * `errors` membuat baris tidak valid; `warnings` hanya informasi yang
 * perlu dicek petugas (baris tetap diimpor).
 */
export function validateImportData(
    rows: Record<string, any>[],
    opsi: { tahunBulan?: number } = {}
): ValidationResult[] {
    const tahunBulan = opsi.tahunBulan ?? new Date().getFullYear();
    const results: ValidationResult[] = [];

    rows.forEach((row, idx) => {
        // ── Lapisan 2: filter baris kosong (defense-in-depth) ─────────────
        // Baris kosong sudah difilter di parseExcelFile, ini hanya jaga-jaga.
        if (isEmptyRow(row)) return;

        const errors: string[]   = [];
        const warnings: string[] = [];

        // ── Mapping kolom (toleran terhadap variasi nama header) ───────

        const mulai = parseTanggalMulai(
            row['Tanggal Mulai PRB'] ?? row['tanggal_mulai_prb'] ?? pickByHeader(row, isHeaderMulai)
        );
        if (mulai.peringatan) warnings.push(mulai.peringatan);

        const rawPengambilan =
            row['Tanggal Pengambilan Obat Terakhir'] ??
            row['Tanggal Pengambilan'] ??
            row['tanggal_pengambilan'] ??
            row['Pengambilan Terakhir'] ??
            row['tanggal_pengambilan_terakhir'];
        const tanggalPengambilan = kosong(rawPengambilan) ? '' : formatDateValue(rawPengambilan);

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

        // NOKA / NOKA tersandi: identitas pasien dari apotek.
        // Header lama "No. Kartu BPJS" tetap diterima sebagai alias NOKA.
        // Trim spasi di awal/akhir saja, jangan hapus spasi dalam NOKA.
        const rawNoka = String(
            row['NOKA / NOKA Tersandi'] ?? row['noka'] ?? pickByHeader(row, isHeaderNoka) ?? ''
        ).trim();

        const diagnosisList = pecahDiagnosis(
            row['Diagnosis PRB'] ?? row['diagnosis_prb'] ?? pickByHeader(row, isHeaderDiagnosis) ?? ''
        );

        const mapped: ImportRow = {
            no_faktur: noFaktur,

            kode_pasien:
                String(
                    row['Kode Pasien'] ??
                        row['kode_pasien'] ??
                        ''
                ).trim() || undefined,

            // Nama Pasien & No. HP sengaja TIDAK dibaca walaupun kolomnya ada
            // di file — sesuai ketentuan etik penelitian.
            noka: rawNoka,

            fktp_asal: normalisasiFktp(
                row['FKTP / Faskes Asal'] ?? row['fktp_asal'] ?? pickByHeader(row, isHeaderFktp)
            ),

            diagnosis_prb: diagnosisList,

            tanggal_mulai_prb: mulai.tanggal || undefined,

            tanggal_berobat: tanggalBerobat,

            tanggal_pengambilan: tanggalPengambilan || undefined,
        };

        // ── Pengambilan dari kolom bulan (data apotek per bulan) ───────
        // Tahun dari judul kolom ("JANUARI 2027") bila ada; selain itu
        // dari opsi tahunBulan. Kolom diproses urut kiri → kanan.
        const pengambilan: string[] = [];
        let kolomTanpaTahunSebelumnya: { header: string; bulan: number } | null = null;
        for (const [header, val] of Object.entries(row)) {
            if (header.startsWith('_')) continue;
            const kolom = kolomBulan(header);
            if (kolom === null) {
                if (!kosong(val) && miripKolomBulan(header)) {
                    warnings.push(
                        `Kolom "${header.trim()}" mirip kolom bulan tetapi tidak dikenali — isinya tidak diimpor. ` +
                        `Gunakan judul seperti "JANUARI" atau "JANUARI 2027".`
                    );
                }
                continue;
            }

            // Bulan mundur tanpa tahun di judul (mis. DESEMBER → JANUARI)
            // berarti file melewati pergantian tahun, padahal semua kolom
            // akan dibaca dengan tahun yang sama.
            if (kolom.tahun === null) {
                if (kolomTanpaTahunSebelumnya && kolom.bulan < kolomTanpaTahunSebelumnya.bulan) {
                    warnings.push(
                        `Kolom ${kolomTanpaTahunSebelumnya.header.trim()} → ${header.trim()} melewati pergantian tahun, ` +
                        `tetapi semua dibaca sebagai tahun ${tahunBulan}. Tulis tahun di judul kolom, mis. "${header.trim()} ${tahunBulan + 1}".`
                    );
                }
                kolomTanpaTahunSebelumnya = { header, bulan: kolom.bulan };
            }

            const { hari, peringatan } = parseHariAmbil(val);
            peringatan.forEach(p => warnings.push(`${header.trim()}: ${p}`));

            for (const h of hari) {
                const iso = buildDateString(kolom.tahun ?? tahunBulan, kolom.bulan, h);
                if (!iso) {
                    warnings.push(`${header.trim()}: tanggal ${h} tidak ada di bulan tersebut, dilewati`);
                } else if (!pengambilan.includes(iso)) {
                    pengambilan.push(iso);
                }
            }
        }
        if (mapped.tanggal_pengambilan && !pengambilan.includes(mapped.tanggal_pengambilan)) {
            pengambilan.push(mapped.tanggal_pengambilan);
        }
        pengambilan.sort();

        // ── Field wajib ────────────────────────────────────────────────

        if (!mapped.noka) {
            errors.push('NOKA / NOKA Tersandi wajib diisi');
        }

        if (!mapped.fktp_asal) {
            errors.push('FKTP / Faskes Asal wajib diisi');
        }

        if (!mapped.diagnosis_prb || mapped.diagnosis_prb.length === 0) {
            errors.push('Diagnosis PRB wajib diisi');
        }

        // Tanggal Mulai PRB boleh kosong (pasien dianggap PRB Lama),
        // tetapi jika diisi harus bisa dibaca.
        if (mulai.gagal) {
            errors.push('Tanggal Mulai PRB tidak valid. Gunakan format DD/MM/YYYY (contoh: 01/08/2026).');
        } else if (!mapped.tanggal_mulai_prb) {
            warnings.push('Tanggal Mulai PRB kosong — pasien dianggap PRB Lama');
        }

        // ── Validasi tanggal ───────────────────────────────────────────

        if (!kosong(rawPengambilan) && !tanggalPengambilan) {
            errors.push('Tanggal Pengambilan tidak valid. Gunakan format DD/MM/YYYY (contoh: 04/10/2026).');
        }

        if (mapped.tanggal_berobat && !isValidDateString(mapped.tanggal_berobat)) {
            errors.push('Tanggal Berobat tidak valid. Gunakan format DD/MM/YYYY (contoh: 01/10/2026).');
        }

        // Tanggal Pengambilan tidak boleh lebih awal dari Tanggal Mulai PRB
        if (mapped.tanggal_mulai_prb && pengambilan.length > 0 && pengambilan[0] < mapped.tanggal_mulai_prb) {
            errors.push('Tanggal Pengambilan tidak boleh lebih awal dari Tanggal Mulai PRB.');
        }

        results.push({
            row: idx + 1,
            sheet: String(row['_sheet_name'] ?? 'Sheet1'),
            data: mapped,
            pengambilan,
            valid: errors.length === 0,
            errors,
            warnings,
        });
    });

    return results;
}

/**
 * =========================================================
 * PAYLOAD IMPORT
 * =========================================================
 *
 * Satu baris pasien dengan beberapa pengambilan → satu baris per
 * pengambilan (urut tanggal), karena backend mencatat satu transaksi
 * per baris dan menilai kepatuhan terhadap jadwal sebelumnya.
 * Baris pasien tanpa pengambilan dikirim sekali (data profil saja).
 */
export function buildImportPayload(results: ValidationResult[]): ImportRow[] {
    const payload: ImportRow[] = [];
    for (const r of results) {
        if (!r.valid) continue;
        if (r.pengambilan.length === 0) {
            payload.push({ ...r.data, tanggal_pengambilan: undefined });
            continue;
        }
        for (const tanggal of r.pengambilan) {
            payload.push({ ...r.data, tanggal_pengambilan: tanggal });
        }
    }
    return payload;
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
 * 5 kolom sesuai format data dari apotek (ketentuan etik penelitian):
 *   1. NOKA / NOKA Tersandi
 *   2. FKTP / Faskes Asal
 *   3. Diagnosis PRB
 *   4. Tanggal Mulai PRB
 *   5. Tanggal Pengambilan Obat Terakhir
 *
 * Nama pasien & No. HP TIDAK termasuk — tidak boleh dikumpulkan.
 * - NOKA diformat sebagai TEXT agar angka 0 di depan terjaga.
 * - Kolom tanggal diberi format DD/MM/YYYY.
 * - Sheet "Petunjuk" disertakan untuk panduan petugas.
 */
export function downloadImportTemplate(): void {
    const workbook = XLSX.utils.book_new();

    // ── Sheet 1: Template Import ──────────────────────────────────────────

    const headers = [
        'NOKA / NOKA Tersandi',
        'FKTP / Faskes Asal',
        'Diagnosis PRB',
        'Tanggal Mulai PRB',
        'Tanggal Pengambilan Obat Terakhir',
    ];

    const tahunIni  = new Date().getFullYear();
    const contohRow = [
        'PRB KPMA 001',
        'Puskesmas Kecamatan X',
        'Hipertensi, DM',
        `01/01/${tahunIni}`,
        `01/03/${tahunIni}`,
    ];

    const wsData: any[][] = [headers, contohRow];
    const worksheet = XLSX.utils.aoa_to_sheet(wsData);

    worksheet['!cols'] = [
        { wch: 24 }, // A — NOKA (TEXT)
        { wch: 32 }, // B — FKTP
        { wch: 36 }, // C — Diagnosis
        { wch: 24 }, // D — Tgl Mulai PRB
        { wch: 34 }, // E — Tgl Pengambilan Terakhir
    ];

    // Paksa kolom NOKA (A) & tanggal (D, E) sebagai TEXT: angka 0 di depan
    // NOKA tidak hilang dan tanggal tetap DD/MM/YYYY (tidak diubah Excel).
    const textColumns = [0, 3, 4];
    for (let r = 1; r < 200; r++) {
        textColumns.forEach(c => {
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
        ['3. Satu baris = satu pasien.'],
        [''],
        ['KOLOM & ATURAN PENGISIAN:'],
        [''],
        ['NOKA / NOKA Tersandi',
         'Nomor kartu pasien atau NOKA yang sudah disandikan. WAJIB DIISI.',
         'Format bebas (huruf, angka, spasi). Contoh: PRB KPMA 001',
        ],
        ['FKTP / Faskes Asal',
         'Nama Fasilitas Kesehatan Tingkat Pertama asal pasien. WAJIB DIISI.',
         'Contoh: Puskesmas Kecamatan X, Klinik Pratama Y',
        ],
        ['Diagnosis PRB',
         'Diagnosis pasien. WAJIB DIISI.',
         'Lebih dari satu diagnosis: pisahkan dengan koma (tanda - + / juga diterima).',
         'Singkatan HT, DM, PJK, dsb. dibakukan otomatis. Contoh: HT, DM atau DM-HT',
        ],
        ['Tanggal Mulai PRB',
         'Tanggal pasien mulai terdaftar program PRB. WAJIB DIISI.',
         'Format: DD/MM/YYYY',
         'Contoh: 01/08/2026',
        ],
        ['Tanggal Pengambilan Obat Terakhir',
         'Tanggal terakhir pasien mengambil obat di apotek. Boleh kosong untuk pasien baru.',
         'Format: DD/MM/YYYY',
         'Catatan: Jika diisi, sistem menghitung jadwal berikutnya otomatis (+30 hari untuk PRB Lama).',
         'Jika kosong dan Tanggal Mulai PRB diisi, jadwal dihitung dari mulai PRB +23 hari (PRB Baru).',
        ],
        [''],
        ['KETENTUAN ETIK:'],
        ['- JANGAN mencantumkan nama pasien maupun No. HP / No. Telepon.'],
        ['- Jika file berisi kolom tersebut, sistem mengabaikannya dan tidak menyimpannya.'],
        [''],
        ['CATATAN PENTING:'],
        ['- Simpan file sebagai .xlsx sebelum upload.'],
        ['- Sistem mengenali pasien berdasarkan NOKA.'],
        ['- Pasien yang NOKA-nya sudah ada akan diperbarui datanya, tidak diduplikasi.'],
        ['- Import bersifat kumulatif: data pasien sebelumnya tidak akan terhapus.'],
        ['- PRB Baru (tanggal mulai = bulan ini): jadwal pertama = mulai PRB + 23 hari.'],
        ['- PRB Lama (tanggal mulai = bulan sebelumnya): jadwal berikutnya = terakhir ambil + 30 hari.'],
        [''],
        ['FORMAT PER BULAN (alternatif):'],
        ['- Kolom tanggal pengambilan boleh diganti kolom per bulan (mis. MARET | APRIL | MEI) berisi tanggal ambil saja.'],
        ['- Ambil 2x dalam sebulan: tulis "1, 29". Tidak ambil: isi "-".'],
        ['- Tahun: tulis di judul kolom (mis. DESEMBER 2026 | JANUARI 2027), wajib bila file melewati pergantian tahun.'],
        ['  Judul tanpa tahun (mis. MARET) memakai tahun yang dipilih saat impor.'],
    ];

    const wsPetunjuk = XLSX.utils.aoa_to_sheet(petunjukData);
    wsPetunjuk['!cols'] = [
        { wch: 40 },
        { wch: 65 },
        { wch: 50 },
        { wch: 50 },
    ];
    XLSX.utils.book_append_sheet(workbook, wsPetunjuk, 'Petunjuk');

    XLSX.writeFile(workbook, 'template-import-pasien-prb.xlsx');
}
