export interface PasienPRB {
    id: string;
    kode_pasien: string;
    nama_pasien: string;
    no_kartu_bpjs: string;
    no_whatsapp: string | null;
    fktp_asal: string;
    diagnosis_prb: DiagnosisPRB[];
    tanggal_mulai_prb: string;
    tanggal_pengambilan_terakhir: string | null;
    tanggal_jadwal_berikutnya: string | null;
    tanggal_realisasi: string | null;
    jatah_obat: number;
    jenis_prb: 'Baru' | 'Lama';
    status: 'Aktif' | 'Pasif';
    alasan_pasif: string | null;
    sumber_data: string | null;
    created_at: string;
    updated_at: string;
    // Computed fields from backend
    status_kepatuhan?: StatusKepatuhan;
    selisih_hari_evaluasi?: number;
    keterangan_kepatuhan?: string;
    early_warning?: string;
    jadwal_target_aktif?: string;
    tanggal_realisasi_aktif?: string | null;  // tanggal realisasi siklus aktif/periode
    riwayat_id_aktif?: string | null;          // UUID riwayat siklus aktif, null jika belum ada
    // Relations
    riwayat_pengambilan_obat?: RiwayatPengambilanObat[];
    riwayat_tindak_lanjut?: RiwayatTindakLanjut[];
}

export type DiagnosisPRB =
    | 'Diabetes Melitus'
    | 'Hipertensi'
    | 'Penyakit Jantung'
    | 'Asma'
    | 'PPOK'
    | 'Stroke'
    | 'Epilepsi'
    | 'Skizofrenia'
    | 'SLE'
    | 'Lainnya';

export type StatusKepatuhan =
    | 'Patuh'
    | 'Tidak Patuh'
    | 'Belum Diambil'
    | 'Belum Jatuh Tempo'
    | 'Belum Ada Jadwal'
    | 'Pasif'
    | '—';

export type TindakanFollowUp =
    | 'Sudah mengambil obat'
    | 'Pasien akan datang'
    | 'Belum dapat dihubungi'
    | 'Pasien berhalangan'
    | 'Rujuk FKTP'
    | 'Lainnya';

export type AlasanPasif =
    | 'Rujuk Balik ke RS'
    | 'Meninggal Dunia'
    | 'Pindah Faskes'
    | 'Menolak Program'
    | 'Pasien Mandiri'
    | 'Kondisi Stabil';

export interface RiwayatPengambilanObat {
    id: string;
    pasien_prb_id: string;
    no_faktur?: string | null;
    tanggal_berobat?: string | null;
    tanggal_pengambilan: string;
    jadwal_target: string;
    selisih_hari: number;
    status_kepatuhan: string;
    created_at: string;
}

export interface RiwayatTindakLanjut {
    id: string;
    pasien_prb_id: string;
    tanggal_jam: string;
    petugas: string;
    tindakan: TindakanFollowUp;
    catatan: string | null;
    update_realisasi_otomatis: boolean;
    created_at: string;
}

export interface DashboardStats {
    total_pasien: number;
    total_aktif: number;
    total_pasif: number;
    patuh: number;
    tidak_patuh: number;
    dalam_toleransi: number;
    persentase_patuh: number;
    per_diagnosis: DiagnosisStats[];
    per_fktp: FktpStats[];
    tanggal_evaluasi: string;
}

export interface DiagnosisStats {
    diagnosis: string;
    total: number;
    patuh: number;
    tidak_patuh: number;
    persentase: number;
}

export interface FktpStats {
    fktp: string;
    total: number;
    patuh: number;
    tidak_patuh: number;
    persentase: number;
}

export interface PageProps {
    flash?: {
        success?: string;
        error?: string;
    };
}

export interface Filters {
    search: string;
    filter_status: string;
    filter_kepatuhan: string;
    filter_diagnosis: string;
    filter_fktp?: string;
    filter_periode?: string;
}

// Constants
export const DIAGNOSIS_OPTIONS: DiagnosisPRB[] = [
    'Diabetes Melitus',
    'Hipertensi',
    'Penyakit Jantung',
    'Asma',
    'PPOK',
    'Stroke',
    'Epilepsi',
    'Skizofrenia',
    'SLE',
    'Lainnya',
];

export const TINDAKAN_OPTIONS: TindakanFollowUp[] = [
    'Sudah mengambil obat',
    'Pasien akan datang',
    'Belum dapat dihubungi',
    'Pasien berhalangan',
    'Rujuk FKTP',
    'Lainnya',
];

export const ALASAN_PASIF_OPTIONS: AlasanPasif[] = [
    'Rujuk Balik ke RS',
    'Meninggal Dunia',
    'Pindah Faskes',
    'Menolak Program',
    'Pasien Mandiri',
    'Kondisi Stabil',
];

export const STATUS_KEPATUHAN_CONFIG: Record<StatusKepatuhan, { color: string; bgColor: string; borderColor: string; icon: string }> = {
    'Patuh': { color: 'text-emerald-700', bgColor: 'bg-emerald-50', borderColor: 'border-emerald-200', icon: '✓' },
    'Tidak Patuh': { color: 'text-rose-700', bgColor: 'bg-rose-50', borderColor: 'border-rose-200', icon: '✗' },
    'Belum Diambil': { color: 'text-amber-700', bgColor: 'bg-amber-50', borderColor: 'border-amber-200', icon: '⏳' },
    'Belum Jatuh Tempo': { color: 'text-blue-700', bgColor: 'bg-blue-50', borderColor: 'border-blue-200', icon: '📅' },
    'Belum Ada Jadwal': { color: 'text-slate-500', bgColor: 'bg-slate-50', borderColor: 'border-slate-200', icon: '—' },
    'Pasif': { color: 'text-slate-500', bgColor: 'bg-slate-100', borderColor: 'border-slate-200', icon: '⏸' },
    '—': { color: 'text-slate-500', bgColor: 'bg-slate-50', borderColor: 'border-slate-200', icon: '—' },
};
