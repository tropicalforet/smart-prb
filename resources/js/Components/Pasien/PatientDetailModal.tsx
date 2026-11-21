import { useState, useEffect } from 'react';
import { PasienPRB, RiwayatPengingatWa, StatusPasien, TINDAKAN_OPTIONS, KATEGORI_TINDAK_LANJUT_OPTIONS, DIAGNOSIS_OPTIONS, ALASAN_PASIF_OPTIONS, STATUS_PASIEN_OPTIONS } from '@/types';
import { Modal } from '../ui/Modal';
import { StatusBadge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { router } from '@inertiajs/react';
import {
    User, IdCard, FileText, Activity, Calendar,
    History, MessageSquarePlus, Stethoscope, Save, Pencil, Phone, Send, Trash2
} from 'lucide-react';
import { pesanPengingat, tahapPengingat, tautanWhatsapp, formatNoWhatsapp, LABEL_TAHAP } from '@/lib/pengingat-wa';
import { samarkanNama } from '@/lib/samaran';
import { csrfHeaders } from '@/lib/csrf';
import { FKTP_OPTIONS, bakukanFktp } from '@/lib/fktp';

/** diagnosis_prb bisa tersimpan sebagai array atau string JSON. */
function daftarDiagnosis(diag: unknown): string[] {
    if (Array.isArray(diag)) return diag.map(String);
    if (typeof diag === 'string') {
        try {
            const parsed = JSON.parse(diag);
            if (Array.isArray(parsed)) return parsed.map(String);
        } catch {}
        return diag ? [diag] : [];
    }
    return [];
}

const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500';

interface FormUbahData {
    nama_samaran: string;
    no_whatsapp: string;
    fktp_asal: string;
    diagnosis_prb: string[];
    tanggal_mulai_prb: string;
    status: StatusPasien;
    alasan_pasif: string;
}

// Format tanggal: DD/MM/YYYY
const BULAN_ID = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];

function formatTglHistori(tgl: string | null | undefined): string {
    if (!tgl) return '-';
    const d = new Date(tgl);
    if (isNaN(d.getTime())) return '-';
    const hari  = String(d.getDate()).padStart(2, '0');
    const bulan = String(d.getMonth() + 1).padStart(2, '0');
    const tahun = d.getFullYear();
    return `${hari}/${bulan}/${tahun}`;
}

interface Props {
    pasienId: string | null;
    isOpen: boolean;
    onClose: () => void;
    tanggalEvaluasi: string;
    filterPeriode?: string;
    /** Dipanggil setelah tindak lanjut berkategori tersimpan (untuk menyesuaikan filter Data Pasien). */
    onTindakLanjutTersimpan?: (kategori: string) => void;
}

