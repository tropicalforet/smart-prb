import { useState, useEffect } from 'react';
import { PasienPRB, TINDAKAN_OPTIONS } from '@/types';
import { Modal } from '../ui/Modal';
import { StatusBadge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { router } from '@inertiajs/react';
import {
    User, IdCard, FileText, Activity, Calendar,
    History, MessageSquarePlus, Stethoscope, Save
} from 'lucide-react';

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
}

export function PatientDetailModal({ pasienId, isOpen, onClose, tanggalEvaluasi, filterPeriode }: Props) {
    const [pasien, setPasien] = useState<PasienPRB | null>(null);
    const [loading, setLoading] = useState(false);
    const [activeTab, setActiveTab] = useState<'profil' | 'riwayat' | 'tindak_lanjut'>('profil');

    // Form state — tindak lanjut
    const [tindakan, setTindakan] = useState('');
    const [catatan, setCatatan] = useState('');
    const [tanggalRealisasi, setTanggalRealisasi] = useState(new Date().toISOString().split('T')[0]);
    const [submitting, setSubmitting] = useState(false);

    // State edit histori per riwayat
    const [editingRiwayatId, setEditingRiwayatId] = useState<string | null>(null);
    const [editingRiwayatTanggal, setEditingRiwayatTanggal] = useState('');
    const [savingRiwayat, setSavingRiwayat] = useState(false);

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
        }
    }, [isOpen, pasienId, tanggalEvaluasi, filterPeriode]);

    const handleTindakLanjutSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);

        const data: any = { tindakan, catatan };
        if (tindakan === 'Sudah mengambil obat') {
            data.tanggal_realisasi = tanggalRealisasi;
        }

        router.post(`/pasien/${pasienId}/tindak-lanjut`, data, {
            preserveScroll: true,
            onSuccess: () => {
                setSubmitting(false);
                setTindakan('');
                setCatatan('');
                refreshPasien();
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

    return (
        <Modal isOpen={isOpen} onClose={onClose} title={`Detail: ${pasien.kode_pasien}`} maxWidth="4xl">
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
                        <p className="text-center text-slate-500 text-sm mb-4 font-mono">
                            NOKA {pasien.noka}
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
                                    {(() => {
                                        let diag = pasien.diagnosis_prb;
                                        if (typeof diag === 'string') {
                                            try {
                                                const parsed = JSON.parse(diag);
                                                if (Array.isArray(parsed)) diag = parsed;
                                            } catch (e) {}
                                        }
                                        return Array.isArray(diag) && diag.length > 0 
                                            ? diag.join(', ') 
                                            : (diag || '-');
                                    })()}
                                </span>
                            </div>
                            <div className="flex items-center gap-3 text-slate-600">
                                <Activity className="w-4 h-4 text-slate-400 shrink-0" />
                                <span>{pasien.fktp_asal}</span>
                            </div>
                        </div>

                    </div>
                </div>

                {/* Right Column: Tabs */}
                <div className="lg:w-2/3">
                    <div className="border-b border-slate-200">
                        <nav className="-mb-px flex space-x-6">
                            <button
                                onClick={() => setActiveTab('profil')}
                                className={`whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm transition-colors ${activeTab === 'profil' ? 'border-teal-500 text-teal-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'}`}
                            >
                                Info Klinis & Jadwal
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
                                    <h5 className="text-sm font-semibold text-slate-800 mb-3 flex items-center gap-2">
                                        <FileText className="w-4 h-4 text-teal-600" />
                                        Informasi Program
                                    </h5>
                                    <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-4">
                                        <div>
                                            <dt className="text-xs font-medium text-slate-500">Mulai PRB</dt>
                                            <dd className="mt-1 text-sm text-slate-900">{formatTglHistori(pasien.tanggal_mulai_prb)}</dd>
                                        </div>
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
                                             return unique.map((riwayat) => {
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
                                                        ? 'text-amber-600'   // lebih awal (tidak patuh)
                                                        : 'text-rose-600';   // terlambat (tidak patuh)

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
                                                                    className="rounded border-slate-300 text-xs py-1 px-2 focus:ring-teal-500 focus:border-teal-500 w-[130px]"
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
                                                </div>
                                            </div>
                                                );
                                            });
                                        })()}
                                    </div>
                                ) : (
                                    <div className="text-center py-10">
                                        <History className="w-12 h-12 text-slate-300 mx-auto mb-3" />
                                        <p className="text-slate-500">Belum ada riwayat pengambilan obat.</p>
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
                                                onChange={(e) => setTindakan(e.target.value)}
                                                required
                                                className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                                            >
                                                <option value="">Pilih tindakan...</option>
                                                {TINDAKAN_OPTIONS.map(opt => <option key={opt} value={opt}>{opt}</option>)}
                                            </select>
                                        </div>

                                        {tindakan === 'Sudah mengambil obat' && (
                                            <div>
                                                <label className="block text-xs font-medium text-slate-700 mb-1">Tanggal Aktual Pengambilan</label>
                                                <input
                                                    type="date"
                                                    value={tanggalRealisasi}
                                                    onChange={(e) => setTanggalRealisasi(e.target.value)}
                                                    required
                                                    className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
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
                                            className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
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
                                                        <span className="inline-block bg-slate-100 text-slate-700 text-xs px-2 py-1 rounded font-medium mb-2">
                                                            {tl.tindakan}
                                                        </span>
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
