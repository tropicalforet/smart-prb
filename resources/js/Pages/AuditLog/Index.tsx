import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { useState } from 'react';
import { Search, X, ClipboardList, Filter } from 'lucide-react';
import { motion } from 'motion/react';

interface AuditLogEntry {
    id: string;
    petugas: string;
    modul: string;
    aksi: string;
    deskripsi: string;
    entitas_id: string | null;
    entitas_label: string | null;
    perubahan: Perubahan[] | null;
    created_at: string;
}

interface Perubahan {
    kolom: string;
    lama: string | null;
    baru: string | null;
}

interface PaginatedLogs {
    data: AuditLogEntry[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    logs: PaginatedLogs;
    filters: {
        filter_modul: string;
        filter_aksi: string;
        search: string;
    };
    modulOptions: Option[];
    aksiOptions: Option[];
}

// Warna badge per modul
const MODUL_COLORS: Record<string, string> = {
    pasien:        'bg-teal-100 text-teal-700 border-teal-200',
    realisasi:     'bg-emerald-100 text-emerald-700 border-emerald-200',
    tindak_lanjut: 'bg-blue-100 text-blue-700 border-blue-200',
    import:        'bg-purple-100 text-purple-700 border-purple-200',
    hapus_massal:  'bg-rose-100 text-rose-700 border-rose-200',
    auth:          'bg-slate-100 text-slate-700 border-slate-200',
};

// Warna badge per aksi
const AKSI_COLORS: Record<string, string> = {
    tambah:              'bg-emerald-50 text-emerald-700',
    ubah:                'bg-amber-50 text-amber-700',
    hapus:               'bg-rose-50 text-rose-700',
    ubah_status:         'bg-orange-50 text-orange-700',
    catat_realisasi:     'bg-teal-50 text-teal-700',
    edit_realisasi:      'bg-cyan-50 text-cyan-700',
    import:              'bg-purple-50 text-purple-700',
    hapus_massal:        'bg-rose-50 text-rose-700',
    catat_tindak_lanjut: 'bg-blue-50 text-blue-700',
    kirim_pengingat_wa:  'bg-emerald-50 text-emerald-700',
    petakan_diagnosis:   'bg-amber-50 text-amber-700',
    login:               'bg-slate-50 text-slate-700',
    logout:              'bg-slate-50 text-slate-500',
    ganti_password:      'bg-indigo-50 text-indigo-700',
};

/** Batas jumlah baris rincian yang langsung ditampilkan sebelum dilipat. */
const RINCIAN_TAMPIL = 6;

/**
 * Rincian perubahan per kolom: nilai lama → nilai baru.
 * Kosong di sisi lama = data baru; kosong di sisi baru = data dihapus.
 */
function RincianPerubahan({ perubahan }: { perubahan: Perubahan[] }) {
    const [buka, setBuka] = useState(perubahan.length <= RINCIAN_TAMPIL);
    const tampil = buka ? perubahan : perubahan.slice(0, RINCIAN_TAMPIL);

    return (
        <div className="mt-1.5 text-xs">
            <ul className="space-y-0.5 border-l-2 border-slate-200 pl-2">
                {tampil.map((p, i) => (
                    <li key={i} className="text-slate-600">
                        <span className="font-medium text-slate-700">{p.kolom}:</span>{' '}
                        {p.lama !== null && (
                            <span className={p.baru !== null ? 'text-rose-600 line-through decoration-rose-300' : 'text-rose-600'}>
                                {p.lama}
                            </span>
                        )}
                        {p.lama !== null && p.baru !== null && <span className="text-slate-400"> → </span>}
                        {p.baru !== null && <span className="text-emerald-700">{p.baru}</span>}
                        {p.lama === null && p.baru === null && <span className="text-slate-400">-</span>}
                    </li>
                ))}
            </ul>
            {perubahan.length > RINCIAN_TAMPIL && (
                <button
                    onClick={() => setBuka(!buka)}
                    className="mt-1 text-teal-600 hover:text-teal-700 font-medium"
                >
                    {buka ? 'Sembunyikan rincian' : `Lihat semua ${perubahan.length} rincian`}
                </button>
            )}
        </div>
    );
}

function formatTanggal(iso: string): string {
    const d = new Date(iso);
    return d.toLocaleDateString('id-ID', {
        day: '2-digit', month: 'short', year: 'numeric',
    }) + ' ' + d.toLocaleTimeString('id-ID', {
        hour: '2-digit', minute: '2-digit',
    });
}

export default function AuditLogIndex({ logs, filters, modulOptions, aksiOptions }: Props) {
    const [search, setSearch]           = useState(filters.search);
    const [filterModul, setFilterModul] = useState(filters.filter_modul);
    const [filterAksi, setFilterAksi]   = useState(filters.filter_aksi);

    const applyFilters = (overrides: Record<string, string> = {}) => {
        const params: Record<string, string> = {};
        const current = { search, filter_modul: filterModul, filter_aksi: filterAksi, ...overrides };
        if (current.search)       params.search       = current.search;
        if (current.filter_modul) params.filter_modul = current.filter_modul;
        if (current.filter_aksi)  params.filter_aksi  = current.filter_aksi;
        router.get('/audit-log', params, { preserveState: true, preserveScroll: true });
    };

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        applyFilters();
    };

    const resetFilters = () => {
        setSearch('');
        setFilterModul('');
        setFilterAksi('');
        router.get('/audit-log', {}, { preserveState: true, preserveScroll: true });
    };

    const hasFilter = search || filterModul || filterAksi;

