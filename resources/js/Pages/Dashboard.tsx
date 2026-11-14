import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { DashboardStats } from '@/types';
import { motion } from 'motion/react';
import {
    Users,
    CheckCircle2,
    AlertTriangle,
    TrendingUp,
    Filter,
    X,
} from 'lucide-react';
import { useState, useEffect } from 'react';
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
    Line,
    ComposedChart,
    ReferenceLine,
} from 'recharts';

interface TrenItem {
    periode: string; // YYYY-MM
    bulan: string;
    patuh: number;
    tidak_patuh: number;
    persentase: number | null; // null = belum ada pasien yang dinilai
}

interface PeriodeOption {
    value: string;
    label: string;
}

interface Props {
    statistik: DashboardStats;
    tanggalEvaluasi: string;
    trenKepatuhan: TrenItem[];
    fktpOptions: string[];
    periodeOptions: PeriodeOption[];
    filters: {
        filter_fktp: string;
        filter_periode: string;
    };
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

/** Tooltip grafik per FKTP: % kepatuhan lalu jumlah Patuh & Tidak Teratur. */
function TooltipFktp({ active, payload, label }: { active?: boolean; payload?: any[]; label?: string }) {
    if (!active || !payload?.length) return null;
    const f = payload[0].payload as { patuh: number; tidak_patuh: number; persentase: number };
    const dinilai = f.patuh + f.tidak_patuh;

    return (
        <div className="bg-white rounded-xl px-3 py-2 text-[13px]" style={{ boxShadow: '0 4px 20px rgba(0,0,0,0.1)' }}>
            <p className="text-slate-700 mb-1">{label}</p>
            <p className="text-teal-700">Kepatuhan : {dinilai > 0 ? `${f.persentase}%` : '-'}</p>
            <p className="text-emerald-600">Patuh : {f.patuh}</p>
            <p className="text-rose-600">Tidak Teratur : {f.tidak_patuh}</p>
        </div>
    );
}

export default function Dashboard({
    statistik,
    trenKepatuhan,
    fktpOptions,
    periodeOptions,
    filters,
}: Props) {
    const [filterFktp, setFilterFktp]       = useState(filters.filter_fktp);
    const [filterPeriode, setFilterPeriode] = useState(filters.filter_periode);

    const applyFilters = (fktp: string, periode: string) => {
        const params: Record<string, string> = {};
        if (fktp)    params.filter_fktp    = fktp;
        if (periode) params.filter_periode = periode;
        router.get('/', params, { preserveState: true, preserveScroll: true });
    };

    const handleFktpChange = (val: string) => {
        setFilterFktp(val);
        applyFilters(val, filterPeriode);
    };

    const handlePeriodeChange = (val: string) => {
        setFilterPeriode(val);
        applyFilters(filterFktp, val);
    };

    const resetFilters = () => {
        setFilterFktp('');
        setFilterPeriode('');
        router.get('/', {}, { preserveState: true, preserveScroll: true });
    };

    const hasFilter = filterFktp || filterPeriode;

    const handleCardClick = (filterKepatuhan: string) => {
        const params: Record<string, string> = {};
        if (filterKepatuhan) params.filter_kepatuhan = filterKepatuhan;
        if (filterFktp)      params.filter_fktp      = filterFktp;
        if (filterPeriode)   params.filter_periode   = filterPeriode;
        router.get('/pasien', params);
    };

    const kpiCards = [
        {
            title: 'Total Pasien',
            value: statistik.total_pasien,
            subtitle: 'Seluruh pasien terdaftar',
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
            title: 'Tidak Teratur',
            value: statistik.tidak_patuh,
            subtitle: 'Butuh tindak lanjut',
            icon: AlertTriangle,
            gradient: 'from-rose-500 to-rose-600',
            shadowColor: 'shadow-rose-500/25',
            filterKepatuhan: 'Tidak Teratur',
        },
    ];

    // Donut chart data
    const donutData = [
        { name: 'Patuh',       value: statistik.patuh,          color: '#10b981' },
        { name: 'Tidak Teratur', value: statistik.tidak_patuh,    color: '#f43f5e' },
    ].filter(d => d.value > 0);


    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />

            {/* Header + Filter */}
            <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-800">Dashboard Monitoring</h1>
                    <p className="text-sm text-slate-500 mt-1">Ringkasan kepatuhan pengambilan obat PRB</p>
                </div>

                {/* Filter Bar */}
                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex items-center gap-1.5 text-xs text-slate-500">
                        <Filter className="w-3.5 h-3.5" />
                        <span>Filter:</span>
                    </div>

                    {/* Filter FKTP */}
                    <select
                        value={filterFktp}
                        onChange={e => handleFktpChange(e.target.value)}
                        className="text-sm border border-slate-300 rounded-lg px-3 py-1.5 bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                    >
                        <option value="">Semua FKTP</option>
                        {fktpOptions.map(f => (
                            <option key={f} value={f}>{f}</option>
                        ))}
                    </select>

                    {/* Filter Periode */}
                    <select
                        value={filterPeriode}
                        onChange={e => handlePeriodeChange(e.target.value)}
                        className="text-sm border border-slate-300 rounded-lg px-3 py-1.5 bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                    >
                        <option value="">Semua Periode</option>
                        {periodeOptions.map(p => (
                            <option key={p.value} value={p.value}>{p.label}</option>
                        ))}
                    </select>

                    {/* Reset */}
                    {hasFilter && (
                        <button
                            onClick={resetFilters}
                            className="flex items-center gap-1 text-xs text-rose-600 hover:text-rose-800 border border-rose-200 rounded-lg px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 transition"
                        >
                            <X className="w-3.5 h-3.5" />
                            Reset
                        </button>
                    )}
                </div>
            </div>

            {/* Filter active badge */}
            {hasFilter && (
                <div className="flex flex-wrap gap-2 mb-4">
                    {filterFktp && (
                        <span className="inline-flex items-center gap-1.5 text-xs bg-teal-50 border border-teal-200 text-teal-700 rounded-full px-3 py-1">
                            FKTP: <strong>{filterFktp}</strong>
                        </span>
                    )}
                    {filterPeriode && (
                        <span className="inline-flex items-center gap-1.5 text-xs bg-teal-50 border border-teal-200 text-teal-700 rounded-full px-3 py-1">
                            Periode: <strong>{periodeOptions.find(p => p.value === filterPeriode)?.label ?? filterPeriode}</strong>
                        </span>
                    )}
                </div>
            )}

            {/* KPI Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
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

            {/* Charts Row 1: Donut + Bar FKTP */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
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
                        <div className="flex items-center justify-center h-60 text-slate-400 text-sm">Belum ada data</div>
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
                                {/* % kepatuhan muncul saat hover, seperti grafik tren:
                                    Patuh ÷ (Patuh + Tidak Teratur), sama dengan Rekap Kepatuhan */}
                                <Tooltip content={<TooltipFktp />} cursor={{ fill: '#f8fafc' }} />
                                <Bar dataKey="patuh" name="Patuh" fill="#10b981" radius={[4, 4, 0, 0]} />
                                <Bar dataKey="tidak_patuh" name="Tidak Teratur" fill="#f43f5e" radius={[4, 4, 0, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    ) : (
                        <div className="flex items-center justify-center h-60 text-slate-400 text-sm">Belum ada data</div>
                    )}
                </motion.div>
            </div>

            {/* Chart Row 2: Tren Kepatuhan */}
            <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.4, delay: 0.5 }}
                className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 mb-6"
            >
                <div className="flex items-center justify-between mb-4">
                    <div>
                        <h3 className="text-sm font-semibold text-slate-700">
                            {/* Rentang mengikuti data tren: periode penelitian (mis. Mar 2026 – Mei 2026) atau 12 bulan */}
                            Tren Kepatuhan{trenKepatuhan.length > 0 ? ` ${trenKepatuhan[0].bulan} – ${trenKepatuhan[trenKepatuhan.length - 1].bulan}` : ''}
                        </h3>
                        <p className="text-xs text-slate-400 mt-0.5">Persentase pasien patuh per siklus bulanan (sama dengan KPI saat filter periode dipilih)</p>
                    </div>
                </div>
                {trenKepatuhan.some(t => t.patuh + t.tidak_patuh > 0) ? (
                    <ResponsiveContainer width="100%" height={280}>
                        <ComposedChart data={trenKepatuhan} margin={{ top: 5, right: 20, left: -10, bottom: 5 }}>
                            <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                            <XAxis
                                dataKey="bulan"
                                tick={{ fontSize: 11, fill: '#64748b' }}
                                interval={0}
                                angle={-15}
                                textAnchor="end"
                                height={50}
                            />
                            {/* Sumbu kiri: persentase (garis) */}
                            <YAxis
                                yAxisId="persen"
                                tick={{ fontSize: 11, fill: '#64748b' }}
                                domain={[0, 100]}
                                tickFormatter={(v) => `${v}%`}
                            />
                            {/* Sumbu kanan: jumlah pasien (batang) */}
                            <YAxis
                                yAxisId="jumlah"
                                orientation="right"
                                tick={{ fontSize: 11, fill: '#94a3b8' }}
                                allowDecimals={false}
                            />
                            {/* Penanda bulan yang dipilih di filter periode */}
                            {filterPeriode && trenKepatuhan.some(t => t.periode === filterPeriode) && (
                                <ReferenceLine
                                    yAxisId="persen"
                                    x={trenKepatuhan.find(t => t.periode === filterPeriode)!.bulan}
                                    stroke="#0d9488"
                                    strokeDasharray="4 4"
                                />
                            )}
                            <Tooltip
                                contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 4px 20px rgba(0,0,0,0.1)', fontSize: '13px' }}
                                formatter={(value, name) => {
                                    if (name === 'persentase') return [value === null ? '-' : `${value}%`, 'Kepatuhan'];
                                    if (name === 'patuh')      return [value, 'Patuh'];
                                    if (name === 'tidak_patuh') return [value, 'Tidak Teratur'];
                                    return [value, name];
                                }}
                            />
                            <Legend
                                formatter={(value) => {
                                    if (value === 'persentase') return <span className="text-xs text-slate-600">% Kepatuhan</span>;
                                    if (value === 'patuh')      return <span className="text-xs text-slate-600">Patuh</span>;
                                    if (value === 'tidak_patuh') return <span className="text-xs text-slate-600">Tidak Teratur</span>;
                                    return value;
                                }}
                            />
                            {/* Batang patuh & tidak teratur sebagai konteks */}
                            <Bar yAxisId="jumlah" dataKey="patuh"       name="patuh"       fill="#10b981" fillOpacity={0.2} radius={[3, 3, 0, 0]} />
                            <Bar yAxisId="jumlah" dataKey="tidak_patuh" name="tidak_patuh" fill="#f43f5e" fillOpacity={0.2} radius={[3, 3, 0, 0]} />
                            {/* Garis persentase kepatuhan — axis utama */}
                            <Line
                                yAxisId="persen"
                                type="monotone"
                                dataKey="persentase"
                                name="persentase"
                                stroke="#10b981"
                                strokeWidth={2.5}
                                dot={{ r: 4, fill: '#10b981', strokeWidth: 0 }}
                                activeDot={{ r: 6 }}
                            />
                        </ComposedChart>
                    </ResponsiveContainer>
                ) : (
                    <div className="flex items-center justify-center h-60 text-slate-400 text-sm">
                        Belum ada data riwayat untuk ditampilkan
                    </div>
                )}
            </motion.div>
        </AppLayout>
    );
}
