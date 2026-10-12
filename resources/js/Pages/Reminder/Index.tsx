import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { useState, useCallback } from 'react';
import { Bell, Download, Copy, CheckCircle2, Users, AlertCircle, X } from 'lucide-react';
import { Button } from '@/Components/ui/Button';
import { motion, AnimatePresence } from 'motion/react';
import { generateWhatsAppLink, getReminderTemplate } from '@/lib/whatsapp-templates';

// ── Types ──────────────────────────────────────────────────────────────────

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
}

interface Props {
    pasienReminder: PasienReminder[];
    periodeOptions: { value: string; label: string }[];
    kategoriOptions: { value: string; label: string }[];
    filters: {
        filter_periode: string;
        filter_kategori: string;
        tanggal_evaluasi: string;
    };
}

// ── Helpers ────────────────────────────────────────────────────────────────

const BULAN = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
function fmt(tgl?: string | null): string {
    if (!tgl) return '-';
    const d = new Date(tgl);
    if (isNaN(d.getTime())) return '-';
    return `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}/${d.getFullYear()}`;
}

// ── Komponen ───────────────────────────────────────────────────────────────

export default function ReminderIndex({ pasienReminder, periodeOptions, kategoriOptions, filters }: Props) {
    const [periode, setPeriode]   = useState(filters.filter_periode);
    const [kategori, setKategori] = useState(filters.filter_kategori);

    // Pasien yang dipilih (checkbox)
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [copied, setCopied]     = useState<string | null>(null);

    // Terapkan filter ke backend
    const applyFilters = useCallback((p: string, k: string) => {
        router.get('/reminder', {
            filter_periode:  p,
            filter_kategori: k,
        }, { preserveState: true, preserveScroll: true });
    }, []);

    const handlePeriodeChange = (v: string) => {
        setPeriode(v);
        if (v && kategori) applyFilters(v, kategori);
    };

    const handleKategoriChange = (v: string) => {
        setKategori(v);
        if (periode && v) applyFilters(periode, v);
    };

    // Checkbox
    const toggleAll = () => {
        if (selected.size === pasienReminder.length) {
            setSelected(new Set());
        } else {
            setSelected(new Set(pasienReminder.map(p => p.id)));
        }
    };

    const toggleOne = (id: string) => {
        setSelected(prev => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    const selectedPasien = pasienReminder.filter(p => selected.has(p.id));

    // Salin satu pesan
    const handleCopyOne = (pasien: PasienReminder) => {
        const msg = getReminderTemplate(pasien.nama_pasien, pasien.jadwal);
        navigator.clipboard.writeText(msg).then(() => {
            setCopied(pasien.id);
            setTimeout(() => setCopied(null), 2000);
        });
    };

    // Buka WhatsApp satu pasien
    const handleWaOne = (pasien: PasienReminder) => {
        if (!pasien.no_whatsapp) {
            alert('Nomor WhatsApp tidak tersedia untuk pasien ini.');
            return;
        }
        const msg = getReminderTemplate(pasien.nama_pasien, pasien.jadwal);
        window.open(generateWhatsAppLink(pasien.no_whatsapp, msg), '_blank');
    };

    // Download daftar reminder sebagai CSV untuk WhatsApp Blast eksternal
    const handleDownload = () => {
        const list = selectedPasien.length > 0 ? selectedPasien : pasienReminder;
        if (list.length === 0) return;

        const header = ['Nama Pasien', 'No. WhatsApp', 'Kode Pasien', 'FKTP', 'Jadwal Pengambilan', 'Pesan Reminder'];
        const rows = list.map(p => [
            p.nama_pasien,
            p.no_whatsapp ?? '-',
            p.kode_pasien,
            p.fktp_asal,
            fmt(p.jadwal),
            getReminderTemplate(p.nama_pasien, p.jadwal),
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

    const sudahDipilih     = selected.size > 0;
    const semuaDipilih     = selected.size === pasienReminder.length && pasienReminder.length > 0;
    const sebagianDipilih  = selected.size > 0 && !semuaDipilih;

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
                    <p className="text-sm text-slate-500">Pilih periode dan kategori, lalu siapkan data reminder untuk dikirim melalui WhatsApp.</p>
                </div>
            </div>

            {/* ── Filter ─────────────────────────────────────────────── */}
            <div className="bg-white border border-slate-200 rounded-2xl shadow-sm p-5 mb-6">
                <h2 className="text-sm font-semibold text-slate-700 mb-4">Filter Reminder</h2>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label className="block text-xs font-medium text-slate-600 mb-1.5">Periode Jadwal</label>
                        <select
                            value={periode}
                            onChange={e => handlePeriodeChange(e.target.value)}
                            className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                        >
                            <option value="">-- Pilih Periode --</option>
                            {periodeOptions.map(o => (
                                <option key={o.value} value={o.value}>{o.label}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600 mb-1.5">Kategori Reminder</label>
                        <select
                            value={kategori}
                            onChange={e => handleKategoriChange(e.target.value)}
                            className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                        >
                            <option value="">-- Pilih Kategori --</option>
                            {kategoriOptions.map(o => (
                                <option key={o.value} value={o.value}>{o.label}</option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>

            {/* ── Belum memilih ──────────────────────────────────────── */}
            {(!periode || !kategori) && (
                <div className="bg-blue-50 border border-blue-200 rounded-2xl p-8 text-center text-blue-700">
                    <Bell className="w-10 h-10 mx-auto mb-3 opacity-50" />
                    <p className="font-medium">Pilih periode dan kategori untuk menampilkan pasien.</p>
                    <p className="text-sm mt-1 opacity-70">Sistem akan otomatis menampilkan daftar pasien yang perlu diingatkan.</p>
                </div>
            )}

            {/* ── Tidak ada pasien ───────────────────────────────────── */}
            {periode && kategori && pasienReminder.length === 0 && (
                <div className="bg-slate-50 border border-slate-200 rounded-2xl p-8 text-center text-slate-500">
                    <CheckCircle2 className="w-10 h-10 mx-auto mb-3 text-emerald-400" />
                    <p className="font-medium">Tidak ada pasien yang perlu diingatkan pada periode dan kategori ini.</p>
                </div>
            )}

            {/* ── Daftar pasien ──────────────────────────────────────── */}
            {pasienReminder.length > 0 && (
                <>
                    {/* Toolbar */}
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                        <div className="flex items-center gap-3">
                            <span className="text-sm font-medium text-slate-700">
                                <span className="text-teal-600 font-bold">{pasienReminder.length}</span> pasien perlu diingatkan
                                {sudahDipilih && (
                                    <span className="ml-2 text-slate-500">
                                        · <span className="text-teal-600 font-bold">{selected.size}</span> dipilih
                                    </span>
                                )}
                            </span>
                            {sudahDipilih && (
                                <button
                                    onClick={() => setSelected(new Set())}
                                    className="text-xs text-slate-400 hover:text-slate-600 flex items-center gap-1"
                                >
                                    <X className="w-3 h-3" /> Batalkan pilihan
                                </button>
                            )}
                        </div>
                        <Button
                            onClick={handleDownload}
                            variant="outline"
                            icon={<Download className="w-4 h-4 text-teal-600" />}
                        >
                            Download Daftar Reminder{sudahDipilih ? ` (${selected.size})` : ' (Semua)'}
                        </Button>
                    </div>

                    {/* Tabel */}
                    <div className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                        <table className="w-full text-sm text-left">
                            <thead className="bg-slate-50/80 border-b border-slate-200 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                                <tr>
                                    <th className="px-4 py-3 w-12">
                                        <input
                                            type="checkbox"
                                            checked={semuaDipilih}
                                            ref={el => { if (el) el.indeterminate = sebagianDipilih; }}
                                            onChange={toggleAll}
                                            className="w-4 h-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500 cursor-pointer"
                                        />
                                    </th>
                                    <th className="px-4 py-3 min-w-[200px]">Pasien</th>
                                    <th className="px-4 py-3 min-w-[140px]">No. WhatsApp</th>
                                    <th className="px-4 py-3 min-w-[130px]">Jadwal Pengambilan</th>
                                    <th className="px-4 py-3 min-w-[130px]">FKTP</th>
                                    <th className="px-4 py-3 text-center min-w-[180px]">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {pasienReminder.map(pasien => (
                                    <motion.tr
                                        key={pasien.id}
                                        initial={{ opacity: 0, y: 6 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        className={`transition-colors ${selected.has(pasien.id) ? 'bg-teal-50/50' : 'hover:bg-slate-50/50'}`}
                                    >
                                        <td className="px-4 py-3">
                                            <input
                                                type="checkbox"
                                                checked={selected.has(pasien.id)}
                                                onChange={() => toggleOne(pasien.id)}
                                                className="w-4 h-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500 cursor-pointer"
                                            />
                                        </td>

                                        <td className="px-4 py-3">
                                            <div className="font-semibold text-slate-800 whitespace-normal">{pasien.nama_pasien}</div>
                                            <div className="text-xs text-slate-400">{pasien.kode_pasien}</div>
                                        </td>

                                        <td className="px-4 py-3 whitespace-nowrap">
                                            {pasien.no_whatsapp ? (
                                                <span className="text-slate-700 font-mono text-xs">{pasien.no_whatsapp}</span>
                                            ) : (
                                                <span className="text-slate-400 italic text-xs flex items-center gap-1">
                                                    <AlertCircle className="w-3 h-3 text-amber-400" /> Tidak tersedia
                                                </span>
                                            )}
                                        </td>

                                        <td className="px-4 py-3 whitespace-nowrap">
                                            <span className="font-medium text-slate-800">{fmt(pasien.jadwal)}</span>
                                        </td>

                                        <td className="px-4 py-3">
                                            <span className="text-xs text-slate-600 whitespace-normal">{pasien.fktp_asal}</span>
                                        </td>

                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-center gap-2">
                                                {/* Salin pesan */}
                                                <button
                                                    onClick={() => handleCopyOne(pasien)}
                                                    className="flex items-center gap-1 text-xs px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 transition-colors"
                                                    title="Salin template pesan"
                                                >
                                                    {copied === pasien.id
                                                        ? <><CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" /> Tersalin</>
                                                        : <><Copy className="w-3.5 h-3.5" /> Salin Pesan</>
                                                    }
                                                </button>

                                                {/* Buka WhatsApp */}
                                                <button
                                                    onClick={() => handleWaOne(pasien)}
                                                    disabled={!pasien.no_whatsapp}
                                                    className="flex items-center gap-1 text-xs px-2.5 py-1.5 rounded-lg bg-emerald-50 hover:bg-emerald-100 text-emerald-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                                    title={pasien.no_whatsapp ? 'Buka WhatsApp' : 'No. WhatsApp tidak tersedia'}
                                                >
                                                    <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                                                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                                                    </svg>
                                                    WhatsApp
                                                </button>
                                            </div>
                                        </td>
                                    </motion.tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Info: tidak kirim otomatis */}
                    <p className="text-xs text-slate-400 mt-3 text-center">
                        SMART PRB hanya menyiapkan data reminder. Pengiriman dilakukan oleh petugas melalui WhatsApp atau tools eksternal.
                    </p>
                </>
            )}
        </AppLayout>
    );
}
