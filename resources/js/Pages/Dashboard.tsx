import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { DashboardStats, PasienPRB, STATUS_KEPATUHAN_CONFIG } from '@/types';
import { motion } from 'motion/react';
import {
    Users,
    CheckCircle2,
    AlertTriangle,
    Clock,
    CalendarDays,
    TrendingUp,
    UserMinus,
} from 'lucide-react';
import { useState, useEffect, useCallback } from 'react';
import {
    BarChart,
    Bar,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
    PieChart,
    Pie,
    Cell,
    Legend,
} from 'recharts';

interface Props {
    statistik: DashboardStats;
    pasienAktif: PasienPRB[];
    tanggalEvaluasi: string;
}

function AnimatedNumber({ value, duration = 1000 }: { value: number; duration?: number }) {
    const [display, setDisplay] = useState(0);
    useEffect(() => {
        let start = 0;
        const increment = value / (duration / 16);
        const timer = setInterval(() => {
            start += increment;
            if (start >= value) {
                setDisplay(value);
                clearInterval(timer);
            } else {
                setDisplay(Math.floor(start));
            }
        }, 16);
        return () => clearInterval(timer);
    }, [value, duration]);
    return <>{display}</>;
}

const CHART_COLORS = ['#10b981', '#f43f5e', '#f59e0b', '#6366f1', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#06b6d4', '#84cc16'];

export default function Dashboard({ statistik, pasienAktif, tanggalEvaluasi }: Props) {
    const [evalDate, setEvalDate] = useState(tanggalEvaluasi);

    const handleDateChange = useCallback((date: string) => {
        setEvalDate(date);
        router.get('/', { tanggal_evaluasi: date }, { preserveState: true, preserveScroll: true });
    }, []);

    const kpiCards = [
        {
            title: 'Total Pasien',
            value: statistik.total_pasien,
            subtitle: `${statistik.total_aktif} Aktif · ${statistik.total_pasif} Pasif`,
            icon: Users,
            gradient: 'from-slate-600 to-slate-700',
            shadowColor: 'shadow-slate-500/20',
            filterKepatuhan: '',
        },
        {
            title: 'Patuh',
            value: statistik.patuh,
            subtitle: `${statistik.persentase_patuh}% kepatuhan`,
            icon: CheckCircle2,
            gradient: 'from-emerald-500 to-emerald-600',
            shadowColor: 'shadow-emerald-500/25',
            filterKepatuhan: 'Patuh',
        },
        {
            title: 'Tidak Patuh',
            value: statistik.tidak_patuh,
            subtitle: 'Butuh tindak lanjut',
            icon: AlertTriangle,
            gradient: 'from-rose-500 to-rose-600',
            shadowColor: 'shadow-rose-500/25',
            filterKepatuhan: 'Tidak Patuh',
        },
        {
            title: 'Dalam Toleransi',
            value: statistik.dalam_toleransi,
            subtitle: 'Rentang H-3 s.d. H+3',
            icon: Clock,
            gradient: 'from-amber-500 to-amber-600',
            shadowColor: 'shadow-amber-500/25',
            filterKepatuhan: 'Dalam Toleransi',
        },
    ];

    const handleCardClick = (filterKepatuhan: string) => {
        if (filterKepatuhan) {
            router.get('/pasien', { filter_kepatuhan: filterKepatuhan, tanggal_evaluasi: evalDate });
        } else {
            router.get('/pasien', { tanggal_evaluasi: evalDate });
        }
    };

    // Donut chart data
    const donutData = [
        { name: 'Patuh', value: statistik.patuh, color: '#10b981' },
        { name: 'Tidak Patuh', value: statistik.tidak_patuh, color: '#f43f5e' },
        { name: 'Toleransi', value: statistik.dalam_toleransi, color: '#f59e0b' },
    ].filter(d => d.value > 0);

    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />

            {/* Header: Tanggal Evaluasi */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-800">Dashboard Monitoring</h1>
                    <p className="text-sm text-slate-500 mt-1">Ringkasan kepatuhan pengambilan obat PRB</p>
                </div>
                <div className="flex items-center gap-2 bg-white rounded-xl border border-slate-200 px-4 py-2.5 shadow-sm">
                    <CalendarDays className="w-4 h-4 text-teal-600" />
                    <label className="text-xs font-medium text-slate-500 whitespace-nowrap">Tanggal Evaluasi:</label>
                    <input
                        type="date"
                        value={evalDate}
                        onChange={(e) => handleDateChange(e.target.value)}
                        className="text-sm font-semibold text-slate-800 border-0 bg-transparent focus:outline-none focus:ring-0 cursor-pointer"
                    />
                </div>
            </div>

            {/* KPI Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                {kpiCards.map((card, idx) => (
                    <motion.div
                        key={card.title}
                        initial={{ opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.4, delay: idx * 0.08 }}
                        onClick={() => handleCardClick(card.filterKepatuhan)}
                        className={`relative overflow-hidden rounded-2xl bg-gradient-to-br ${card.gradient} text-white p-5 cursor-pointer group shadow-lg ${card.shadowColor} hover:scale-[1.02] transition-transform duration-200`}
                    >
                        <div className="absolute top-0 right-0 w-24 h-24 bg-white/10 rounded-full -translate-y-8 translate-x-8 group-hover:scale-110 transition-transform duration-300" />
                        <div className="relative z-10">
                            <div className="flex items-center justify-between mb-3">
                                <card.icon className="w-5 h-5 text-white/80" />
                                <TrendingUp className="w-4 h-4 text-white/40" />
                            </div>
                            <div className="text-3xl font-extrabold tracking-tight">
                                <AnimatedNumber value={card.value} />
                            </div>
                            <div className="text-sm font-semibold text-white/90 mt-1">{card.title}</div>
                            <div className="text-xs text-white/60 mt-0.5">{card.subtitle}</div>
                        </div>
                    </motion.div>
                ))}
            </div>

            {/* Charts */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                {/* Donut Chart */}
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.3 }}
                    className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6"
                >
                    <h3 className="text-sm font-semibold text-slate-700 mb-4">Rasio Kepatuhan</h3>
                    {donutData.length > 0 ? (
                        <ResponsiveContainer width="100%" height={260}>
                            <PieChart>
                                <Pie
                                    data={donutData}
                                    cx="50%"
                                    cy="50%"
                                    innerRadius={55}
                                    outerRadius={90}
                                    paddingAngle={4}
                                    dataKey="value"
                                    stroke="none"
                                >
                                    {donutData.map((entry, index) => (
                                        <Cell key={index} fill={entry.color} />
                                    ))}
                                </Pie>
                                <Tooltip
                                    contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 4px 20px rgba(0,0,0,0.1)', fontSize: '13px' }}
                                />
                                <Legend
                                    verticalAlign="bottom"
                                    height={36}
                                    iconType="circle"
                                    formatter={(value) => <span className="text-xs text-slate-600">{value}</span>}
                                />
                            </PieChart>
                        </ResponsiveContainer>
                    ) : (
                        <div className="flex items-center justify-center h-60 text-slate-400 text-sm">
                            Belum ada data
                        </div>
                    )}
                </motion.div>

                {/* Bar Chart: Per FKTP */}
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.4 }}
                    className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 lg:col-span-2"
                >
                    <h3 className="text-sm font-semibold text-slate-700 mb-4">Kepatuhan per FKTP Perujuk</h3>
                    {statistik.per_fktp.length > 0 ? (
                        <ResponsiveContainer width="100%" height={260}>
                            <BarChart data={statistik.per_fktp} margin={{ top: 5, right: 10, left: -10, bottom: 5 }}>
                                <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                                <XAxis
                                    dataKey="fktp"
                                    tick={{ fontSize: 11, fill: '#64748b' }}
                                    interval={0}
                                    angle={-15}
                                    textAnchor="end"
                                    height={60}
                                />
                                <YAxis tick={{ fontSize: 11, fill: '#64748b' }} allowDecimals={false} />
                                <Tooltip
                                    contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 4px 20px rgba(0,0,0,0.1)', fontSize: '13px' }}
                                />
                                <Bar dataKey="patuh" name="Patuh" fill="#14b8a6" radius={[4, 4, 0, 0]} />
                                <Bar dataKey="tidak_patuh" name="Tidak Patuh" fill="#f97316" radius={[4, 4, 0, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    ) : (
                        <div className="flex items-center justify-center h-60 text-slate-400 text-sm">
                            Belum ada data
                        </div>
                    )}
                </motion.div>
            </div>

            {/* Overdue Patient Alert Table */}
            {statistik.tidak_patuh > 0 && (
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.6 }}
                    className="bg-white rounded-2xl border border-rose-200 shadow-sm overflow-hidden"
                >
                    <div className="px-6 py-4 bg-gradient-to-r from-rose-50 to-rose-100 border-b border-rose-200">
                        <h3 className="text-sm font-semibold text-rose-700 flex items-center gap-2">
                            <AlertTriangle className="w-4 h-4" />
                            Pasien Tidak Patuh — Butuh Tindak Lanjut Segera
                        </h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-100">
                                    <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Pasien</th>
                                    <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Diagnosis</th>
                                    <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Jadwal</th>
                                    <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Keterlambatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                {pasienAktif
                                    .filter(p => p.status_kepatuhan === 'Tidak Patuh')
                                    .slice(0, 10)
                                    .map((p) => (
                                        <tr key={p.id} className="border-b border-slate-50 hover:bg-rose-50/50 transition-colors">
                                            <td className="px-4 py-3">
                                                <div className="font-medium text-slate-800">{p.nama_pasien}</div>
                                                <div className="text-xs text-slate-400">{p.kode_pasien}</div>
                                            </td>
                                            <td className="px-4 py-3 text-slate-600">{p.diagnosis_prb}</td>
                                            <td className="px-4 py-3 text-slate-600">
                                                {p.tanggal_jadwal_berikutnya ? new Date(p.tanggal_jadwal_berikutnya).toLocaleDateString('id-ID') : '-'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-rose-100 text-rose-700 text-xs font-semibold">
                                                    {p.selisih_hari_evaluasi} hari
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                            </tbody>
                        </table>
                    </div>
                </motion.div>
            )}
        </AppLayout>
    );
}
