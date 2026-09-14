import { Head, router } from '@inertiajs/react';
import { useState, useCallback, useMemo } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PasienPRB, Filters, DiagnosisPRB, STATUS_KEPATUHAN_CONFIG, StatusKepatuhan } from '@/types';
import { Button } from '@/Components/ui/Button';
import { StatusBadge } from '@/Components/ui/Badge';
import { WhatsAppButton } from '@/Components/Pasien/WhatsAppButton';
import { QuickRealisasiModal } from '@/Components/Pasien/QuickRealisasiModal';
import { PatientDetailModal } from '@/Components/Pasien/PatientDetailModal';
import { AddPatientModal } from '@/Components/Pasien/AddPatientModal';
import { ImportExcelModal } from '@/Components/Pasien/ImportExcelModal';
import { exportToExcel } from '@/lib/excel-utils';
import { Search, Filter, Plus, FileSpreadsheet, Download, RefreshCw, CalendarDays, MoreVertical } from 'lucide-react';
import { motion, AnimatePresence } from 'motion/react';

interface Props {
    pasienList: PasienPRB[];
    tanggalEvaluasi: string;
    filters: Filters;
    diagnosisOptions: DiagnosisPRB[];
    alasanPasifOptions: string[];
}

export default function PasienIndex({ pasienList, tanggalEvaluasi, filters, diagnosisOptions }: Props) {
    const [searchTerm, setSearchTerm] = useState(filters.search);
    const [evalDate, setEvalDate] = useState(tanggalEvaluasi);
    const [showFilters, setShowFilters] = useState(false);

    // Modal states
    const [addModalOpen, setAddModalOpen] = useState(false);
    const [importModalOpen, setImportModalOpen] = useState(false);
    const [detailPasienId, setDetailPasienId] = useState<string | null>(null);
    const [realisasiPasien, setRealisasiPasien] = useState<PasienPRB | null>(null);

    // Apply filters
    const applyFilters = useCallback((newFilters: Partial<Filters> = {}, overrideEvalDate?: string) => {
        router.get('/pasien', {
            ...filters,
            search: searchTerm,
            tanggal_evaluasi: overrideEvalDate || evalDate,
            ...newFilters,
        }, { preserveState: true, preserveScroll: true });
    }, [filters, searchTerm, evalDate]);

    // Handle search input with debounce
    const handleSearch = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            applyFilters();
        }
    };

    const handleExport = async () => {
        try {
            const res = await fetch(`/pasien-export?tanggal_evaluasi=${evalDate}`);
            const data = await res.json();
            exportToExcel(data, `Laporan_PRB_${evalDate}.xlsx`);
        } catch (e) {
            alert('Gagal mengekspor data.');
        }
    };

    // Filter status options
    const statusKepatuhanOptions: StatusKepatuhan[] = ['Patuh', 'Tidak Patuh', 'Dalam Toleransi', 'Belum Jatuh Tempo'];

    return (
        <AppLayout title="Data Pasien PRB">
            <Head title="Data Pasien PRB" />

            {/* Header Actions */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div className="flex-1 max-w-md">
                    <div className="relative group">
                        <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <Search className="w-5 h-5 text-slate-400 group-focus-within:text-teal-500 transition-colors" />
                        </div>
                        <input
                            type="text"
                            placeholder="Cari nama, No BPJS, atau Kode PRB... (Tekan Enter)"
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            onKeyDown={handleSearch}
                            className="block w-full pl-10 pr-4 py-2.5 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 shadow-sm transition-all"
                        />
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex items-center gap-2 bg-white rounded-xl border border-slate-200 px-3 py-2 shadow-sm mr-2">
                        <CalendarDays className="w-4 h-4 text-teal-600" />
                        <input
                            type="date"
                            value={evalDate}
                            onChange={(e) => {
                                setEvalDate(e.target.value);
                                applyFilters({}, e.target.value);
                            }}
                            className="text-sm font-medium text-slate-700 border-0 bg-transparent focus:outline-none focus:ring-0 p-0 w-[115px] cursor-pointer"
                            title="Tanggal Evaluasi Kepatuhan"
                        />
                    </div>

                    <Button variant="outline" onClick={() => setShowFilters(!showFilters)} className={showFilters ? 'bg-slate-100' : ''}>
                        <Filter className="w-4 h-4 mr-2" /> Filter
                    </Button>
                    <div className="hidden sm:block w-px h-8 bg-slate-200 mx-1"></div>
                    <Button variant="outline" onClick={() => setImportModalOpen(true)}>
                        <FileSpreadsheet className="w-4 h-4 text-emerald-600 mr-2" /> Import
                    </Button>
                    <Button variant="outline" onClick={handleExport}>
                        <Download className="w-4 h-4 mr-2" /> Export
                    </Button>
                    <Button onClick={() => setAddModalOpen(true)}>
                        <Plus className="w-4 h-4 mr-1" /> Tambah
                    </Button>
                </div>
            </div>

            {/* Filter Panel */}
            <AnimatePresence>
                {showFilters && (
                    <motion.div
                        initial={{ height: 0, opacity: 0 }}
                        animate={{ height: 'auto', opacity: 1 }}
                        exit={{ height: 0, opacity: 0 }}
                        className="overflow-hidden mb-6"
                    >
                        <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-sm grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Status Pasien</label>
                                <select
                                    value={filters.filter_status}
                                    onChange={(e) => applyFilters({ filter_status: e.target.value })}
                                    className="w-full text-sm border-slate-300 rounded-lg focus:ring-teal-500 focus:border-teal-500"
                                >
                                    <option value="">Semua Status</option>
                                    <option value="Aktif">Aktif</option>
                                    <option value="Pasif">Pasif</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Diagnosis PRB</label>
                                <select
                                    value={filters.filter_diagnosis}
                                    onChange={(e) => applyFilters({ filter_diagnosis: e.target.value })}
                                    className="w-full text-sm border-slate-300 rounded-lg focus:ring-teal-500 focus:border-teal-500"
                                >
                                    <option value="">Semua Diagnosis</option>
                                    {diagnosisOptions.map(d => <option key={d} value={d}>{d}</option>)}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Status Kepatuhan</label>
                                <select
                                    value={filters.filter_kepatuhan}
                                    onChange={(e) => applyFilters({ filter_kepatuhan: e.target.value })}
                                    className="w-full text-sm border-slate-300 rounded-lg focus:ring-teal-500 focus:border-teal-500"
                                >
                                    <option value="">Semua Kepatuhan</option>
                                    {statusKepatuhanOptions.map(s => <option key={s} value={s}>{s}</option>)}
                                </select>
                            </div>
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* Data Table */}
            <div className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm whitespace-nowrap">
                        <thead className="bg-slate-50/80 border-b border-slate-200 text-slate-500 font-semibold text-xs uppercase tracking-wider">
                            <tr>
                                <th className="px-5 py-4">Pasien & Jenis</th>
                                <th className="px-5 py-4">Diagnosis & Asal</th>
                                <th className="px-5 py-4">Jadwal Pengambilan</th>
                                <th className="px-5 py-4">Status Kepatuhan</th>
                                <th className="px-5 py-4 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {pasienList.length > 0 ? (
                                pasienList.map((pasien, idx) => (
                                    <motion.tr
                                        key={pasien.id}
                                        initial={{ opacity: 0, y: 10 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={{ duration: 0.2, delay: Math.min(idx * 0.05, 0.5) }}
                                        className="hover:bg-slate-50 transition-colors group"
                                    >
                                        <td className="px-5 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-600 font-bold shrink-0 group-hover:bg-teal-50 group-hover:text-teal-600 transition-colors">
                                                    {pasien.nama_pasien.charAt(0)}
                                                </div>
                                                <div>
                                                    <div className="font-semibold text-slate-800 flex items-center gap-2">
                                                        {pasien.nama_pasien}
                                                        <span className={`text-[10px] px-1.5 py-0.5 rounded-sm border font-medium uppercase tracking-wider ${
                                                            pasien.jenis_prb === 'Baru' 
                                                                ? 'bg-blue-50 text-blue-600 border-blue-200' 
                                                                : 'bg-slate-100 text-slate-600 border-slate-200'
                                                        }`}>
                                                            PRB {pasien.jenis_prb}
                                                        </span>
                                                    </div>
                                                    <div className="text-xs text-slate-500">{pasien.kode_pasien} • {pasien.no_kartu_bpjs}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-5 py-4">
                                            <div className="font-medium text-slate-700">{pasien.diagnosis_prb}</div>
                                            <div className="text-xs text-slate-500 truncate max-w-[200px]">{pasien.fktp_asal}</div>
                                        </td>
                                        <td className="px-5 py-4">
                                            {pasien.status === 'Pasif' ? (
                                                <span className="text-slate-400 text-xs italic">Pasien Non-Aktif</span>
                                            ) : (
                                                <>
                                                    <div className="font-medium text-slate-800">
                                                        {pasien.tanggal_jadwal_berikutnya ? new Date(pasien.tanggal_jadwal_berikutnya).toLocaleDateString('id-ID') : '-'}
                                                    </div>
                                                    <div className="text-xs text-slate-500">
                                                        Terkahir: {pasien.tanggal_pengambilan_terakhir ? new Date(pasien.tanggal_pengambilan_terakhir).toLocaleDateString('id-ID') : '-'}
                                                    </div>
                                                </>
                                            )}
                                        </td>
                                        <td className="px-5 py-4">
                                            <StatusBadge status={pasien.status_kepatuhan || 'Belum Ada Jadwal'} />
                                            {pasien.selisih_hari_evaluasi !== undefined && pasien.status_kepatuhan !== 'Pasif' && (
                                                <div className="text-[10px] text-slate-400 mt-1">
                                                    Selisih: {pasien.selisih_hari_evaluasi} hari
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-5 py-4 text-center">
                                            <div className="flex items-center justify-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                                {pasien.status === 'Aktif' && (
                                                    <button
                                                        onClick={() => setRealisasiPasien(pasien)}
                                                        className="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50 transition-colors"
                                                        title="Catat Realisasi Pengambilan"
                                                    >
                                                        <RefreshCw className="w-5 h-5" />
                                                    </button>
                                                )}
                                                <WhatsAppButton pasien={pasien} tanggalEvaluasi={evalDate} variant="icon" />
                                                <button
                                                    onClick={() => setDetailPasienId(pasien.id)}
                                                    className="p-1.5 rounded-lg text-slate-600 hover:bg-slate-100 transition-colors"
                                                    title="Detail Pasien"
                                                >
                                                    <MoreVertical className="w-5 h-5" />
                                                </button>
                                            </div>
                                        </td>
                                    </motion.tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={5} className="px-5 py-16 text-center text-slate-500">
                                        <div className="flex flex-col items-center">
                                            <Search className="w-10 h-10 text-slate-300 mb-3" />
                                            <p className="text-base font-medium">Tidak ada data pasien ditemukan.</p>
                                            <p className="text-sm mt-1">Coba sesuaikan kata kunci atau filter pencarian Anda.</p>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                
                {pasienList.length > 0 && (
                    <div className="px-5 py-4 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between text-sm text-slate-500">
                        Menampilkan {pasienList.length} pasien
                    </div>
                )}
            </div>

            {/* Modals */}
            {addModalOpen && <AddPatientModal isOpen={addModalOpen} onClose={() => setAddModalOpen(false)} />}
            {importModalOpen && <ImportExcelModal isOpen={importModalOpen} onClose={() => setImportModalOpen(false)} />}
            {realisasiPasien && (
                <QuickRealisasiModal 
                    isOpen={!!realisasiPasien} 
                    onClose={() => setRealisasiPasien(null)} 
                    pasien={realisasiPasien} 
                />
            )}
            <PatientDetailModal 
                isOpen={!!detailPasienId} 
                onClose={() => setDetailPasienId(null)} 
                pasienId={detailPasienId} 
                tanggalEvaluasi={evalDate}
            />
        </AppLayout>
    );
}