    return (
        <AppLayout title="Audit Log">
            <Head title="Audit Log" />

            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-800 flex items-center gap-2">
                        <ClipboardList className="w-6 h-6 text-teal-600" />
                        Audit Log
                    </h1>
                    <p className="text-sm text-slate-500 mt-1">
                        Riwayat seluruh aktivitas yang tercatat di sistem
                    </p>
                </div>
                <div className="text-sm text-slate-500 bg-slate-100 rounded-lg px-3 py-1.5">
                    Total: <span className="font-semibold text-slate-700">{logs.total.toLocaleString('id-ID')}</span> entri
                </div>
            </div>

            {/* Filter Bar */}
            <div className="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mb-6">
                <div className="flex flex-col sm:flex-row gap-3">
                    {/* Search */}
                    <form onSubmit={handleSearchSubmit} className="flex-1 flex gap-2">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                placeholder="Cari deskripsi, nama pasien, atau petugas..."
                                className="w-full pl-9 pr-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                            />
                        </div>
                        <button type="submit"
                            className="px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition">
                            Cari
                        </button>
                    </form>

                    {/* Filter Modul */}
                    <div className="flex items-center gap-2">
                        <Filter className="w-4 h-4 text-slate-400 flex-shrink-0" />
                        <select
                            value={filterModul}
                            onChange={e => { setFilterModul(e.target.value); applyFilters({ filter_modul: e.target.value }); }}
                            className="text-sm border border-slate-300 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-teal-500"
                        >
                            <option value="">Semua Modul</option>
                            {modulOptions.map(o => (
                                <option key={o.value} value={o.value}>{o.label}</option>
                            ))}
                        </select>

                        <select
                            value={filterAksi}
                            onChange={e => { setFilterAksi(e.target.value); applyFilters({ filter_aksi: e.target.value }); }}
                            className="text-sm border border-slate-300 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-teal-500"
                        >
                            <option value="">Semua Aksi</option>
                            {aksiOptions.map(o => (
                                <option key={o.value} value={o.value}>{o.label}</option>
                            ))}
                        </select>

                        {hasFilter && (
                            <button onClick={resetFilters}
                                className="flex items-center gap-1 text-xs text-rose-600 border border-rose-200 rounded-lg px-2.5 py-2 bg-rose-50 hover:bg-rose-100 transition">
                                <X className="w-3.5 h-3.5" /> Reset
                            </button>
                        )}
                    </div>
                </div>
            </div>

            {/* Tabel */}
            <motion.div
                initial={{ opacity: 0, y: 16 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3 }}
                className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden"
            >
                {logs.data.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-20 text-slate-400">
                        <ClipboardList className="w-12 h-12 mb-3 opacity-30" />
                        <p className="text-sm">Belum ada aktivitas yang tercatat</p>
                    </div>
                ) : (
                    <>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50">
                                        <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider whitespace-nowrap">Waktu</th>
                                        <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Modul</th>
                                        <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Aksi</th>
                                        <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Deskripsi</th>
                                        <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Petugas</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {logs.data.map((log, idx) => (
                                        <tr key={log.id}
                                            className={`hover:bg-slate-50/70 transition-colors ${idx % 2 === 0 ? '' : 'bg-slate-50/30'}`}>
                                            {/* Waktu */}
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <span className="text-xs text-slate-500 font-mono">
                                                    {formatTanggal(log.created_at)}
                                                </span>
                                            </td>

                                            {/* Modul */}
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex items-center text-xs font-medium px-2 py-0.5 rounded-full border ${MODUL_COLORS[log.modul] ?? 'bg-slate-100 text-slate-600 border-slate-200'}`}>
                                                    {log.modul.replace('_', ' ')}
                                                </span>
                                            </td>

                                            {/* Aksi */}
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex items-center text-xs font-medium px-2 py-0.5 rounded-md ${AKSI_COLORS[log.aksi] ?? 'bg-slate-50 text-slate-600'}`}>
                                                    {log.aksi.replace(/_/g, ' ')}
                                                </span>
                                            </td>

                                            {/* Deskripsi */}
                                            <td className="px-4 py-3 text-slate-700 max-w-md">
                                                {log.deskripsi}
                                                {log.perubahan && log.perubahan.length > 0 && (
                                                    <RincianPerubahan perubahan={log.perubahan} />
                                                )}
                                            </td>

                                            {/* Petugas */}
                                            <td className="px-4 py-3">
                                                <span className="text-xs font-medium text-slate-600 bg-slate-100 px-2 py-0.5 rounded-md">
                                                    {log.petugas}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination */}
                        {logs.last_page > 1 && (
                            <div className="flex items-center justify-between px-4 py-3 border-t border-slate-100">
                                <p className="text-xs text-slate-500">
                                    Menampilkan {logs.from}–{logs.to} dari {logs.total.toLocaleString('id-ID')} entri
                                </p>
                                <div className="flex items-center gap-1">
                                    {logs.links.map((link, i) => (
                                        <button
                                            key={i}
                                            disabled={!link.url}
                                            onClick={() => link.url && router.get(link.url)}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                            className={`px-3 py-1.5 text-xs rounded-lg border transition ${
                                                link.active
                                                    ? 'bg-teal-600 text-white border-teal-600'
                                                    : link.url
                                                        ? 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50'
                                                        : 'bg-slate-50 text-slate-300 border-slate-200 cursor-not-allowed'
                                            }`}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </>
                )}
            </motion.div>
        </AppLayout>
    );
}
