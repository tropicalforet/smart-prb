import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { useState, useCallback } from 'react';
import {
    Bell, Download, Copy, CheckCircle2, AlertCircle, X,
    ChevronDown, ChevronUp, MessageSquarePlus, Send, Users,
    Loader2, TriangleAlert,
} from 'lucide-react';
import { Button } from '@/Components/ui/Button';
import { motion, AnimatePresence } from 'motion/react';
import { generateWhatsAppLink, getReminderTemplate } from '@/lib/whatsapp-templates';

// ── Types ──────────────────────────────────────────────────────────────────

interface TindakLanjutEntry {
    id: string;
    tanggal_jam: string;
    petugas: string;
    tindakan: string;
    catatan: string | null;
}

interface PasienReminder {
    id: string;
    kode_pasien: string;
    nama_pasien: string;
    no_whatsapp: string | null;
    fktp_asal: string;
    jadwal: string | null;
    early_warning: string;
    status_kepatuhan: string;
    kedatangan: string | null;
    tindak_lanjut: TindakLanjutEntry[];
}

interface BlastDetail {
    id: string;
    nama_pasien: string;
    no_whatsapp: string;
    status: 'berhasil' | 'gagal' | 'invalid';
    keterangan: string;
}

interface BlastRingkasan {
    dipilih: number;
    berhasil: number;
    gagal: number;
    invalid: number;
}

interface Props {
    pasienReminder: PasienReminder[];
    periodeOptions: { value: string; label: string }[];
    fktpOptions: string[];
    kategoriOptions: { value: string; label: string }[];
    tindakanOptions: string[];
    filters: {
        filter_periode: string;
        filter_kategori: string;
        filter_fktp: string;
        tanggal_evaluasi: string;
    };
}

// ── Helpers ────────────────────────────────────────────────────────────────

function fmt(tgl?: string | null): string {
    if (!tgl) return '-';
    const d = new Date(tgl);
    if (isNaN(d.getTime())) return '-';
    return `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}/${d.getFullYear()}`;
}

/** Validasi nomor WA di sisi frontend (cermin validasi backend) */
function isValidWa(no: string | null | undefined): boolean {
    if (!no) return false;
    const digits = no.replace(/[^0-9]/g, '');
    return digits.length >= 9 && digits.length <= 15;
}

// ── Sub-komponen: Form Tindak Lanjut per pasien ────────────────────────────

function FormTindakLanjut({
    pasienId,
    tindakanOptions,
    onSaved,
    onCancel,
}: {
    pasienId: string;
    tindakanOptions: string[];
    onSaved: () => void;
    onCancel: () => void;
}) {
    const [tindakan, setTindakan]           = useState('');
    const [catatan, setCatatan]             = useState('');
    const [petugas, setPetugas]             = useState('');
    const [tanggalRealisasi, setTglRealisasi] = useState(
        new Date().toISOString().split('T')[0]
    );
    const [submitting, setSubmitting] = useState(false);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!tindakan) return;
        setSubmitting(true);
        const data: Record<string, string> = { tindakan, catatan, petugas };
        if (tindakan === 'Sudah mengambil obat') {
            data.tanggal_realisasi = tanggalRealisasi;
        }
        router.post(`/pasien/${pasienId}/tindak-lanjut`, data, {
            preserveScroll: true,
            onSuccess: () => { setSubmitting(false); onSaved(); },
            onError:   () => setSubmitting(false),
        });
    };

    return (
        <form onSubmit={handleSubmit} className="bg-teal-50/60 border border-teal-100 rounded-xl p-4 mt-2">
            <h5 className="text-xs font-semibold text-teal-800 mb-3 flex items-center gap-1.5">
                <MessageSquarePlus className="w-3.5 h-3.5" /> Catat Tindak Lanjut
            </h5>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                <div>
                    <label className="block text-xs font-medium text-slate-600 mb-1">Hasil Kontak *</label>
                    <select value={tindakan} onChange={e => setTindakan(e.target.value)} required
                        className="w-full rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500 py-1.5">
                        <option value="">Pilih hasil...</option>
                        {tindakanOptions.map(opt => <option key={opt} value={opt}>{opt}</option>)}
                    </select>
                </div>
                {tindakan === 'Sudah mengambil obat' && (
                    <div>
                        <label className="block text-xs font-medium text-slate-600 mb-1">Tanggal Pengambilan *</label>
                        <input type="date" value={tanggalRealisasi} onChange={e => setTglRealisasi(e.target.value)} required
                            className="w-full rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500 py-1.5" />
                    </div>
                )}
                <div>
                    <label className="block text-xs font-medium text-slate-600 mb-1">Nama Petugas</label>
                    <input type="text" value={petugas} onChange={e => setPetugas(e.target.value)}
                        placeholder="Nama petugas yang menghubungi"
                        className="w-full rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500 py-1.5" />
                </div>
            </div>
            <div className="mb-3">
                <label className="block text-xs font-medium text-slate-600 mb-1">Catatan</label>
                <textarea value={catatan} onChange={e => setCatatan(e.target.value)} rows={2}
                    placeholder="Hasil percakapan, alasan tidak mengambil, keterangan lain..."
                    className="w-full rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500" />
            </div>
            <div className="flex justify-end gap-2">
                <button type="button" onClick={onCancel}
                    className="text-xs px-3 py-1.5 rounded-lg bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors">
                    Batal
                </button>
                <button type="submit" disabled={!tindakan || submitting}
                    className="text-xs px-3 py-1.5 rounded-lg bg-teal-600 text-white hover:bg-teal-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-1.5">
                    {submitting ? 'Menyimpan...' : <><CheckCircle2 className="w-3.5 h-3.5" /> Simpan</>}
                </button>
            </div>
        </form>
    );
}

