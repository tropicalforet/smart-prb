import * as XLSX from 'xlsx';
import { PasienPRB, DIAGNOSIS_OPTIONS } from '@/types';

export interface ImportRow {
    nama_pasien: string;
    no_kartu_bpjs: string;
    no_whatsapp?: string;
    fktp_asal: string;
    diagnosis_prb: string;
    tanggal_mulai_prb: string;
    tanggal_pengambilan_terakhir?: string;
    jatah_obat?: number;
    kode_pasien?: string;
    status?: string;
}

export interface ValidationResult {
    row: number;
    data: ImportRow;
    valid: boolean;
    errors: string[];
}

/**
 * Parse file Excel menjadi array JSON.
 */
export function parseExcelFile(file: File): Promise<Record<string, any>[]> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = (e) => {
            try {
                const data = new Uint8Array(e.target?.result as ArrayBuffer);
                const workbook = XLSX.read(data, { type: 'array', cellDates: true });
                const sheetName = workbook.SheetNames[0];
                const sheet = workbook.Sheets[sheetName];
                const jsonData = XLSX.utils.sheet_to_json(sheet);
                resolve(jsonData);
            } catch (err) {
                reject(new Error('Gagal membaca file Excel. Pastikan format file benar.'));
            }
        };
        reader.onerror = () => reject(new Error('Gagal membaca file.'));
        reader.readAsArrayBuffer(file);
    });
}

/**
 * Validasi per baris data import.
 */
export function validateImportData(rows: Record<string, any>[]): ValidationResult[] {
    return rows.map((row, idx) => {
        const errors: string[] = [];

        const mapped: ImportRow = {
            nama_pasien: String(row['Nama Pasien'] || row['nama_pasien'] || '').trim(),
            no_kartu_bpjs: String(row['No. Kartu BPJS'] || row['no_kartu_bpjs'] || '').trim(),
            no_whatsapp: String(row['No. WhatsApp'] || row['no_whatsapp'] || '').trim() || undefined,
            fktp_asal: String(row['FKTP Asal'] || row['fktp_asal'] || '').trim(),
            diagnosis_prb: String(row['Diagnosis PRB'] || row['diagnosis_prb'] || '').trim(),
            tanggal_mulai_prb: formatDateValue(row['Tanggal Mulai PRB'] || row['tanggal_mulai_prb']),
            tanggal_pengambilan_terakhir: formatDateValue(row['Pengambilan Terakhir'] || row['tanggal_pengambilan_terakhir']) || undefined,
            jatah_obat: parseInt(String(row['Jatah Obat (Hari)'] || row['jatah_obat'] || '30')) || 30,
            kode_pasien: String(row['Kode Pasien'] || row['kode_pasien'] || '').trim() || undefined,
            status: String(row['Status'] || row['status'] || 'Aktif').trim(),
        };

        // Validasi field wajib
        if (!mapped.nama_pasien) errors.push('Nama Pasien wajib diisi');
        if (!mapped.no_kartu_bpjs) errors.push('No. Kartu BPJS wajib diisi');
        if (!mapped.fktp_asal) errors.push('FKTP Asal wajib diisi');
        if (!mapped.diagnosis_prb) errors.push('Diagnosis PRB wajib diisi');
        if (!mapped.tanggal_mulai_prb) errors.push('Tanggal Mulai PRB wajib diisi atau format tidak valid');

        // Validasi diagnosis
        if (mapped.diagnosis_prb && !DIAGNOSIS_OPTIONS.includes(mapped.diagnosis_prb as any)) {
            errors.push(`Diagnosis "${mapped.diagnosis_prb}" tidak valid. Pilihan: ${DIAGNOSIS_OPTIONS.join(', ')}`);
        }

        // Validasi tanggal
        if (mapped.tanggal_mulai_prb && isNaN(Date.parse(mapped.tanggal_mulai_prb))) {
            errors.push('Format tanggal mulai PRB tidak valid (gunakan YYYY-MM-DD)');
        }

        return {
            row: idx + 1,
            data: mapped,
            valid: errors.length === 0,
            errors,
        };
    });
}

function formatDateValue(val: any): string {
    if (!val) return '';
    if (val instanceof Date) {
        return val.toISOString().split('T')[0];
    }
    const str = String(val).trim();
    // Cek apakah sudah YYYY-MM-DD
    if (/^\d{4}-\d{2}-\d{2}$/.test(str)) return str;
    // Coba parse berbagai format
    const parsed = new Date(str);
    if (!isNaN(parsed.getTime())) {
        return parsed.toISOString().split('T')[0];
    }
    return str;
}

/**
 * Generate file Excel dari data ekspor.
 */
export function exportToExcel(data: Record<string, any>[], filename: string = 'laporan-smart-prb.xlsx'): void {
    const worksheet = XLSX.utils.json_to_sheet(data);

    // Auto-width kolom
    const colWidths = Object.keys(data[0] || {}).map(key => ({
        wch: Math.max(key.length, ...data.map(row => String(row[key] || '').length)) + 2,
    }));
    worksheet['!cols'] = colWidths;

    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, 'Laporan PRB');
    XLSX.writeFile(workbook, filename);
}

/**
 * Generate template Excel kosong untuk import.
 */
export function downloadImportTemplate(): void {
    const template = [
        {
            'Kode Pasien': 'PRB-001 (opsional, auto-generate jika kosong)',
            'Nama Pasien': 'Contoh: Ahmad Sudirman',
            'No. Kartu BPJS': '0001234567890',
            'No. WhatsApp': '081234567890',
            'FKTP Asal': 'Puskesmas Kecamatan X',
            'Diagnosis PRB': 'Hipertensi',
            'Tanggal Mulai PRB': '2024-01-15',
            'Pengambilan Terakhir': '2024-08-15',
            'Status': 'Aktif',
        },
    ];

    const worksheet = XLSX.utils.json_to_sheet(template);
    worksheet['!cols'] = [
        { wch: 20 }, { wch: 30 }, { wch: 20 }, { wch: 18 },
        { wch: 30 }, { wch: 20 }, { wch: 18 }, { wch: 18 },
        { wch: 10 },
    ];

    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, 'Template Import');
    XLSX.writeFile(workbook, 'template-import-pasien-prb.xlsx');
}
