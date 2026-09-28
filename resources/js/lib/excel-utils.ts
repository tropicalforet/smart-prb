import * as XLSX from 'xlsx';
import { PasienPRB, DIAGNOSIS_OPTIONS } from '@/types';

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
    tanggal_pengambilan_terakhir?: string;
}

export interface ValidationResult {
    row: number;
    data: ImportRow;
    valid: boolean;
    errors: string[];
}

/**
 * =========================================================
 * PARSE FILE EXCEL
 * =========================================================
 */
export function parseExcelFile(
    file: File
): Promise<Record<string, any>[]> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onload = (e) => {
            try {
                const data = new Uint8Array(
                    e.target?.result as ArrayBuffer
                );

                const workbook = XLSX.read(data, {
                    type: 'array',
                    cellDates: true,
                });

                const sheetName =
                    workbook.SheetNames[0];

                const sheet =
                    workbook.Sheets[sheetName];

                const jsonData =
                    XLSX.utils.sheet_to_json<Record<string, any>>(sheet, {
                        defval: '',
                    });

                resolve(jsonData);
            } catch (err) {
                reject(
                    new Error(
                        'Gagal membaca file Excel. Pastikan format file benar.'
                    )
                );
            }
        };

        reader.onerror = () =>
            reject(
                new Error(
                    'Gagal membaca file.'
                )
            );

        reader.readAsArrayBuffer(file);
    });
}

/**
 * =========================================================
 * VALIDASI DATA IMPORT
 * =========================================================
 */
export function validateImportData(
    rows: Record<string, any>[]
): ValidationResult[] {
    return rows.map((row, idx) => {
        const errors: string[] = [];

        const tanggalMulai =
            formatDateValue(
                row['Tanggal Mulai PRB'] ??
                    row['tanggal_mulai_prb']
            );

        const tanggalTerakhir =
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

            no_kartu_bpjs:
                String(
                    row['No. Kartu BPJS'] ??
                        row['no_kartu_bpjs'] ??
                        ''
                ).trim(),

            no_whatsapp:
                String(
                    row['No. WhatsApp'] ??
                        row['no_whatsapp'] ??
                        ''
                ).trim() || undefined,

            fktp_asal:
                String(
                    row['FKTP Asal'] ??
                        row['fktp_asal'] ??
                        ''
                ).trim(),

            diagnosis_prb:
                String(
                    row['Diagnosis PRB'] ??
                        row['diagnosis_prb'] ??
                        ''
                ).split(',').map(d => d.trim()).filter(Boolean),

            tanggal_mulai_prb:
                tanggalMulai,

            tanggal_berobat:
                tanggalBerobat,

            tanggal_pengambilan_terakhir:
                tanggalTerakhir ||
                undefined,
        };

        /*
         * =====================================================
         * FIELD WAJIB
         * =====================================================
         */

        if (!mapped.nama_pasien) {
            errors.push(
                'Nama Pasien wajib diisi'
            );
        }

        if (!mapped.no_kartu_bpjs) {
            errors.push(
                'No. Kartu BPJS wajib diisi'
            );
        }

        if (!mapped.fktp_asal) {
            errors.push(
                'FKTP Asal wajib diisi'
            );
        }

        if (!mapped.diagnosis_prb || mapped.diagnosis_prb.length === 0) {
            errors.push(
                'Diagnosis PRB wajib diisi'
            );
        }

        if (!mapped.tanggal_mulai_prb) {
            errors.push(
                'Tanggal Mulai PRB wajib diisi'
            );
        }

        /*
         * =====================================================
         * VALIDASI DIAGNOSIS
         * =====================================================
         */

        if (mapped.diagnosis_prb && mapped.diagnosis_prb.length > 0) {
            const invalidDiagnoses = mapped.diagnosis_prb.filter(d => !DIAGNOSIS_OPTIONS.includes(d as any));
            if (invalidDiagnoses.length > 0) {
                errors.push(
                    `Diagnosis "${invalidDiagnoses.join(', ')}" tidak valid. Pilihan: ${DIAGNOSIS_OPTIONS.join(', ')}`
                );
            }
        }

        /*
         * =====================================================
         * VALIDASI TANGGAL
         * =====================================================
         */

        if (
            mapped.tanggal_mulai_prb &&
            !isValidDateString(
                mapped.tanggal_mulai_prb
            )
        ) {
            errors.push(
                'Tanggal Mulai PRB tidak valid. Gunakan format DD-MM-YYYY.'
            );
        }

        if (
            mapped.tanggal_pengambilan_terakhir &&
            !isValidDateString(
                mapped.tanggal_pengambilan_terakhir
            )
        ) {
            errors.push(
                'Pengambilan Terakhir tidak valid. Gunakan format DD-MM-YYYY.'
            );
        }

        /*
         * Pengambilan terakhir tidak boleh
         * lebih awal dari tanggal mulai PRB.
         */
        if (
            mapped.tanggal_mulai_prb &&
            mapped.tanggal_pengambilan_terakhir &&
            isValidDateString(
                mapped.tanggal_mulai_prb
            ) &&
            isValidDateString(
                mapped.tanggal_pengambilan_terakhir
            )
        ) {
            const mulai =
                new Date(
                    mapped.tanggal_mulai_prb
                );

            const terakhir =
                new Date(
                    mapped.tanggal_pengambilan_terakhir
                );

            if (terakhir < mulai) {
                errors.push(
                    'Pengambilan Terakhir tidak boleh lebih awal dari Tanggal Mulai PRB.'
                );
            }
        }

        return {
            row: idx + 1,
            data: mapped,
            valid: errors.length === 0,
            errors,
        };
    });
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
 * HANYA 8 KOLOM.
 *
 * Tidak ada:
 * - Jenis PRB
 * - Jatah Obat
 * - Jadwal Berikutnya
 * - Realisasi
 * - Status
 * - Status Kepatuhan
 */
export function downloadImportTemplate(): void {
    const template = [
        {
            'No. Faktur / No. Transaksi Apotek':
                'INV-202408-001',

            'Kode Pasien':
                'PRB-001',

            'Nama Pasien':
                'Ahmad Sudirman',

            'No. Kartu BPJS':
                '0001234567890',

            'No. WhatsApp':
                '081234567890',

            'FKTP Asal':
                'Puskesmas Kecamatan X',

            'Diagnosis PRB':
                'Hipertensi',

            'Tanggal Mulai PRB':
                '15-01-2024',

            'Tanggal Berobat':
                '15-08-2024',

            'Tanggal Pengambilan':
                '15-08-2024',
        },
    ];

    const worksheet =
        XLSX.utils.json_to_sheet(
            template
        );

    worksheet['!cols'] = [
        { wch: 22 },
        { wch: 18 },
        { wch: 30 },
        { wch: 20 },
        { wch: 18 },
        { wch: 32 },
        { wch: 24 },
        { wch: 22 },
        { wch: 20 },
        { wch: 24 },
    ];

    const workbook =
        XLSX.utils.book_new();

    XLSX.utils.book_append_sheet(
        workbook,
        worksheet,
        'Template Import'
    );

    XLSX.writeFile(
        workbook,
        'template-import-pasien-prb.xlsx'
    );
}