export function PatientDetailModal({ pasienId, isOpen, onClose, tanggalEvaluasi, filterPeriode, onTindakLanjutTersimpan }: Props) {
    const [pasien, setPasien] = useState<PasienPRB | null>(null);
    const [loading, setLoading] = useState(false);
    const [activeTab, setActiveTab] = useState<'profil' | 'riwayat' | 'tindak_lanjut'>('profil');

    // Form state — tindak lanjut
    const [tindakan, setTindakan] = useState('');
    const [kategori, setKategori] = useState('');
    const [catatan, setCatatan] = useState('');
    const [tanggalRealisasi, setTanggalRealisasi] = useState(new Date().toISOString().split('T')[0]);
    const [submitting, setSubmitting] = useState(false);

    // State ubah data pasien (FKTP, diagnosis, mulai PRB, status)
    const [formUbah, setFormUbah] = useState<FormUbahData | null>(null);
    const [errorsUbah, setErrorsUbah] = useState<Record<string, string>>({});
    const [savingUbah, setSavingUbah] = useState(false);

    // State edit histori per riwayat
    const [editingRiwayatId, setEditingRiwayatId] = useState<string | null>(null);
    const [editingRiwayatTanggal, setEditingRiwayatTanggal] = useState('');
    const [savingRiwayat, setSavingRiwayat] = useState(false);

    // Hapus pengambilan terbaru yang salah input
    const [hapusRiwayatId, setHapusRiwayatId] = useState<string | null>(null);
    const [menghapusRiwayat, setMenghapusRiwayat] = useState(false);
    const handleHapusRiwayat = (riwayatId: string) => {
        if (!pasienId) return;
        setMenghapusRiwayat(true);
        router.delete(`/pasien/${pasienId}/realisasi/${riwayatId}`, {
            preserveScroll: true,
            onFinish: () => {
                setMenghapusRiwayat(false);
                setHapusRiwayatId(null);
                refreshPasien();
            },
        });
    };

    // Helper: refresh data pasien setelah perubahan
    const refreshPasien = () => {
        if (!pasienId) return;
        const params = new URLSearchParams({ tanggal_evaluasi: tanggalEvaluasi });
        if (filterPeriode) params.set('filter_periode', filterPeriode);
        fetch(`/pasien/${pasienId}?${params.toString()}`, {
            headers: { 'Accept': 'application/json' }
        })
            .then(res => res.json())
            .then(data => setPasien(data));
    };

    const handleEditRiwayat = (riwayat: import('@/types').RiwayatPengambilanObat) => {
        setEditingRiwayatId(riwayat.id);
        setEditingRiwayatTanggal(
            typeof riwayat.tanggal_pengambilan === 'string'
                ? riwayat.tanggal_pengambilan.split('T')[0]
                : new Date(riwayat.tanggal_pengambilan).toISOString().split('T')[0]
        );
    };

    const handleSaveRiwayat = (riwayatId: string) => {
        if (!pasienId) return;
        setSavingRiwayat(true);
        router.put(`/pasien/${pasienId}/realisasi`, {
            riwayat_id:        riwayatId,
            tanggal_realisasi: editingRiwayatTanggal,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setSavingRiwayat(false);
                setEditingRiwayatId(null);
                refreshPasien();
            },
            onError: () => {
                setSavingRiwayat(false);
            },
        });
    };

    const mulaiUbahData = () => {
        if (!pasien) return;
        setErrorsUbah({});
        setFormUbah({
            nama_samaran: pasien.nama_samaran ?? '',
            no_whatsapp: pasien.no_whatsapp ? formatNoWhatsapp(pasien.no_whatsapp) : '',
            fktp_asal: bakukanFktp(pasien.fktp_asal) ?? '',
            diagnosis_prb: daftarDiagnosis(pasien.diagnosis_prb),
            tanggal_mulai_prb: (pasien.tanggal_mulai_prb ?? '').slice(0, 10),
            status: pasien.status,
            alasan_pasif: pasien.alasan_pasif ?? '',
        });
    };

    // Satu data PRB = satu diagnosis → pilih satu (diagnosis lain lewat Tambah)
    const pilihDiagnosis = (diag: string) => {
        setFormUbah(f => f && ({ ...f, diagnosis_prb: [diag] }));
    };

    const handleSimpanUbahData = (e: React.FormEvent) => {
        e.preventDefault();
        if (!pasien || !formUbah) return;
        setSavingUbah(true);
        setErrorsUbah({});
        router.put(`/pasien/${pasien.id}`, {
            noka: pasien.noka, // NOKA = identitas pasien, tidak diubah dari sini
            nama_samaran: samarkanNama(formUbah.nama_samaran),
            no_whatsapp: formUbah.no_whatsapp.trim(),
            fktp_asal: formUbah.fktp_asal.replace(/\s+/g, ' ').trim(),
            diagnosis_prb: formUbah.diagnosis_prb,
            tanggal_mulai_prb: formUbah.tanggal_mulai_prb,
            status: formUbah.status,
            alasan_pasif: formUbah.status === 'Pasif' ? formUbah.alasan_pasif : null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setSavingUbah(false);
                setFormUbah(null);
                refreshPasien();
            },
            onError: (errs) => {
                setSavingUbah(false);
                setErrorsUbah(errs as Record<string, string>);
            },
        });
    };

    useEffect(() => {
        if (isOpen && pasienId) {
            setLoading(true);
            // Sertakan filter_periode agar server mengevaluasi dalam konteks
            // periode yang sedang dilihat user (bukan selalu siklus aktif terbaru).
            const params = new URLSearchParams({ tanggal_evaluasi: tanggalEvaluasi });
            if (filterPeriode) params.set('filter_periode', filterPeriode);

            fetch(`/pasien/${pasienId}?${params.toString()}`, {
                headers: { 'Accept': 'application/json' }
            })
                .then(res => res.json())
                .then(data => {
                    setPasien(data);
                    setLoading(false);
                })
                .catch(() => setLoading(false));
        } else {
            setPasien(null);
            setActiveTab('profil');
            setFormUbah(null);
        }
    }, [isOpen, pasienId, tanggalEvaluasi, filterPeriode]);

    const handleTindakLanjutSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);

        const data: any = { tindakan, catatan };
        if (tindakan === 'Belum mengambil obat') {
            data.kategori = kategori;
        }
        // Kategori yang disimpan — "Sudah mengambil obat" tidak punya kategori
        const kategoriTersimpan = tindakan === 'Belum mengambil obat' ? kategori : '';
        if (tindakan === 'Sudah mengambil obat') {
            data.tanggal_realisasi = tanggalRealisasi;
        }

        router.post(`/pasien/${pasienId}/tindak-lanjut`, data, {
            preserveScroll: true,
            onSuccess: () => {
                setSubmitting(false);
                setTindakan('');
                setKategori('');
                setCatatan('');
                refreshPasien();
                if (kategoriTersimpan) onTindakLanjutTersimpan?.(kategoriTersimpan);
            },
            onError: () => setSubmitting(false),
        });
    };

    if (!pasien) {
        return (
            <Modal isOpen={isOpen} onClose={onClose} title="Detail Pasien" maxWidth="3xl">
                <div className="h-64 flex items-center justify-center">
                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-teal-600"></div>
                </div>
            </Modal>
        );
    }

    // Baris gabungan: beberapa diagnosis dengan tanggal mulai & ambil yang sama
    const diagnosisTampil = pasien.diagnosis_kelompok?.length ? pasien.diagnosis_kelompok : daftarDiagnosis(pasien.diagnosis_prb);
    const barisGabungan = (pasien.anggota_ids?.length ?? 1) > 1;

    return (
        <Modal isOpen={isOpen} onClose={onClose} title={`Detail: ${pasien.kode_pasien} · ${diagnosisTampil.join(', ')}`} maxWidth="4xl">
            <div className="flex flex-col lg:flex-row gap-6">
                {/* Left Column: Fixed Profile Summary */}
                <div className="lg:w-1/3 space-y-4">
                    <div className="bg-slate-50 p-4 rounded-xl border border-slate-100">
                        <div className="w-16 h-16 rounded-full bg-teal-100 text-teal-600 flex items-center justify-center mb-4 mx-auto text-xl font-bold">
                            <IdCard className="w-7 h-7" />
                        </div>
                        <h4 className="text-center font-bold text-slate-800 text-lg leading-tight mb-1">
                            {pasien.kode_pasien}
                        </h4>
                        {pasien.nama_samaran && (
                            <p className="text-center text-slate-700 text-sm font-medium mb-1">
                                {pasien.nama_samaran}
                            </p>
                        )}
                        <p className="text-center text-slate-500 text-sm mb-1 font-mono">
                            NOKA {pasien.noka}
                        </p>
                        <p className="text-center text-slate-500 text-sm mb-4 font-mono">
                            Tanggal Aktif {formatTglHistori(pasien.tanggal_mulai_prb)}
                        </p>

                        <div className="flex justify-center items-center gap-2 mb-6">
                            <StatusBadge status={pasien.status_kepatuhan || 'Belum Ada Jadwal'} />
                            <span className={`text-[10px] px-2 py-1 rounded-full border font-bold uppercase tracking-wider ${
                                pasien.jenis_prb === 'Baru' 
                                    ? 'bg-blue-100 text-blue-700 border-blue-200' 
                                    : 'bg-slate-100 text-slate-700 border-slate-200'
                            }`}>
                                PRB {pasien.jenis_prb}
                            </span>
                        </div>

                        <div className="space-y-3 text-sm">
                            <div className="flex items-center gap-3 text-slate-600">
                                <Stethoscope className="w-4 h-4 text-slate-400 shrink-0" />
                                <span className="font-medium">
                                    {diagnosisTampil.length > 0 ? diagnosisTampil.join(', ') : '-'}
                                </span>
                            </div>
                            <div className="flex items-center gap-3 text-slate-600">
                                <Activity className="w-4 h-4 text-slate-400 shrink-0" />
                                <span>{pasien.fktp_asal}</span>
                            </div>
                            <div className="flex items-center gap-3 text-slate-600">
                                <Phone className="w-4 h-4 text-slate-400 shrink-0" />
                                <span className={pasien.no_whatsapp ? 'font-mono' : 'text-slate-400'}>
                                    {pasien.no_whatsapp ? formatNoWhatsapp(pasien.no_whatsapp) : 'No. WhatsApp belum diisi'}
                                </span>
                            </div>
                        </div>

                    </div>

                    {/* key: isi pesan yang diubah tidak terbawa ke pasien lain */}
                    <PengingatWhatsapp key={pasien.id} pasien={pasien} />
                </div>

                {/* Right Column: Tabs */}
                <div className="lg:w-2/3">
                    <div className="border-b border-slate-200">
                        <nav className="-mb-px flex space-x-6">
                            <button
                                onClick={() => setActiveTab('profil')}
                                className={`whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm transition-colors ${activeTab === 'profil' ? 'border-teal-500 text-teal-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'}`}
                            >
                                Info Pasien & Jadwal
                            </button>
                            <button
                                onClick={() => setActiveTab('riwayat')}
                                className={`whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm transition-colors ${activeTab === 'riwayat' ? 'border-teal-500 text-teal-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'}`}
                            >
                                Histori Pengambilan
                            </button>
                            <button
                                onClick={() => setActiveTab('tindak_lanjut')}
                                className={`whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm transition-colors ${activeTab === 'tindak_lanjut' ? 'border-teal-500 text-teal-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'}`}
                            >
                                Tindak Lanjut
                            </button>
                        </nav>
                    </div>

                    <div className="pt-6 min-h-[300px]">
                        {activeTab === 'profil' && (
                            <div className="space-y-6 animate-fade-in-up">
                                <div>
                                    <h5 className="text-sm font-semibold text-slate-800 mb-3 flex items-center gap-2">
                                        <Calendar className="w-4 h-4 text-teal-600" />
                                        Jadwal & Kepatuhan
                                    </h5>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="bg-slate-50 p-3 rounded-lg border border-slate-100">
                                            <div className="text-xs text-slate-500 mb-1">Pengambilan Terakhir</div>
                                            <div className="font-medium text-slate-800">
                                                {pasien.tanggal_pengambilan_terakhir ? formatTglHistori(pasien.tanggal_pengambilan_terakhir) : 'Belum pernah'}
                                            </div>
                                        </div>
                                        <div className="bg-slate-50 p-3 rounded-lg border border-slate-100">
                                            <div className="text-xs text-slate-500 mb-1">Jadwal Berikutnya</div>
                                            <div className="font-medium text-slate-800">
                                                {pasien.tanggal_jadwal_berikutnya ? formatTglHistori(pasien.tanggal_jadwal_berikutnya) : '-'}
                                            </div>
                                        </div>
                                    </div>
                                    {pasien.keterangan_kepatuhan && (
                                        <div className="mt-3 bg-blue-50 text-blue-800 text-sm p-3 rounded-lg border border-blue-100">
                                            {pasien.keterangan_kepatuhan}
                                        </div>
                                    )}
                                </div>

                                <div>
                                    <div className="flex items-center justify-between mb-3">
                                        <h5 className="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                            <FileText className="w-4 h-4 text-teal-600" />
                                            Informasi Pasien
                                        </h5>
                                        {!formUbah && (
                                            <button
                                                type="button"
                                                onClick={mulaiUbahData}
                                                className="inline-flex items-center gap-1.5 text-xs font-medium text-teal-700 hover:text-teal-800 bg-teal-50 hover:bg-teal-100 border border-teal-200 rounded-lg px-2.5 py-1.5 transition-colors"
                                            >
                                                <Pencil className="w-3.5 h-3.5" />
                                                Ubah Data
                                            </button>
                                        )}
                                    </div>

                                    {formUbah ? (
                                        <form onSubmit={handleSimpanUbahData} className="space-y-4 bg-slate-50 border border-slate-200 rounded-xl p-4">
                                            {Object.keys(errorsUbah).length > 0 && (
                                                <div className="bg-rose-50 border border-rose-200 rounded-lg px-3 py-2 text-sm text-rose-700 space-y-1">
                                                    {Object.entries(errorsUbah).map(([field, msg]) => (
                                                        <div key={field}>⚠ {msg}</div>
                                                    ))}
                                                </div>
                                            )}

                                            <div>
                                                <label htmlFor="ubah_nama" className="block text-xs font-medium text-slate-700 mb-1">Nama Pasien (Opsional)</label>
                                                <input
                                                    id="ubah_nama"
                                                    type="text"
                                                    value={formUbah.nama_samaran}
                                                    onChange={e => setFormUbah({ ...formUbah, nama_samaran: e.target.value.slice(0, 150) })}
                                                    placeholder="Contoh: Rizka Dwi Putri"
                                                    className={inputClass}
                                                />
                                                <p className="text-xs text-slate-500 mt-1">
                                                    Disimpan tersamar{formUbah.nama_samaran.trim() ? <>: <span className="font-mono">{samarkanNama(formUbah.nama_samaran)}</span></> : '.'}
                                                </p>
                                            </div>

                                            <div>
                                                <label htmlFor="ubah_wa" className="block text-xs font-medium text-slate-700 mb-1">No. WhatsApp (Opsional)</label>
                                                <input
                                                    id="ubah_wa"
                                                    type="tel"
                                                    inputMode="tel"
                                                    value={formUbah.no_whatsapp}
                                                    onChange={e => setFormUbah({ ...formUbah, no_whatsapp: e.target.value.replace(/[^\d\s+-]/g, '').slice(0, 20) })}
                                                    placeholder="Contoh: 081234567890"
                                                    className={`${inputClass} font-mono`}
                                                />
                                                <p className="text-xs text-slate-500 mt-1">Kosongkan untuk menghapus nomor.</p>
                                            </div>

                                            <div>
                                                <label htmlFor="ubah_fktp" className="block text-xs font-medium text-slate-700 mb-1">FKTP Asal (Perujuk) *</label>
                                                <select
                                                    id="ubah_fktp"
                                                    value={formUbah.fktp_asal}
                                                    onChange={e => setFormUbah({ ...formUbah, fktp_asal: e.target.value })}
                                                    required
                                                    className={inputClass}
                                                >
                                                    <option value="">Pilih FKTP…</option>
                                                    {FKTP_OPTIONS.map(f => <option key={f} value={f}>{f}</option>)}
                                                </select>
                                            </div>

                                            {barisGabungan ? (
                                                // Beberapa diagnosis satu baris: perubahan berlaku untuk semuanya,
                                                // diagnosis tidak diubah dari sini
                                                <div>
                                                    <span className="block text-xs font-medium text-slate-700 mb-1">Diagnosis PRB</span>
                                                    <p className="text-sm text-slate-700">{diagnosisTampil.join(', ')}</p>
                                                </div>
                                            ) : (
                                            <fieldset>
                                                <legend className="block text-xs font-medium text-slate-700 mb-1">Diagnosis PRB * <span className="font-normal text-slate-500">(satu diagnosis per data; diagnosis lain lewat Tambah)</span></legend>
                                                <div className="grid grid-cols-2 gap-2">
                                                    {/* Pilihan baku + diagnosis yang sudah tersimpan (mis. dari import) agar tidak hilang */}
                                                    {[...new Set([...DIAGNOSIS_OPTIONS, ...daftarDiagnosis(pasien.diagnosis_prb)])].map(opt => (
                                                        <label key={opt} className="flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                                                            <input
                                                                type="radio"
                                                                name="ubah_diagnosis"
                                                                checked={formUbah.diagnosis_prb.length === 1 && formUbah.diagnosis_prb[0] === opt}
                                                                onChange={() => pilihDiagnosis(opt)}
                                                                className="w-4 h-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                                            />
                                                            {opt}
                                                        </label>
                                                    ))}
                                                </div>
                                                {formUbah.diagnosis_prb.length === 0 && (
                                                    <p className="text-xs text-rose-500 mt-1">Pilih minimal satu diagnosis.</p>
                                                )}
                                            </fieldset>
                                            )}

                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                <div>
                                                    <label htmlFor="ubah_mulai" className="block text-xs font-medium text-slate-700 mb-1">Tgl Mulai PRB *</label>
                                                    <input
                                                        id="ubah_mulai"
                                                        type="date"
                                                        value={formUbah.tanggal_mulai_prb}
                                                        onChange={e => setFormUbah({ ...formUbah, tanggal_mulai_prb: e.target.value })}
                                                        required
                                                        className={inputClass}
                                                    />
                                                </div>
                                                <div>
                                                    <label htmlFor="ubah_status" className="block text-xs font-medium text-slate-700 mb-1">Status Kepesertaan *</label>
                                                    <select
                                                        id="ubah_status"
                                                        value={formUbah.status}
                                                        onChange={e => setFormUbah({ ...formUbah, status: e.target.value as StatusPasien })}
                                                        className={inputClass}
                                                    >
                                                        {STATUS_PASIEN_OPTIONS.map(opt => <option key={opt} value={opt}>{opt}</option>)}
                                                    </select>
                                                </div>
                                            </div>

                                            {formUbah.status === 'Pasif' && (
                                                <div>
                                                    <label htmlFor="ubah_alasan" className="block text-xs font-medium text-slate-700 mb-1">Alasan Pasif *</label>
                                                    <select
                                                        id="ubah_alasan"
                                                        value={formUbah.alasan_pasif}
                                                        onChange={e => setFormUbah({ ...formUbah, alasan_pasif: e.target.value })}
                                                        required
                                                        className={inputClass}
                                                    >
                                                        <option value="">Pilih alasan...</option>
                                                        {[...new Set([...ALASAN_PASIF_OPTIONS, ...(pasien.alasan_pasif ? [pasien.alasan_pasif] : [])])].map(opt => (
                                                            <option key={opt} value={opt}>{opt}</option>
                                                        ))}
                                                    </select>
                                                    <p className="text-xs text-slate-500 mt-1">Pasien Pasif keluar dari pemantauan: kepatuhannya tidak lagi dinilai di Dashboard maupun Rekap.</p>
                                                </div>
                                            )}

                                            <p className="text-xs text-slate-500">
                                                Jenis PRB, jatah obat, dan jadwal berikutnya dihitung ulang otomatis dari Tgl Mulai PRB dan pengambilan terakhir.
                                            </p>

                                            <div className="flex justify-end gap-2">
                                                <Button type="button" variant="ghost" onClick={() => setFormUbah(null)}>Batal</Button>
                                                <Button
                                                    type="submit"
                                                    isLoading={savingUbah}
                                                    disabled={formUbah.diagnosis_prb.length === 0}
                                                    icon={<Save className="w-4 h-4" />}
                                                >
                                                    Simpan Perubahan
                                                </Button>
                                            </div>
                                        </form>
                                    ) : (
                                    <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-4">
                                        <div>
                                            <dt className="text-xs font-medium text-slate-500">Jatah Obat</dt>
                                            <dd className="mt-1 text-sm text-slate-900">{pasien.jatah_obat} Hari</dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs font-medium text-slate-500">Status Kepesertaan</dt>
                                            <dd className="mt-1">
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${pasien.status === 'Aktif' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-800'}`}>
                                                    {pasien.status}
                                                </span>
                                            </dd>
                                        </div>
                                        {pasien.status === 'Pasif' && (
                                            <div>
                                                <dt className="text-xs font-medium text-slate-500">Alasan Pasif</dt>
                                                <dd className="mt-1 text-sm text-slate-900">{pasien.alasan_pasif}</dd>
                                            </div>
                                        )}
                                    </dl>
                                    )}
                                </div>
                            </div>
                        )}

                        {activeTab === 'riwayat' && (
                            <div className="animate-fade-in-up">
                                {pasien.riwayat_pengambilan_obat && pasien.riwayat_pengambilan_obat.length > 0 ? (
                                    <div className="relative border-l border-slate-200 ml-3 space-y-6">
                                        {(() => {
                                            // Deduplicate: satu No. Faktur = satu transaksi
                                            const seen = new Set<string>();
                                            const unique = pasien.riwayat_pengambilan_obat.filter((r) => {
                                                const key = r.no_faktur
                                                    ? `faktur_${r.no_faktur}`
                                                    : `bpjs_${r.tanggal_berobat || r.tanggal_pengambilan}`;
                                                if (seen.has(key)) return false;
                                                seen.add(key);
                                                return true;
                                            });
                                            // Yang terakhir DICATAT (bukan tanggal terbesar) — sama dengan server
                                            const terakhirDicatat = [...unique].sort((a, b) =>
                                                `${b.created_at ?? ''}${b.id}`.localeCompare(`${a.created_at ?? ''}${a.id}`))[0]?.id;
                                             return unique.map((riwayat) => {
                                                // Hanya realisasi manual yang terakhir dicatat yang bisa dihapus (salah input)
                                                const bisaDihapus = riwayat.id === terakhirDicatat && !!riwayat.no_faktur?.startsWith('REALISASI-');
                                                const selisih = riwayat.selisih_hari ?? 0;

                                                // selisih_hari: positif = terlambat, negatif = lebih awal, 0 = tepat waktu
                                                let selisihText: string;
                                                if (selisih > 0) {
                                                    selisihText = `Terlambat ${selisih} hari`;
                                                } else if (selisih < 0) {
                                                    selisihText = `${Math.abs(selisih)} hari lebih awal`;
                                                } else {
                                                    selisihText = 'Tepat waktu';
                                                }

                                                const isPatuh = riwayat.status_kepatuhan === 'Patuh';
                                                const dotColor = isPatuh ? 'bg-emerald-500' : 'bg-rose-500';
                                                const badgeBg  = isPatuh
                                                    ? 'bg-emerald-100 text-emerald-700'
                                                    : 'bg-rose-100 text-rose-700';
                                                const selisihColor = isPatuh
                                                    ? 'text-emerald-600'
                                                    : selisih < 0
                                                        ? 'text-amber-600'   // lebih awal (tidak teratur)
                                                        : 'text-rose-600';   // terlambat (tidak teratur)

                                                return (
                                            <div key={riwayat.id} className="relative pl-6">
                                                <div className={`absolute -left-1.5 top-1.5 w-3 h-3 rounded-full border-2 border-white ${dotColor}`} />
                                                <div className="bg-slate-50 border border-slate-100 rounded-lg p-4">
                                                    <div className="flex items-center justify-between mb-2">
                                                        {editingRiwayatId === riwayat.id ? (
                                                            <div className="flex items-center gap-1.5 flex-1 mr-2">
                                                                <input
                                                                    type="date"
                                                                    value={editingRiwayatTanggal}
                                                                    onChange={e => setEditingRiwayatTanggal(e.target.value)}
                                                                    className="rounded border border-slate-300 text-xs py-1 px-2 focus:ring-teal-500 focus:border-teal-500 w-[130px]"
                                                                />
                                                                <button
                                                                    disabled={savingRiwayat}
                                                                    onClick={() => handleSaveRiwayat(riwayat.id)}
                                                                    className="text-teal-600 hover:text-teal-700 p-1 bg-teal-50 hover:bg-teal-100 rounded transition-colors disabled:opacity-50"
                                                                    title="Simpan"
                                                                >
                                                                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"/></svg>
                                                                </button>
                                                                <button
                                                                    onClick={() => setEditingRiwayatId(null)}
                                                                    className="text-slate-400 hover:text-slate-600 p-1 bg-slate-100 hover:bg-slate-200 rounded transition-colors"
                                                                    title="Batal"
                                                                >
                                                                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                </button>
                                                            </div>
                                                        ) : (
                                                            <div className="flex items-center gap-2 flex-1">
                                                                <span className="font-semibold text-slate-800">
                                                                    {formatTglHistori(riwayat.tanggal_pengambilan)}
                                                                </span>
                                                                {riwayat.no_faktur?.startsWith('REALISASI-') && (
                                                                    <button
                                                                        onClick={() => handleEditRiwayat(riwayat)}
                                                                        className="text-slate-400 hover:text-teal-600 transition-colors"
                                                                        title="Edit tanggal kedatangan"
                                                                    >
                                                                        <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                                                    </button>
                                                                )}
                                                                {bisaDihapus && (
                                                                    <button
                                                                        onClick={() => setHapusRiwayatId(riwayat.id)}
                                                                        className="text-slate-400 hover:text-rose-600 transition-colors"
                                                                        title="Hapus pengambilan ini (salah input)"
                                                                        aria-label="Hapus pengambilan ini"
                                                                    >
                                                                        <Trash2 className="w-3 h-3" />
                                                                    </button>
                                                                )}
                                                            </div>
                                                        )}
                                                        <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${badgeBg} shrink-0`}>
                                                            {riwayat.status_kepatuhan}
                                                        </span>
                                                    </div>
                                                    <div className="text-sm text-slate-500 space-y-0.5">
                                                        {riwayat.no_faktur && (
                                                            <div className="text-xs font-mono text-slate-600">
                                                                No. Faktur: <span className="font-semibold text-slate-700">{riwayat.no_faktur}</span>
                                                            </div>
                                                        )}
                                                        {riwayat.jadwal_target && (
                                                            <div className="text-xs text-slate-600">
                                                                Jadwal Ambil Obat: {formatTglHistori(riwayat.jadwal_target)}
                                                            </div>
                                                        )}
                                                        <div className={`text-xs font-medium ${selisihColor}`}>
                                                            {selisihText}
                                                        </div>
                                                    </div>
                                                    {hapusRiwayatId === riwayat.id && (
                                                        <div className="mt-3 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800">
                                                            <p className="font-semibold">Hapus pengambilan {formatTglHistori(riwayat.tanggal_pengambilan)}?</p>
                                                            <p className="mt-1">Jadwal berikutnya dihitung ulang.</p>
                                                            <div className="mt-2 flex justify-end gap-2">
                                                                <button
                                                                    onClick={() => setHapusRiwayatId(null)}
                                                                    disabled={menghapusRiwayat}
                                                                    className="px-3 py-1 rounded-md bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-50"
                                                                >
                                                                    Batal
                                                                </button>
                                                                <button
                                                                    onClick={() => handleHapusRiwayat(riwayat.id)}
                                                                    disabled={menghapusRiwayat}
                                                                    className="px-3 py-1 rounded-md bg-rose-600 text-white hover:bg-rose-700 disabled:opacity-50"
                                                                >
                                                                    {menghapusRiwayat ? 'Menghapus…' : 'Ya, hapus'}
                                                                </button>
                                                            </div>
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                                );
                                            });
                                        })()}
                                    </div>
                                ) : (
                                    <div className="text-center py-10">
                                        <History className="w-12 h-12 text-slate-300 mx-auto mb-3" />
                                        <p className="text-slate-500">
                                            {pasien.periode_penelitian
                                                ? `Tidak ada pengambilan obat pada periode penelitian (${pasien.periode_penelitian}).`
                                                : 'Belum ada riwayat pengambilan obat.'}
                                        </p>
                                    </div>
                                )}
                            </div>
                        )}

                        {activeTab === 'tindak_lanjut' && (
                            <div className="animate-fade-in-up">
                                <form onSubmit={handleTindakLanjutSubmit} className="bg-teal-50/50 p-4 rounded-xl border border-teal-100 mb-8">
                                    <h5 className="text-sm font-semibold text-teal-800 mb-4 flex items-center gap-2">
                                        <MessageSquarePlus className="w-4 h-4" />
                                        Catat Tindak Lanjut Baru
                                    </h5>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                                        <div>
                                            <label className="block text-xs font-medium text-slate-700 mb-1">Hasil Tindakan</label>
                                            <select
                                                value={tindakan}
                                                onChange={(e) => {
                                                    setTindakan(e.target.value);
                                                    // Kategori hanya untuk "Belum mengambil obat"
                                                    if (e.target.value !== 'Belum mengambil obat') setKategori('');
                                                }}
                                                required
                                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500"
                                            >
                                                <option value="">Pilih tindakan...</option>
                                                {TINDAKAN_OPTIONS.map(opt => <option key={opt} value={opt}>{opt}</option>)}
                                            </select>
                                        </div>

                                        {tindakan === 'Belum mengambil obat' && (
                                        <div>
                                            <label className="block text-xs font-medium text-slate-700 mb-1">Kategori Tindak Lanjut</label>
                                            <select
                                                value={kategori}
                                                onChange={(e) => setKategori(e.target.value)}
                                                required
                                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500"
                                            >
                                                <option value="">Pilih kategori...</option>
                                                {KATEGORI_TINDAK_LANJUT_OPTIONS.map(opt => <option key={opt} value={opt}>{opt}</option>)}
                                            </select>
                                            {kategori === 'Meninggal' && pasien.status !== 'Meninggal' && (
                                                <p className="text-xs text-rose-600 mt-1">Status pasien akan diubah menjadi Meninggal dan keluar dari pemantauan.</p>
                                            )}
                                        </div>
                                        )}

                                        {tindakan === 'Sudah mengambil obat' && (
                                            <div>
                                                <label className="block text-xs font-medium text-slate-700 mb-1">Tanggal Aktual Pengambilan</label>
                                                <input
                                                    type="date"
                                                    value={tanggalRealisasi}
                                                    onChange={(e) => setTanggalRealisasi(e.target.value)}
                                                    required
                                                    max={new Date().toLocaleDateString('en-CA')}
                                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500"
                                                />
                                            </div>
                                        )}
                                    </div>
                                    <div className="mb-4">
                                        <label className="block text-xs font-medium text-slate-700 mb-1">Catatan (Opsional)</label>
                                        <textarea
                                            value={catatan}
                                            onChange={(e) => setCatatan(e.target.value)}
                                            rows={2}
                                            className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500"
                                            placeholder="Tuliskan catatan percakapan atau keterangan tambahan..."
                                        />
                                    </div>
                                    <div className="flex justify-end">
                                        <Button type="submit" isLoading={submitting} icon={<Save className="w-4 h-4" />}>
                                            Simpan Tindak Lanjut
                                        </Button>
                                    </div>
                                </form>

                                <div>
                                    <h5 className="text-sm font-semibold text-slate-800 mb-4">Histori Tindak Lanjut</h5>
                                    {pasien.riwayat_tindak_lanjut && pasien.riwayat_tindak_lanjut.length > 0 ? (
                                        <div className="space-y-4">
                                            {pasien.riwayat_tindak_lanjut.map(tl => (
                                                <div key={tl.id} className="bg-white p-4 rounded-lg border border-slate-200 flex gap-4">
                                                    <div className="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center shrink-0">
                                                        <User className="w-5 h-5 text-slate-500" />
                                                    </div>
                                                    <div className="flex-1 min-w-0">
                                                        <div className="flex justify-between items-start mb-1">
                                                            <p className="font-semibold text-slate-800 text-sm">{tl.petugas}</p>
                                                            <span className="text-xs text-slate-500">{new Date(tl.tanggal_jam).toLocaleString('id-ID')}</span>
                                                        </div>
                                                        <div className="flex flex-wrap gap-1.5 mb-2">
                                                            <span className="inline-block bg-slate-100 text-slate-700 text-xs px-2 py-1 rounded font-medium">
                                                                {tl.tindakan}
                                                            </span>
                                                            {tl.kategori && (
                                                                <span className="inline-block bg-teal-50 text-teal-700 border border-teal-100 text-xs px-2 py-1 rounded font-medium">
                                                                    {tl.kategori}
                                                                </span>
                                                            )}
                                                        </div>
                                                        {tl.catatan && (
                                                            <p className="text-sm text-slate-600">{tl.catatan}</p>
                                                        )}
                                                        {tl.update_realisasi_otomatis && (
                                                            <p className="text-xs text-emerald-600 mt-2 flex items-center gap-1">
                                                                <Save className="w-3 h-3" />
                                                                Otomatis memperbarui jadwal siklus berikutnya.
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="text-center py-6 text-slate-500 text-sm border border-dashed border-slate-300 rounded-lg">
                                            Belum ada histori tindak lanjut.
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </Modal>
    );
}

/**
 * Kartu pengingat ambil obat lewat WhatsApp. Pesan awal mengikuti tahap Early
 * Warning dari jadwal berikutnya dan bisa diubah petugas sebelum dikirim;
 * tombol membuka WhatsApp, petugas menekan Kirim.
 */
function PengingatWhatsapp({ pasien }: { pasien: PasienPRB }) {
    const jadwal = pasien.tanggal_jadwal_berikutnya;
    const [lihatPesan, setLihatPesan] = useState(false);
    const [pesanUbahan, setPesanUbahan] = useState<string | null>(null); // null = pakai pesan awal
    const [histori, setHistori] = useState<RiwayatPengingatWa[]>(pasien.riwayat_pengingat_wa ?? []);
    const [lihatSemua, setLihatSemua] = useState(false);

    let alasanTidakBisa: string | null = null;
    if (pasien.status !== 'Aktif') alasanTidakBisa = `Pasien ${pasien.status} tidak perlu diingatkan.`;
    else if (!jadwal) alasanTidakBisa = 'Pasien belum punya jadwal ambil obat.';
    else if (!pasien.no_whatsapp) alasanTidakBisa = 'Isi No. WhatsApp lewat Ubah Data untuk mengirim pengingat.';

    const pesanAwal = !alasanTidakBisa && jadwal ? pesanPengingat(jadwal, pasien.fktp_asal, new Date(), pasien.nama_samaran) : '';
    const pesan = pesanUbahan ?? pesanAwal;
    const tahap = jadwal ? LABEL_TAHAP[tahapPengingat(jadwal)] : null;

    // Buka WhatsApp, lalu catat pengiriman di histori (tidak menghalangi pengiriman)
    const kirim = () => {
        window.open(tautanWhatsapp(pasien.no_whatsapp!, pesan), '_blank', 'noopener');
        fetch(`/pasien/${pasien.id}/pengingat-wa`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
            body: JSON.stringify({ tahap, jadwal_target: jadwal }),
        })
            .then(res => (res.ok ? res.json() : null))
            .then((data: RiwayatPengingatWa[] | null) => { if (data) setHistori(data); })
            .catch(() => {});
    };
    const fmtWaktu = (iso: string) => new Date(iso).toLocaleString('id-ID', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
    const historiTampil = lihatSemua ? histori : histori.slice(0, 3);

    return (
        <div className="mt-4 bg-emerald-50/60 p-4 rounded-xl border border-emerald-100">
            <div className="flex items-center justify-between gap-2 mb-2">
                <h5 className="text-sm font-semibold text-emerald-800 flex items-center gap-2">
                    <Send className="w-4 h-4" />
                    Pengingat WhatsApp
                </h5>
                {tahap && pasien.status === 'Aktif' && (
                    <span className="text-[10px] font-semibold uppercase tracking-wide text-emerald-700 bg-white border border-emerald-200 rounded-full px-2 py-0.5">
                        {tahap}
                    </span>
                )}
            </div>

            {alasanTidakBisa ? (
                <p className="text-xs text-slate-500">{alasanTidakBisa}</p>
            ) : (
                <>
                    <button
                        type="button"
                        onClick={kirim}
                        disabled={!pesan.trim()}
                        className="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium px-3 py-2 transition-colors"
                    >
                        <Send className="w-4 h-4" />
                        Kirim Pengingat WA
                    </button>
                    <button
                        type="button"
                        onClick={() => setLihatPesan(v => !v)}
                        className="mt-2 text-xs text-emerald-700 hover:text-emerald-900 underline underline-offset-2"
                    >
                        {lihatPesan ? 'Sembunyikan isi pesan' : 'Lihat / ubah isi pesan'}
                    </button>
                    {lihatPesan && (
                        <div className="mt-2">
                            <textarea
                                value={pesan}
                                onChange={(e) => setPesanUbahan(e.target.value)}
                                rows={9}
                                aria-label="Isi pesan pengingat WhatsApp"
                                className="w-full text-xs text-slate-700 bg-white border border-emerald-200 rounded-lg p-3 focus:border-emerald-500 focus:ring-emerald-500"
                            />
                            <div className="flex items-center justify-between gap-2 mt-1">
                                <span className="text-[11px] text-slate-500">
                                    {pesanUbahan !== null ? 'Pesan sudah diubah — yang dikirim pesan ini.' : 'Pesan bisa diubah sebelum dikirim.'}
                                </span>
                                {pesanUbahan !== null && (
                                    <button
                                        type="button"
                                        onClick={() => setPesanUbahan(null)}
                                        className="text-[11px] text-emerald-700 hover:text-emerald-900 underline underline-offset-2 shrink-0"
                                    >
                                        Kembalikan pesan awal
                                    </button>
                                )}
                            </div>
                        </div>
                    )}
                </>
            )}

            {/* Histori pengiriman WhatsApp */}
            <div className="mt-3 pt-3 border-t border-emerald-100">
                <div className="text-[11px] font-semibold uppercase tracking-wide text-emerald-800 mb-1.5">
                    Histori pengiriman ({histori.length})
                </div>
                {histori.length === 0 ? (
                    <p className="text-xs text-slate-500">Belum ada pengingat yang dikirim.</p>
                ) : (
                    <ul className="space-y-1.5">
                        {historiTampil.map(h => (
                            <li key={h.id} className="text-xs text-slate-600 bg-white/70 border border-emerald-100 rounded-md px-2 py-1.5">
                                <span className="font-medium text-slate-700">{fmtWaktu(h.dikirim_pada)}</span>
                                {' · '}{h.petugas}
                                {h.tahap && <span className="block text-[11px] text-slate-500">{h.tahap}{h.jadwal_target ? ` — jadwal ${h.jadwal_target.slice(0, 10).split('-').reverse().join('/')}` : ''}</span>}
                            </li>
                        ))}
                    </ul>
                )}
                {histori.length > 3 && (
                    <button
                        type="button"
                        onClick={() => setLihatSemua(v => !v)}
                        className="mt-1.5 text-[11px] text-emerald-700 hover:text-emerald-900 underline underline-offset-2"
                    >
                        {lihatSemua ? 'Tampilkan lebih sedikit' : `Lihat semua (${histori.length})`}
                    </button>
                )}
            </div>
        </div>
    );
}
