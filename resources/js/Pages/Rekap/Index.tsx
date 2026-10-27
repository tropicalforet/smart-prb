import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { useCallback, useState } from 'react';
import { motion } from 'motion/react';
import {
    Users,
    CheckCircle2,
    AlertTriangle,
    Clock,
    UserMinus,
    Download,
    CalendarDays,
    Percent,
} from 'lucide-react';
import { Button } from '@/Components/ui/Button';

interface RekapData {
    fktp: string;
    total: number;
    patuh: number;
    dalam_toleransi: number;
    tidak_patuh: number;
    pasif: number;
    belum_jatuh_tempo: number;
    persentase_patuh: number | null;
}

interface Props {
    summary: {
        total: number;
        patuh: number;
        dalam_toleransi: number;
        tidak_patuh: number;
        pasif: number;
        belum_jatuh_tempo: number;
        persentase_patuh: number | null;
    };
    rekapFktp: RekapData[];
    filters: {
        filter_periode: string;
        filter_fktp: string;
        filter_kepatuhan: string;
        tanggal_evaluasi: string;
    };
    fktpOptions: string[];
    periodeOptions: { value: string; label: string }[];
}

/** Persentase kepatuhan = Patuh / (Patuh + Tidak Patuh); '-' bila belum ada yang dinilai. */
const formatPersen = (p: number | null) => (p === null ? '-' : `${p}%`);