// ── Sub-komponen: Icon WhatsApp ────────────────────────────────────────────

function WaIcon({ className = 'w-4 h-4' }: { className?: string }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="currentColor">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
        </svg>
    );
}

// ── Modal Konfirmasi Blast ─────────────────────────────────────────────────

function ModalKonfirmasi({
    selectedPasien,
    onConfirm,
    onCancel,
}: {
    selectedPasien: PasienReminder[];
    onConfirm: (petugas: string) => void;
    onCancel: () => void;
}) {
    const [petugas, setPetugas] = useState('');
    const valid   = selectedPasien.filter(p => isValidWa(p.no_whatsapp));
    const invalid = selectedPasien.filter(p => !isValidWa(p.no_whatsapp));

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm">
            <motion.div
                initial={{ scale: 0.95, opacity: 0 }}
                animate={{ scale: 1,    opacity: 1 }}
                exit={{   scale: 0.95, opacity: 0 }}
                transition={{ duration: 0.15 }}
                className="bg-white rounded-2xl shadow-2xl w-full max-w-md"
            >
                <div className="p-6">
                    <div className="flex items-center gap-3 mb-5">
                        <div className="w-10 h-10 rounded-xl bg-emerald-100 flex items-center justify-center">
                            <WaIcon className="w-5 h-5 text-emerald-600" />
                        </div>
                        <div>
                            <h3 className="font-bold text-slate-800">Konfirmasi Kirim Reminder</h3>
                            <p className="text-xs text-slate-500">Periksa ringkasan sebelum melanjutkan</p>
                        </div>
                    </div>

                    {/* Ringkasan validasi */}
                    <div className="space-y-2 mb-5">
                        <div className="flex items-center justify-between bg-slate-50 rounded-lg px-4 py-2.5 text-sm">
                            <span className="text-slate-600 flex items-center gap-2"><Users className="w-4 h-4" /> Dipilih</span>
                            <span className="font-bold text-slate-800">{selectedPasien.length} pasien</span>
                        </div>
                        <div className="flex items-center justify-between bg-emerald-50 rounded-lg px-4 py-2.5 text-sm">
                            <span className="text-emerald-700 flex items-center gap-2"><CheckCircle2 className="w-4 h-4" /> Nomor valid</span>
                            <span className="font-bold text-emerald-700">{valid.length} pasien</span>
                        </div>
                        {invalid.length > 0 && (
                            <div className="flex items-center justify-between bg-amber-50 rounded-lg px-4 py-2.5 text-sm">
                                <span className="text-amber-700 flex items-center gap-2"><TriangleAlert className="w-4 h-4" /> Nomor tidak valid</span>
                                <span className="font-bold text-amber-700">{invalid.length} pasien</span>
                            </div>
                        )}
                    </div>

                    {/* Daftar pasien invalid (jika ada) */}
                    {invalid.length > 0 && (
                        <div className="mb-5 bg-amber-50/80 border border-amber-100 rounded-xl p-3">
                            <p className="text-xs font-semibold text-amber-700 mb-2">Pasien tanpa nomor valid (tidak akan dikirimi):</p>
                            <ul className="space-y-1">
                                {invalid.map(p => (
                                    <li key={p.id} className="text-xs text-amber-800 flex items-center gap-1.5">
                                        <AlertCircle className="w-3 h-3 shrink-0" />
                                        {p.nama_pasien} —{' '}
                                        <span className="font-mono">{p.no_whatsapp || 'tidak ada nomor'}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {valid.length === 0 && (
                        <div className="mb-5 bg-rose-50 border border-rose-100 rounded-xl p-3 text-sm text-rose-700 text-center">
                            Tidak ada pasien dengan nomor WhatsApp valid. Tidak ada yang dapat dikirimi.
                        </div>
                    )}

                    {/* Nama petugas */}
                    {valid.length > 0 && (
                        <div className="mb-5">
                            <label className="block text-xs font-medium text-slate-600 mb-1.5">
                                Nama Petugas <span className="text-slate-400">(opsional)</span>
                            </label>
                            <input
                                type="text"
                                value={petugas}
                                onChange={e => setPetugas(e.target.value)}
                                placeholder="Nama petugas yang mengirim reminder"
                                className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                            />
                        </div>
                    )}

                    <div className="flex gap-3 justify-end">
                        <Button variant="ghost" onClick={onCancel}>Batal</Button>
                        {valid.length > 0 && (
                            <Button
                                onClick={() => onConfirm(petugas)}
                                className="bg-emerald-600 text-white border-emerald-700 hover:bg-emerald-700"
                                icon={<WaIcon className="w-4 h-4" />}
                            >
                                Kirim ke {valid.length} Pasien
                            </Button>
                        )}
                    </div>
                </div>
            </motion.div>
        </div>
    );
}

// ── Modal Ringkasan Hasil ──────────────────────────────────────────────────

function ModalRingkasan({
    ringkasan,
    detail,
    onClose,
}: {
    ringkasan: BlastRingkasan;
    detail: BlastDetail[];
    onClose: () => void;
}) {
    const [showDetail, setShowDetail] = useState(false);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm">
            <motion.div
                initial={{ scale: 0.95, opacity: 0 }}
                animate={{ scale: 1,    opacity: 1 }}
                exit={{   scale: 0.95, opacity: 0 }}
                transition={{ duration: 0.15 }}
                className="bg-white rounded-2xl shadow-2xl w-full max-w-lg"
            >
                <div className="p-6">
                    {/* Header */}
                    <div className="flex items-center justify-between mb-5">
                        <div className="flex items-center gap-3">
                            <div className="w-10 h-10 rounded-xl bg-teal-100 flex items-center justify-center">
                                <CheckCircle2 className="w-5 h-5 text-teal-600" />
                            </div>
                            <div>
                                <h3 className="font-bold text-slate-800">Reminder Selesai</h3>
                                <p className="text-xs text-slate-500">Ringkasan hasil pengiriman</p>
                            </div>
                        </div>
                        <button onClick={onClose} className="text-slate-400 hover:text-slate-600 transition-colors">
                            <X className="w-5 h-5" />
                        </button>
                    </div>

                    {/* Kartu ringkasan */}
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
                        {[
                            { label: 'Dipilih',  value: ringkasan.dipilih,  color: 'bg-slate-100 text-slate-700' },
                            { label: 'Berhasil', value: ringkasan.berhasil, color: 'bg-emerald-100 text-emerald-700' },
                            { label: 'Gagal',    value: ringkasan.gagal,    color: 'bg-rose-100 text-rose-700' },
                            { label: 'Tdk Valid',value: ringkasan.invalid,  color: 'bg-amber-100 text-amber-700' },
                        ].map(c => (
                            <div key={c.label} className={`${c.color} rounded-xl p-3 text-center`}>
                                <div className="text-2xl font-bold">{c.value}</div>
                                <div className="text-xs font-medium mt-0.5">{c.label}</div>
                            </div>
                        ))}
                    </div>

                    {/* Catatan */}
                    <p className="text-xs text-slate-500 mb-4 bg-slate-50 rounded-lg px-3 py-2">
                        Setiap pasien berstatus Berhasil tercatat "Reminder WhatsApp dibuka" di histori tindak lanjut.
                        WhatsApp dibuka untuk setiap pasien dengan nomor valid — petugas tetap mengirim pesan secara manual.
                    </p>

                    {/* Tombol detail */}
                    <button
                        onClick={() => setShowDetail(!showDetail)}
                        className="flex items-center gap-1.5 text-xs text-slate-500 hover:text-slate-700 mb-4 transition-colors"
                    >
                        {showDetail ? <ChevronUp className="w-3.5 h-3.5" /> : <ChevronDown className="w-3.5 h-3.5" />}
                        {showDetail ? 'Sembunyikan detail' : 'Lihat detail per pasien'}
                    </button>

                    {showDetail && (
                        <div className="max-h-48 overflow-y-auto border border-slate-100 rounded-xl mb-4">
                            <table className="w-full text-xs">
                                <thead className="bg-slate-50 sticky top-0">
                                    <tr>
                                        <th className="px-3 py-2 text-left font-semibold text-slate-500">Pasien</th>
                                        <th className="px-3 py-2 text-left font-semibold text-slate-500">No. WA</th>
                                        <th className="px-3 py-2 text-left font-semibold text-slate-500">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {detail.map(d => (
                                        <tr key={d.id} className="hover:bg-slate-50">
                                            <td className="px-3 py-2 text-slate-800">{d.nama_pasien}</td>
                                            <td className="px-3 py-2 font-mono text-slate-600">{d.no_whatsapp}</td>
                                            <td className="px-3 py-2">
                                                <span className={`px-2 py-0.5 rounded-full text-xs font-semibold ${
                                                    d.status === 'berhasil'
                                                        ? 'bg-emerald-100 text-emerald-700'
                                                        : d.status === 'gagal'
                                                            ? 'bg-rose-100 text-rose-700'
                                                            : 'bg-amber-100 text-amber-700'
                                                }`}>
                                                    {d.status}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <div className="flex justify-end">
                        <Button onClick={onClose}>Tutup</Button>
                    </div>
                </div>
            </motion.div>
        </div>
    );
}

// ── Komponen Utama ─────────────────────────────────────────────────────────

export default function ReminderIndex({
    pasienReminder,
    periodeOptions,
    fktpOptions,
    kategoriOptions,
    tindakanOptions,
    filters,
}: Props) {
    const [periode, setPeriode]   = useState(filters.filter_periode);
    const [kategori, setKategori] = useState(filters.filter_kategori);
    const [fktp, setFktp]         = useState(filters.filter_fktp ?? '');

    // Checkbox
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [copied, setCopied]     = useState<string | null>(null);

    // Form tindak lanjut per pasien
    const [formOpen, setFormOpen]       = useState<string | null>(null);
    const [historiOpen, setHistoriOpen] = useState<Set<string>>(new Set());

    // State blast
    const [showKonfirmasi, setShowKonfirmasi] = useState(false);
    const [blasting, setBlasting]             = useState(false);
    const [blastProgress, setBlastProgress]   = useState(0);   // 0–100
    const [blastDone, setBlastDone]           = useState<{ ringkasan: BlastRingkasan; detail: BlastDetail[] } | null>(null);

    // ── Filter ke backend ──────────────────────────────────────────────
    const applyFilters = useCallback((p: string, k: string, f: string) => {
        router.get('/reminder', { filter_periode: p, filter_kategori: k, filter_fktp: f },
            { preserveState: true, preserveScroll: true });
    }, []);

    const handlePeriodeChange  = (v: string) => { setPeriode(v);  applyFilters(v, kategori, fktp); resetSelection(); };
    const handleKategoriChange = (v: string) => { setKategori(v); applyFilters(periode, v, fktp);  resetSelection(); };
    const handleFktpChange     = (v: string) => { setFktp(v);     applyFilters(periode, kategori, v); resetSelection(); };

    const resetSelection = () => { setSelected(new Set()); setFormOpen(null); };

    // ── Checkbox ───────────────────────────────────────────────────────
    const toggleAll = () => {
        if (selected.size === pasienReminder.length) setSelected(new Set());
        else setSelected(new Set(pasienReminder.map(p => p.id)));
    };
    const toggleOne = (id: string) => {
        setSelected(prev => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };
    const toggleHistori = (id: string) => {
        setHistoriOpen(prev => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    const selectedPasien    = pasienReminder.filter(p => selected.has(p.id));
    const validForWa        = selectedPasien.filter(p => isValidWa(p.no_whatsapp));
    const sudahDipilih      = selected.size > 0;
    const semuaDipilih      = selected.size === pasienReminder.length && pasienReminder.length > 0;
    const sebagianDipilih   = selected.size > 0 && !semuaDipilih;

    // ── Salin pesan satu pasien ────────────────────────────────────────
    const handleCopyOne = (pasien: PasienReminder) => {
        const msg = getReminderTemplate(pasien.nama_pasien, pasien.jadwal);
        navigator.clipboard.writeText(msg).then(() => {
            setCopied(pasien.id);
            setTimeout(() => setCopied(null), 2000);
        });
    };

    // ── Buka WA satu pasien ────────────────────────────────────────────
    const handleWaOne = (pasien: PasienReminder) => {
        if (!pasien.no_whatsapp) { alert('Nomor WhatsApp tidak tersedia untuk pasien ini.'); return; }
        const msg = getReminderTemplate(pasien.nama_pasien, pasien.jadwal);
        window.open(generateWhatsAppLink(pasien.no_whatsapp, msg), '_blank');
    };

    // ── BLAST: mulai setelah konfirmasi ────────────────────────────────
    const handleBlastConfirm = async (petugasInput: string) => {
        setShowKonfirmasi(false);
        setBlasting(true);
        setBlastProgress(0);

        // CSRF: utamakan cookie XSRF-TOKEN (selalu diperbarui Laravel di tiap
        // response, termasuk setelah login/logout via Inertia). Meta tag hanya
        // dirender saat full page load, jadi bisa basi setelah session regenerate.
        const xsrfCookie = document.cookie
            .split('; ')
            .find(c => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1];
        const csrfHeaders: Record<string, string> = xsrfCookie
            ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie) }
            : { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '' };

        try {
            // Panggil endpoint blast (JSON request)
            const response = await fetch('/reminder/blast', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    ...csrfHeaders,
                },
                body: JSON.stringify({
                    pasien_ids:      Array.from(selected),
                    petugas:         petugasInput,
                    filter_periode:  periode,
                    filter_kategori: kategori,
                }),
            });

            if (!response.ok) {
                setBlasting(false);
                const pesan = response.status === 419
                    ? 'Sesi Anda sudah kedaluwarsa. Silakan muat ulang halaman lalu coba lagi.'
                    : `Gagal mengirim reminder (kode ${response.status}). Silakan coba lagi.`;
                alert(pesan);
                return;
            }

            const json = await response.json();

            // Setelah data tersimpan: buka WA satu per satu dengan jeda
            // untuk pasien yang valid (berurutan, agar browser tidak blok popup)
            const validDetail: BlastDetail[] = (json.detail ?? []).filter(
                (d: BlastDetail) => d.status === 'berhasil'
            );

            const totalBuka = validDetail.length;
            for (let i = 0; i < totalBuka; i++) {
                const pasienData = pasienReminder.find(p => p.id === validDetail[i].id);
                if (pasienData?.no_whatsapp) {
                    const msg = getReminderTemplate(pasienData.nama_pasien, pasienData.jadwal);
                    setTimeout(() => {
                        window.open(generateWhatsAppLink(pasienData.no_whatsapp!, msg), '_blank');
                    }, i * 700);
                }
                // Update progress bar
                setBlastProgress(Math.round(((i + 1) / totalBuka) * 100));
                await new Promise(r => setTimeout(r, 100));
            }

            // Selesai — tampilkan ringkasan
            setBlasting(false);
            setBlastProgress(100);
            setBlastDone({ ringkasan: json.ringkasan, detail: json.detail ?? [] });

        } catch (err) {
            setBlasting(false);
            alert('Terjadi kesalahan saat mengirim reminder. Silakan coba lagi.');
        }
    };

    // ── Download CSV ───────────────────────────────────────────────────
    const handleDownload = () => {
        const list = selectedPasien.length > 0 ? selectedPasien : pasienReminder;
        if (list.length === 0) return;
        const header = ['Nama Pasien', 'No. WhatsApp', 'Kode Pasien', 'FKTP', 'Jadwal Pengambilan', 'Pesan Reminder'];
        const rows = list.map(p => [
            p.nama_pasien, p.no_whatsapp ?? '-', p.kode_pasien, p.fktp_asal,
            fmt(p.jadwal), getReminderTemplate(p.nama_pasien, p.jadwal),
        ]);
        const csv = [header, ...rows]
            .map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(','))
            .join('\n');
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        const label = periodeOptions.find(o => o.value === periode)?.label ?? periode;
        a.download = `Reminder_PRB_${label.replace(/\s/g,'_')}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    };

    // ═══════════════════════════════════════════════════════════════════
    return (
        <AppLayout title="Reminder WhatsApp PRB">
            <Head title="Reminder WhatsApp PRB" />

            {/* ── Header ─────────────────────────────────────────────── */}
            <div className="flex items-center gap-3 mb-6">
                <div className="w-10 h-10 rounded-xl bg-teal-600 flex items-center justify-center">
                    <Bell className="w-5 h-5 text-white" />
                </div>
                <div>
                    <h1 className="text-xl font-bold text-slate-800">Reminder Pengambilan Obat</h1>
                    <p className="text-sm text-slate-500">
                        Filter pasien → pilih → kirim reminder WhatsApp → catat tindak lanjut.
                    </p>
                    <p className="text-xs text-amber-600 mt-1 flex items-center gap-1">
                        <AlertCircle className="w-3.5 h-3.5" />
                        Hanya menampilkan pasien yang memiliki nomor WhatsApp terdaftar
                    </p>
                </div>
            </div>

            {/* ── Filter ─────────────────────────────────────────────── */}
            <div className="bg-white border border-slate-200 rounded-2xl shadow-sm p-5 mb-6">
                <h2 className="text-sm font-semibold text-slate-700 mb-4">Filter Reminder</h2>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label className="block text-xs font-medium text-slate-600 mb-1.5">Periode Jadwal</label>
                        <select value={periode} onChange={e => handlePeriodeChange(e.target.value)}
                            className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">-- Pilih Periode --</option>
                            {periodeOptions.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600 mb-1.5">FKTP</label>
                        <select value={fktp} onChange={e => handleFktpChange(e.target.value)}
                            className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua FKTP</option>
                            {fktpOptions.map(f => <option key={f} value={f}>{f}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600 mb-1.5">Kategori Reminder</label>
                        <select value={kategori} onChange={e => handleKategoriChange(e.target.value)}
                            className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">-- Pilih Kategori --</option>
                            {kategoriOptions.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                        </select>
                    </div>
                </div>
            </div>

            {/* ── Belum memilih ──────────────────────────────────────── */}
            {(!periode || !kategori) && (
                <div className="bg-blue-50 border border-blue-200 rounded-2xl p-8 text-center text-blue-700">
                    <Bell className="w-10 h-10 mx-auto mb-3 opacity-50" />
                    <p className="font-medium">Pilih periode dan kategori untuk menampilkan pasien.</p>
                    <p className="text-sm mt-1 opacity-70">Sistem akan menampilkan daftar pasien yang perlu diingatkan.</p>
                </div>
            )}

            {/* ── Tidak ada pasien ───────────────────────────────────── */}
            {periode && kategori && pasienReminder.length === 0 && (
                <div className="bg-slate-50 border border-slate-200 rounded-2xl p-8 text-center text-slate-500">
                    <CheckCircle2 className="w-10 h-10 mx-auto mb-3 text-emerald-400" />
                    <p className="font-medium">Tidak ada pasien yang perlu diingatkan pada filter ini.</p>
                </div>
            )}

            {/* ── Progress bar saat blast ─────────────────────────────── */}
            {blasting && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm">
                    <div className="bg-white rounded-2xl shadow-2xl p-8 w-full max-w-sm text-center">
                        <Loader2 className="w-10 h-10 text-teal-500 mx-auto mb-4 animate-spin" />
                        <p className="font-semibold text-slate-800 mb-1">Membuka WhatsApp...</p>
                        <p className="text-sm text-slate-500 mb-4">Membuka WhatsApp satu per satu. Jangan tutup halaman ini.</p>
                        <div className="w-full bg-slate-100 rounded-full h-2.5">
                            <div
                                className="bg-teal-500 h-2.5 rounded-full transition-all duration-300"
                                style={{ width: `${blastProgress}%` }}
                            />
                        </div>
                        <p className="text-xs text-slate-400 mt-2">{blastProgress}%</p>
                    </div>
                </div>
            )}

            {/* ── Daftar pasien ──────────────────────────────────────── */}
            {pasienReminder.length > 0 && (
                <>
                    {/* Toolbar */}
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                        <div className="flex items-center gap-3 flex-wrap">
                            <span className="text-sm font-medium text-slate-700">
                                <span className="text-teal-600 font-bold">{pasienReminder.length}</span> pasien
                                {sudahDipilih && (
                                    <span className="ml-2 text-slate-500">
                                        · <span className="text-teal-600 font-bold">{selected.size}</span> dipilih
                                        {validForWa.length < selected.size && (
                                            <span className="text-amber-600 ml-1">
                                                ({selected.size - validForWa.length} tanpa nomor valid)
                                            </span>
                                        )}
                                    </span>
                                )}
                            </span>
                            {sudahDipilih && (
                                <button onClick={() => setSelected(new Set())}
                                    className="text-xs text-slate-400 hover:text-slate-600 flex items-center gap-1">
                                    <X className="w-3 h-3" /> Batalkan
                                </button>
                            )}
                        </div>

                        <div className="flex items-center gap-2 flex-wrap">
                            {/* Tombol Kirim Reminder */}
                            <Button
                                onClick={() => setShowKonfirmasi(true)}
                                disabled={!sudahDipilih || blasting}
                                className={`${sudahDipilih ? 'bg-emerald-600 text-white border-emerald-700 hover:bg-emerald-700' : ''}`}
                                icon={<WaIcon className="w-4 h-4" />}
                            >
                                Kirim Reminder WhatsApp
                                {sudahDipilih && ` (${selected.size})`}
                            </Button>

                            <Button onClick={handleDownload} variant="outline"
                                icon={<Download className="w-4 h-4 text-teal-600" />}>
                                Download{sudahDipilih ? ` (${selected.size})` : ' Semua'}
                            </Button>
                        </div>
                    </div>

                    {/* Tabel pasien */}
                    <div className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm text-left min-w-[700px]">
                                <thead className="bg-slate-50/80 border-b border-slate-200 text-xs font-semibold text-slate-500 uppercase tracking-wider sticky top-0 z-10">
                                    <tr>
                                        <th className="px-4 py-3 w-10">
                                            <input type="checkbox" checked={semuaDipilih}
                                                ref={el => { if (el) el.indeterminate = sebagianDipilih; }}
                                                onChange={toggleAll}
                                                className="w-4 h-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500 cursor-pointer" />
                                        </th>
                                        <th className="px-4 py-3">Pasien</th>
                                        <th className="px-4 py-3">FKTP</th>
                                        <th className="px-4 py-3">No. WhatsApp</th>
                                        <th className="px-4 py-3">Jadwal</th>
                                        <th className="px-4 py-3">Early Warning</th>
                                        <th className="px-4 py-3 text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {pasienReminder.map(pasien => (
                                        <>
                                            <tr key={pasien.id}
                                                className={`transition-colors ${selected.has(pasien.id) ? 'bg-teal-50/40' : 'hover:bg-slate-50/50'}`}>
                                                <td className="px-4 py-3">
                                                    <input type="checkbox" checked={selected.has(pasien.id)}
                                                        onChange={() => toggleOne(pasien.id)}
                                                        className="w-4 h-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500 cursor-pointer" />
                                                </td>

                                                <td className="px-4 py-3">
                                                    <div className="font-semibold text-slate-800">{pasien.nama_pasien}</div>
                                                    <div className="text-xs text-slate-400">{pasien.kode_pasien}</div>
                                                </td>

                                                <td className="px-4 py-3 text-xs text-slate-600 max-w-[160px] whitespace-normal">
                                                    {pasien.fktp_asal}
                                                </td>

                                                <td className="px-4 py-3 whitespace-nowrap">
                                                    {pasien.no_whatsapp ? (
                                                        <span className={`text-xs font-mono px-2 py-0.5 rounded ${
                                                            isValidWa(pasien.no_whatsapp)
                                                                ? 'bg-slate-100 text-slate-700'
                                                                : 'bg-amber-50 text-amber-700 border border-amber-200'
                                                        }`}>
                                                            {pasien.no_whatsapp}
                                                            {!isValidWa(pasien.no_whatsapp) && ' ⚠'}
                                                        </span>
                                                    ) : (
                                                        <span className="text-xs text-amber-600 flex items-center gap-1 bg-amber-50 px-2 py-0.5 rounded border border-amber-100">
                                                            <AlertCircle className="w-3 h-3" /> Tidak ada
                                                        </span>
                                                    )}
                                                </td>

                                                <td className="px-4 py-3 whitespace-nowrap">
                                                    <span className="font-medium text-slate-800">{fmt(pasien.jadwal)}</span>
                                                </td>

                                                <td className="px-4 py-3 whitespace-nowrap">
                                                    <span className={`text-xs font-medium px-2 py-0.5 rounded-full border ${
                                                        pasien.early_warning === 'Tidak patuh/Overdue'
                                                            ? 'bg-rose-50 text-rose-700 border-rose-200'
                                                            : pasien.early_warning === 'Jatuh tempo' || pasien.early_warning === 'Belum mengambil, masih toleransi'
                                                                ? 'bg-orange-50 text-orange-700 border-orange-200'
                                                                : 'bg-amber-50 text-amber-700 border-amber-200'
                                                    }`}>
                                                        {pasien.early_warning}
                                                    </span>
                                                </td>

                                                <td className="px-4 py-3">
                                                    <div className="flex items-center justify-center gap-1.5">
                                                        {/* Salin pesan */}
                                                        <button onClick={() => handleCopyOne(pasien)}
                                                            className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 transition-colors"
                                                            title="Salin template pesan">
                                                            {copied === pasien.id
                                                                ? <><CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" /> Tersalin</>
                                                                : <><Copy className="w-3.5 h-3.5" /> Salin</>
                                                            }
                                                        </button>

                                                        {/* Buka WA langsung (satu pasien) */}
                                                        <button onClick={() => handleWaOne(pasien)}
                                                            disabled={!pasien.no_whatsapp}
                                                            className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg bg-emerald-50 hover:bg-emerald-100 text-emerald-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                                            title={pasien.no_whatsapp ? 'Buka WhatsApp' : 'No. WA tidak tersedia'}>
                                                            <WaIcon className="w-3.5 h-3.5" />
                                                        </button>

                                                        {/* Catat tindak lanjut */}
                                                        <button
                                                            onClick={() => setFormOpen(formOpen === pasien.id ? null : pasien.id)}
                                                            className={`flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-colors ${
                                                                formOpen === pasien.id
                                                                    ? 'bg-teal-600 text-white'
                                                                    : 'bg-teal-50 hover:bg-teal-100 text-teal-700'
                                                            }`}
                                                            title="Catat tindak lanjut">
                                                            <MessageSquarePlus className="w-3.5 h-3.5" />
                                                        </button>

                                                        {/* Toggle histori */}
                                                        {pasien.tindak_lanjut.length > 0 && (
                                                            <button onClick={() => toggleHistori(pasien.id)}
                                                                className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg bg-slate-50 hover:bg-slate-100 text-slate-500 transition-colors"
                                                                title={`${pasien.tindak_lanjut.length} tindak lanjut`}>
                                                                {historiOpen.has(pasien.id)
                                                                    ? <ChevronUp className="w-3.5 h-3.5" />
                                                                    : <ChevronDown className="w-3.5 h-3.5" />
                                                                }
                                                                <span>{pasien.tindak_lanjut.length}</span>
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>

                                            {/* Form tindak lanjut (row ekspansi) */}
                                            {formOpen === pasien.id && (
                                                <tr key={`form-${pasien.id}`}>
                                                    <td colSpan={7} className="px-4 pb-4 bg-teal-50/20">
                                                        <FormTindakLanjut
                                                            pasienId={pasien.id}
                                                            tindakanOptions={tindakanOptions}
                                                            onSaved={() => {
                                                                setFormOpen(null);
                                                                router.reload({ only: ['pasienReminder'] });
                                                            }}
                                                            onCancel={() => setFormOpen(null)}
                                                        />
                                                    </td>
                                                </tr>
                                            )}

                                            {/* Histori tindak lanjut (row ekspansi) */}
                                            {historiOpen.has(pasien.id) && pasien.tindak_lanjut.length > 0 && (
                                                <tr key={`histori-${pasien.id}`}>
                                                    <td colSpan={7} className="px-4 pb-3 bg-slate-50/60">
                                                        <div className="space-y-1.5 pt-2">
                                                            {pasien.tindak_lanjut.map(tl => (
                                                                <div key={tl.id} className="flex gap-3 text-xs bg-white rounded-lg p-2.5 border border-slate-100">
                                                                    <span className="text-slate-400 whitespace-nowrap">{tl.tanggal_jam}</span>
                                                                    <div className="flex-1">
                                                                        <span className="font-semibold text-slate-700">{tl.tindakan}</span>
                                                                        {tl.petugas && <span className="text-slate-400 ml-1.5">oleh {tl.petugas}</span>}
                                                                        {tl.catatan && <div className="text-slate-500 mt-0.5">{tl.catatan}</div>}
                                                                    </div>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </td>
                                                </tr>
                                            )}
                                        </>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Pilih semua + footer info */}
                    <div className="mt-3 flex flex-col sm:flex-row items-start sm:items-center gap-3">
                        <label className="flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
                            <input type="checkbox" checked={semuaDipilih}
                                ref={el => { if (el) el.indeterminate = sebagianDipilih; }}
                                onChange={toggleAll}
                                className="w-4 h-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500 cursor-pointer" />
                            Pilih Semua ({pasienReminder.length})
                        </label>
                        <p className="text-xs text-slate-400">
                            Dashboard PRB membuka WhatsApp secara manual satu per satu. Tidak ada pengiriman otomatis.
                        </p>
                    </div>
                </>
            )}

            {/* ── Modal Konfirmasi ────────────────────────────────────── */}
            <AnimatePresence>
                {showKonfirmasi && (
                    <ModalKonfirmasi
                        selectedPasien={selectedPasien}
                        onConfirm={handleBlastConfirm}
                        onCancel={() => setShowKonfirmasi(false)}
                    />
                )}
            </AnimatePresence>

            {/* ── Modal Ringkasan Hasil ───────────────────────────────── */}
            <AnimatePresence>
                {blastDone && (
                    <ModalRingkasan
                        ringkasan={blastDone.ringkasan}
                        detail={blastDone.detail}
                        onClose={() => {
                            setBlastDone(null);
                            setSelected(new Set());
                            router.reload({ only: ['pasienReminder'] });
                        }}
                    />
                )}
            </AnimatePresence>
        </AppLayout>
    );
}