export default function RekapIndex({
    summary,
    rekapFktp,
    filters,
    fktpOptions,
    periodeOptions
}: Props) {
    const [filterPeriode, setFilterPeriode] = useState(filters.filter_periode);
    const [filterFktp, setFilterFktp] = useState(filters.filter_fktp);
    const [filterKepatuhan, setFilterKepatuhan] = useState(filters.filter_kepatuhan);
    
    const applyFilters = useCallback((newFilters: any = {}) => {
        router.get('/rekap', {
            filter_periode: filterPeriode,
            filter_fktp: filterFktp,
            filter_kepatuhan: filterKepatuhan,
            ...newFilters
        }, { preserveState: true, preserveScroll: true });
    }, [filterPeriode, filterFktp, filterKepatuhan]);

    const handleExport = () => {
        // Build URL dengan parameter filter yang sama dengan halaman saat ini
        const params = new URLSearchParams();
        
        if (filterPeriode) params.append('filter_periode', filterPeriode);
        if (filterFktp) params.append('filter_fktp', filterFktp);
        if (filterKepatuhan) params.append('filter_kepatuhan', filterKepatuhan);
        params.append('tanggal_evaluasi', filters.tanggal_evaluasi);

        // Trigger download dengan membuka URL di tab baru
        const exportUrl = `/rekap/export?${params.toString()}`;
        window.open(exportUrl, '_blank');
    };

    const summaryCards = [
        {
            title: 'Total Pasien',
            value: summary.total,
            icon: Users,
            gradient: 'from-slate-600 to-slate-700',
        },
        {
            title: '% Kepatuhan',
            value: formatPersen(summary.persentase_patuh),
            icon: Percent,
            gradient: 'from-teal-500 to-teal-600',
        },
        {
            title: 'Patuh',
            value: summary.patuh,
            icon: CheckCircle2,
            gradient: 'from-emerald-500 to-emerald-600',
        },
        {
            title: 'Dalam Toleransi',
            value: summary.dalam_toleransi,
            icon: Clock,
            gradient: 'from-amber-500 to-amber-600',
        },
        {
            title: 'Tidak Patuh',
            value: summary.tidak_patuh,
            icon: AlertTriangle,
            gradient: 'from-rose-500 to-rose-600',
        },
        {
            title: 'Blm Jatuh Tempo',
            value: summary.belum_jatuh_tempo,
            icon: CalendarDays,
            gradient: 'from-blue-400 to-blue-500',
        },
        {
            title: 'Pasif',
            value: summary.pasif,
            icon: UserMinus,
            gradient: 'from-slate-400 to-slate-500',
        },
    ];

    return (
        <AppLayout title="Rekap Kepatuhan PRB">
            <Head title="Rekap Kepatuhan PRB" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-800">Rekap Kepatuhan PRB</h1>
                    <p className="text-sm text-slate-500 mt-1">Ringkasan kepatuhan berdasarkan FKTP</p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <select
                        value={filterPeriode}
                        onChange={(e) => {
                            setFilterPeriode(e.target.value);
                            applyFilters({ filter_periode: e.target.value });
                        }}
                        className="text-sm font-medium text-slate-700 bg-white border border-slate-200 rounded-xl px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 cursor-pointer h-[42px]"
                    >
                        <option value="">Semua Periode</option>
                        {periodeOptions.map((opt) => (
                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                        ))}
                    </select>

                    <select
                        value={filterFktp}
                        onChange={(e) => {
                            setFilterFktp(e.target.value);
                            applyFilters({ filter_fktp: e.target.value });
                        }}
                        className="text-sm font-medium text-slate-700 bg-white border border-slate-200 rounded-xl px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 cursor-pointer h-[42px]"
                    >
                        <option value="">Semua FKTP</option>
                        {fktpOptions.map((fktp) => (
                            <option key={fktp} value={fktp}>{fktp}</option>
                        ))}
                    </select>

                    <select
                        value={filterKepatuhan}
                        onChange={(e) => {
                            setFilterKepatuhan(e.target.value);
                            applyFilters({ filter_kepatuhan: e.target.value });
                        }}
                        className="text-sm font-medium text-slate-700 bg-white border border-slate-200 rounded-xl px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 cursor-pointer h-[42px]"
                    >
                        <option value="">Semua Kepatuhan</option>
                        <option value="Patuh">Patuh</option>
                        {/* value harus cocok dengan string status yang dikembalikan KepatuhanService */}
                        <option value="Dalam Toleransi">Dalam Toleransi</option>
                        <option value="Tidak Patuh">Tidak Patuh</option>
                        <option value="Belum Jatuh Tempo">Belum Jatuh Tempo</option>
                        <option value="Pasif">Pasif</option>
                    </select>

                    <Button onClick={handleExport} variant="outline" className="h-[42px]">
                        <Download className="w-4 h-4 mr-2" />
                        Export Excel
                    </Button>
                </div>
            </div>

            {/* Summary Cards */}
            <div className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-4 mb-6">
                {summaryCards.map((card, idx) => (
                    <motion.div
                        key={card.title}
                        initial={{ opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.3, delay: idx * 0.05 }}
                        className={`rounded-xl bg-gradient-to-br ${card.gradient} p-4 text-white shadow-md relative overflow-hidden`}
                    >
                        <div className="absolute top-0 right-0 w-16 h-16 bg-white/10 rounded-full -translate-y-4 translate-x-4" />
                        <div className="flex items-center gap-2 mb-2">
                            <card.icon className="w-4 h-4 text-white/80" />
                            <span className="text-xs font-semibold uppercase tracking-wider text-white/90">{card.title}</span>
                        </div>
                        <div className="text-2xl font-bold">{card.value}</div>
                    </motion.div>
                ))}
            </div>

            {/* Table */}
            <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm text-left">
                        <thead className="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider border-b border-slate-200">
                            <tr>
                                <th className="px-6 py-4 whitespace-nowrap">FKTP</th>
                                <th className="px-6 py-4 whitespace-nowrap text-right">Total Pasien</th>
                                <th className="px-6 py-4 whitespace-nowrap text-right text-emerald-600">Patuh</th>
                                <th className="px-6 py-4 whitespace-nowrap text-right text-amber-600">Dlm Toleransi</th>
                                <th className="px-6 py-4 whitespace-nowrap text-right text-rose-600">Tidak Patuh</th>
                                <th className="px-6 py-4 whitespace-nowrap text-right text-blue-500">Blm Jatuh Tempo</th>
                                <th className="px-6 py-4 whitespace-nowrap text-right">Pasif</th>
                                <th className="px-6 py-4 whitespace-nowrap text-right text-teal-600">% Kepatuhan</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rekapFktp.map((row, idx) => (
                                <tr key={idx} className="hover:bg-slate-50/50 transition-colors">
                                    <td className="px-6 py-4 font-medium text-slate-800 whitespace-nowrap">{row.fktp}</td>
                                    <td className="px-6 py-4 text-right font-medium text-slate-700">{row.total}</td>
                                    <td className="px-6 py-4 text-right text-emerald-600 font-medium">{row.patuh}</td>
                                    <td className="px-6 py-4 text-right text-amber-600 font-medium">{row.dalam_toleransi}</td>
                                    <td className="px-6 py-4 text-right text-rose-600 font-medium">{row.tidak_patuh}</td>
                                    <td className="px-6 py-4 text-right text-blue-500 font-medium">{row.belum_jatuh_tempo ?? 0}</td>
                                    <td className="px-6 py-4 text-right text-slate-500">{row.pasif}</td>
                                    <td className="px-6 py-4 text-right whitespace-nowrap">
                                        <div className="flex items-center justify-end gap-2">
                                            {row.persentase_patuh !== null && (
                                                <div className="w-16 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                                    <div className="h-full bg-teal-500 rounded-full" style={{ width: `${row.persentase_patuh}%` }} />
                                                </div>
                                            )}
                                            <span className="font-semibold text-teal-700 w-12">{formatPersen(row.persentase_patuh)}</span>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {rekapFktp.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="px-6 py-12 text-center text-slate-500">
                                        Tidak ada data rekap yang ditemukan.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <p className="text-xs text-slate-500 mt-3">
                % Kepatuhan = Patuh ÷ (Patuh + Tidak Patuh). Pasien Dalam Toleransi, Belum Jatuh Tempo, dan Pasif belum dinilai
                sehingga tidak masuk perhitungan.
            </p>
        </AppLayout>
    );
}
