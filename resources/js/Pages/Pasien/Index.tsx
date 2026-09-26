import { Head, router } from '@inertiajs/react';
import { useState, useCallback, useMemo } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import {
    PasienPRB,
    Filters,
    DiagnosisPRB,
    StatusKepatuhan,
} from '@/types';
import { Button } from '@/Components/ui/Button';
import { StatusBadge } from '@/Components/ui/Badge';
import { WhatsAppButton } from '@/Components/Pasien/WhatsAppButton';
import { QuickRealisasiModal } from '@/Components/Pasien/QuickRealisasiModal';
import { PatientDetailModal } from '@/Components/Pasien/PatientDetailModal';
import { AddPatientModal } from '@/Components/Pasien/AddPatientModal';
import { ImportExcelModal } from '@/Components/Pasien/ImportExcelModal';
import { exportToExcel } from '@/lib/excel-utils';
import {
    Search,
    Filter,
    Plus,
    FileSpreadsheet,
    Download,
    RefreshCw,
    CalendarDays,
    MoreVertical,
    Trash2,
    AlertTriangle,
    CheckSquare,
    X,
} from 'lucide-react';
import { motion, AnimatePresence } from 'motion/react';

interface Props {
    pasienList: PasienPRB[];
    tanggalEvaluasi: string;
    filters: Filters;
    diagnosisOptions: DiagnosisPRB[];
    alasanPasifOptions: string[];
    fktpOptions: string[];
    periodeOptions: { value: string; label: string }[];
}

export default function PasienIndex({
    pasienList,
    tanggalEvaluasi,
    filters,
    diagnosisOptions,
    fktpOptions,
    periodeOptions,
}: Props) {
    const [searchTerm, setSearchTerm] = useState(filters.search);
    const [evalDate, setEvalDate] = useState(tanggalEvaluasi);
    const [showFilters, setShowFilters] = useState(false);

    // Modal states
    const [addModalOpen, setAddModalOpen] = useState(false);
    const [importModalOpen, setImportModalOpen] = useState(false);
    const [detailPasienId, setDetailPasienId] = useState<string | null>(null);
    const [realisasiPasien, setRealisasiPasien] =
        useState<PasienPRB | null>(null);
    const [deletePasien, setDeletePasien] =
        useState<PasienPRB | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    // Inline edit schedule
    const [editingScheduleId, setEditingScheduleId] = useState<string | null>(null);
    const [scheduleValue, setScheduleValue] = useState<string>('');

    // Bulk selection states
    const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
    const [showBulkDeleteConfirm, setShowBulkDeleteConfirm] = useState(false);
    const [isBulkDeleting, setIsBulkDeleting] = useState(false);

    // Check if all are selected
    const allSelected = useMemo(
        () => pasienList.length > 0 && selectedIds.size === pasienList.length,
        [selectedIds.size, pasienList.length]
    );
    const someSelected = useMemo(
        () => selectedIds.size > 0 && selectedIds.size < pasienList.length,
        [selectedIds.size, pasienList.length]
    );

    // Toggle single selection
    const toggleSelect = (id: string) => {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    };

    // Toggle select all
    const toggleSelectAll = () => {
        if (allSelected) {
            setSelectedIds(new Set());
        } else {
            setSelectedIds(new Set(pasienList.map((p) => p.id)));
        }
    };

    // Clear selection
    const clearSelection = () => setSelectedIds(new Set());

    // Handle delete single
    const handleDelete = () => {
        if (!deletePasien) return;
        setIsDeleting(true);
        router.delete(`/pasien/${deletePasien.id}`, {
            onSuccess: () => {
                setDeletePasien(null);
                setIsDeleting(false);
                selectedIds.delete(deletePasien.id);
                setSelectedIds(new Set(selectedIds));
            },
            onError: () => {
                setIsDeleting(false);
            },
        });
    };

    const handleEditSchedule = (pasien: PasienPRB) => {
        setEditingScheduleId(pasien.id);
        setScheduleValue(pasien.tanggal_jadwal_berikutnya || '');
    };

    const handleSaveSchedule = (pasienId: string) => {
        router.put(`/pasien/${pasienId}/jadwal`, {
            tanggal_jadwal_berikutnya: scheduleValue
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingScheduleId(null);
            }
        });
    };

    const handleCancelEditSchedule = () => {
        setEditingScheduleId(null);
    };

    // Handle bulk delete
    const handleBulkDelete = () => {
        setIsBulkDeleting(true);
        router.post(
            '/pasien/hapus-massal',
            { ids: Array.from(selectedIds) },
            {
                onSuccess: () => {
                    setShowBulkDeleteConfirm(false);
                    setIsBulkDeleting(false);
                    setSelectedIds(new Set());
                },
                onError: () => {
                    setIsBulkDeleting(false);
                },
            }
        );
    };

    // Apply filters
    const applyFilters = useCallback(
        (
            newFilters: Partial<Filters> = {},
            overrideEvalDate?: string
        ) => {
            router.get(
                '/pasien',
                {
                    ...filters,
                    search: searchTerm,
                    tanggal_evaluasi:
                        overrideEvalDate || evalDate,
                    ...newFilters,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                }
            );
        },
        [filters, searchTerm, evalDate]
    );

    // Handle search input with Enter
    const handleSearch = (
        e: React.KeyboardEvent<HTMLInputElement>
    ) => {
        if (e.key === 'Enter') {
            applyFilters();
        }
    };

    // Export data
    const handleExport = async () => {
        try {
            const queryParams = new URLSearchParams({
                tanggal_evaluasi: evalDate,
                search: filters.search || '',
                filter_status: filters.filter_status || '',
                filter_periode: filters.filter_periode || '',
                filter_kepatuhan: filters.filter_kepatuhan || '',
                filter_diagnosis: filters.filter_diagnosis || '',
                filter_fktp: filters.filter_fktp || '',
            });

            const res = await fetch(
                `/pasien-export?${queryParams.toString()}`
            );

            const data = await res.json();

            let fktpName = 'Semua_FKTP';
            if (filters.filter_fktp) {
                fktpName = filters.filter_fktp.replace(/\s+/g, '_');
            }

            exportToExcel(
                data,
                `Laporan_PRB_${fktpName}_${evalDate}.xlsx`
            );
        } catch (e) {
            alert('Gagal mengekspor data.');
        }
    };

    // Filter status options
    const statusKepatuhanOptions: StatusKepatuhan[] = [
        'Patuh',
        'Dalam Toleransi',
        'Tidak Patuh',
        'Belum Jatuh Tempo',
        'Pasif',
    ];

    // Format tanggal Indonesia
    const formatTanggal = (tanggal?: string | null) => {
        if (!tanggal) {
            return '-';
        }

        const date = new Date(tanggal);

        if (Number.isNaN(date.getTime())) {
            return '-';
        }

        return date.toLocaleDateString('id-ID', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        });
    };

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
                            onChange={(e) =>
                                setSearchTerm(e.target.value)
                            }
                            onKeyDown={handleSearch}
                            className="block w-full pl-10 pr-4 py-2.5 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 shadow-sm transition-all"
                        />
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex items-center gap-2">
                        <select
                            className="text-sm font-medium text-slate-700 bg-white border border-slate-200 rounded-xl px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 cursor-pointer h-[42px]"
                            value={filters.filter_periode || ''}
                            onChange={(e) => applyFilters({ filter_periode: e.target.value })}
                            title="Periode Monitoring"
                        >
                            <option value="">Semua Periode</option>
                            {periodeOptions?.map((opt) => (
                                <option key={opt.value} value={opt.value}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex items-center gap-2 bg-white rounded-xl border border-slate-200 px-3 py-2 shadow-sm mr-2">
                        <CalendarDays className="w-4 h-4 text-teal-600" />

                        <input
                            type="date"
                            value={evalDate}
                            onChange={(e) => {
                                setEvalDate(e.target.value);
                                applyFilters(
                                    {},
                                    e.target.value
                                );
                            }}
                            className="text-sm font-medium text-slate-700 border-0 bg-transparent focus:outline-none focus:ring-0 p-0 w-[115px] cursor-pointer"
                            title="Tanggal Evaluasi Kepatuhan"
                        />
                    </div>

                    <Button
                        variant="outline"
                        onClick={() =>
                            setShowFilters(!showFilters)
                        }
                        className={
                            showFilters
                                ? 'bg-slate-100'
                                : ''
                        }
                    >
                        <Filter className="w-4 h-4 mr-2" />
                        Filter
                    </Button>

                    <div className="hidden sm:block w-px h-8 bg-slate-200 mx-1"></div>

                    <Button
                        variant="outline"
                        onClick={() =>
                            setImportModalOpen(true)
                        }
                    >
                        <FileSpreadsheet className="w-4 h-4 text-emerald-600 mr-2" />
                        Import
                    </Button>

                    <Button
                        variant="outline"
                        onClick={handleExport}
                    >
                        <Download className="w-4 h-4 mr-2" />
                        Export
                    </Button>

                    <Button
                        onClick={() =>
                            setAddModalOpen(true)
                        }
                    >
                        <Plus className="w-4 h-4 mr-1" />
                        Tambah
                    </Button>
                </div>
            </div>

            {/* Filter Panel */}
            <AnimatePresence>
                {showFilters && (
                    <motion.div
                        initial={{
                            height: 0,
                            opacity: 0,
                        }}
                        animate={{
                            height: 'auto',
                            opacity: 1,
                        }}
                        exit={{
                            height: 0,
                            opacity: 0,
                        }}
                        className="overflow-hidden mb-6"
                    >
                        <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-sm grid grid-cols-1 md:grid-cols-4 gap-4">
                            {/* Status Pasien */}
                            <div>
                                <label className="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">
                                    Status Pasien
                                </label>

                                <select
                                    value={
                                        filters.filter_status
                                    }
                                    onChange={(e) =>
                                        applyFilters({
                                            filter_status:
                                                e.target.value,
                                        })
                                    }
                                    className="w-full text-sm border-slate-300 rounded-lg focus:ring-teal-500 focus:border-teal-500"
                                >
                                    <option value="">
                                        Semua Status
                                    </option>

                                    <option value="Aktif">
                                        Aktif
                                    </option>

                                    <option value="Pasif">
                                        Pasif
                                    </option>
                                </select>
                            </div>


                            {/* Status Kepatuhan */}
                            <div>
                                <label className="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">
                                    Status Kepatuhan
                                </label>

                                <select
                                    value={
                                        filters.filter_kepatuhan
                                    }
                                    onChange={(e) =>
                                        applyFilters({
                                            filter_kepatuhan:
                                                e.target.value,
                                        })
                                    }
                                    className="w-full text-sm border-slate-300 rounded-lg focus:ring-teal-500 focus:border-teal-500"
                                >
                                    <option value="">
                                        Semua Kepatuhan
                                    </option>

                                    {statusKepatuhanOptions.map(
                                        (s) => (
                                            <option
                                                key={s}
                                                value={s}
                                            >
                                                {s}
                                            </option>
                                        )
                                    )}
                                </select>
                            </div>

                            {/* FKTP Asal */}
                            <div>
                                <label className="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">
                                    FKTP Asal
                                </label>

                                <select
                                    value={
                                        filters.filter_fktp || ''
                                    }
                                    onChange={(e) =>
                                        applyFilters({
                                            filter_fktp:
                                                e.target.value,
                                        })
                                    }
                                    className="w-full text-sm border-slate-300 rounded-lg focus:ring-teal-500 focus:border-teal-500"
                                >
                                    <option value="">
                                        Semua FKTP
                                    </option>

                                    {fktpOptions.map(
                                        (f) => (
                                            <option
                                                key={f}
                                                value={f}
                                            >
                                                {f}
                                            </option>
                                        )
                                    )}
                                </select>
                            </div>
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* Bulk Action Toolbar */}
            <AnimatePresence>
                {selectedIds.size > 0 && (
                    <motion.div
                        initial={{ height: 0, opacity: 0 }}
                        animate={{ height: 'auto', opacity: 1 }}
                        exit={{ height: 0, opacity: 0 }}
                        className="overflow-hidden mb-4"
                    >
                        <div className="bg-rose-50 border border-rose-200 rounded-xl px-5 py-3 flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <CheckSquare className="w-5 h-5 text-rose-600" />
                                <span className="text-sm font-medium text-rose-800">
                                    {selectedIds.size} pasien dipilih
                                </span>
                                <button
                                    onClick={clearSelection}
                                    className="text-xs text-rose-600 hover:text-rose-800 underline underline-offset-2 transition-colors"
                                >
                                    Batal pilih
                                </button>
                            </div>

                            <Button
                                variant="danger"
                                size="sm"
                                onClick={() => setShowBulkDeleteConfirm(true)}
                            >
                                <Trash2 className="w-4 h-4 mr-1" />
                                Hapus {selectedIds.size} Pasien
                            </Button>
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* Data Table */}
            <div className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[1650px] text-left text-sm whitespace-nowrap">
                        <thead className="bg-slate-50/80 border-b border-slate-200 text-slate-500 font-semibold text-xs uppercase tracking-wider">
                            <tr>
                                <th className="px-3 py-4 w-12">
                                    <input
                                        type="checkbox"
                                        checked={allSelected}
                                        ref={(el) => {
                                            if (el) el.indeterminate = someSelected;
                                        }}
                                        onChange={toggleSelectAll}
                                        className="w-4 h-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500 cursor-pointer"
                                        title="Pilih semua"
                                    />
                                </th>

                                <th className="px-5 py-4 min-w-[250px]">
                                    Pasien & Jenis
                                </th>

                                <th className="px-5 py-4 min-w-[210px]">
                                    Diagnosis & Asal
                                </th>

                                <th className="px-5 py-4 min-w-[130px]">
                                    Mulai PRB
                                </th>

                                <th className="px-5 py-4 min-w-[150px]">
                                    Terakhir Ambil Obat
                                </th>

                                <th className="px-5 py-4 min-w-[150px]">
                                    Jadwal Ambil Obat
                                </th>

                                <th className="px-5 py-4 min-w-[170px]">
                                    Early Warning
                                </th>

                                <th className="px-5 py-4 min-w-[150px]">
                                    Tanggal Ambil Obat
                                </th>

                                <th className="px-5 py-4 min-w-[170px]">
                                    Status Kepatuhan
                                </th>

                                <th className="px-5 py-4 text-center min-w-[120px]">
                                    Aksi
                                </th>
                            </tr>
                        </thead>

                        <tbody className="divide-y divide-slate-100">
                            {pasienList.length > 0 ? (
                                pasienList.map(
                                    (pasien, idx) => {
                                        return (
                                            <motion.tr
                                                key={pasien.id}
                                                initial={{
                                                    opacity: 0,
                                                    y: 10,
                                                }}
                                                animate={{
                                                    opacity: 1,
                                                    y: 0,
                                                }}
                                                transition={{
                                                    duration: 0.2,
                                                    delay: Math.min(
                                                        idx *
                                                            0.05,
                                                        0.5
                                                    ),
                                                }}
                                                className={`transition-colors group ${
                                                    selectedIds.has(pasien.id)
                                                        ? 'bg-teal-50/50 hover:bg-teal-50'
                                                        : 'hover:bg-slate-50'
                                                }`}
                                            >
                                                {/* CHECKBOX */}
                                                <td className="px-3 py-4">
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedIds.has(pasien.id)}
                                                        onChange={() => toggleSelect(pasien.id)}
                                                        className="w-4 h-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500 cursor-pointer"
                                                    />
                                                </td>

                                                {/* 1. PASIEN & JENIS */}
                                                <td className="px-5 py-4">
                                                    <div className="flex items-center gap-3">
                                                        <div className="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-600 font-bold shrink-0 group-hover:bg-teal-50 group-hover:text-teal-600 transition-colors">
                                                            {pasien.nama_pasien.charAt(
                                                                0
                                                            )}
                                                        </div>

                                                        <div>
                                                            <div className="font-semibold text-slate-800 flex items-center gap-2">
                                                                {
                                                                    pasien.nama_pasien
                                                                }

                                                                <span
                                                                    className={`text-[10px] px-1.5 py-0.5 rounded-sm border font-medium uppercase tracking-wider ${
                                                                        pasien.jenis_prb ===
                                                                        'Baru'
                                                                            ? 'bg-blue-50 text-blue-600 border-blue-200'
                                                                            : 'bg-slate-100 text-slate-600 border-slate-200'
                                                                    }`}
                                                                >
                                                                    PRB{' '}
                                                                    {
                                                                        pasien.jenis_prb
                                                                    }
                                                                </span>
                                                            </div>

                                                            <div className="text-xs text-slate-500">
                                                                {
                                                                    pasien.kode_pasien
                                                                }{' '}
                                                                •{' '}
                                                                {
                                                                    pasien.no_kartu_bpjs
                                                                }
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                {/* 2. DIAGNOSIS & ASAL */}
                                                <td className="px-5 py-4">
                                                    <div className="font-medium text-slate-700">
                                                        {(() => {
                                                            let diag = pasien.diagnosis_prb;
                                                            if (typeof diag === 'string') {
                                                                try {
                                                                    const parsed = JSON.parse(diag);
                                                                    if (Array.isArray(parsed)) diag = parsed;
                                                                } catch (e) {
                                                                    // ignore and treat as plain string
                                                                }
                                                            }
                                                            return Array.isArray(diag) && diag.length > 0 
                                                                ? diag.join(', ') 
                                                                : (diag || '-');
                                                        })()}
                                                    </div>

                                                    <div className="text-xs text-slate-500 truncate max-w-[200px]">
                                                        {
                                                            pasien.fktp_asal
                                                        }
                                                    </div>
                                                </td>

                                                {/* 3. MULAI PRB */}
                                                <td className="px-5 py-4">
                                                    <div className="font-medium text-slate-800">
                                                        {formatTanggal(
                                                            pasien.tanggal_mulai_prb
                                                        )}
                                                    </div>
                                                </td>

                                                {/* 4. TERAKHIR AMBIL OBAT */}
                                                <td className="px-5 py-4">
                                                    <div
                                                        className={`font-medium ${
                                                            pasien.tanggal_pengambilan_terakhir
                                                                ? 'text-slate-800'
                                                                : 'text-slate-400 italic'
                                                        }`}
                                                    >
                                                        {pasien.tanggal_pengambilan_terakhir
                                                            ? formatTanggal(
                                                                  pasien.tanggal_pengambilan_terakhir
                                                              )
                                                            : 'Belum pernah'}
                                                    </div>
                                                </td>

                                                {/* 5. JADWAL AMBIL OBAT */}
                                                <td className="px-5 py-4">
                                                    {pasien.status === 'Pasif' ? (
                                                        <span className="text-slate-400 text-xs italic">
                                                            Pasien Non-Aktif
                                                        </span>
                                                    ) : editingScheduleId === pasien.id ? (
                                                        <div className="flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
                                                            <input
                                                                type="date"
                                                                value={scheduleValue}
                                                                onChange={(e) => setScheduleValue(e.target.value)}
                                                                className="rounded border-slate-300 text-xs py-1 px-2 focus:ring-teal-500 focus:border-teal-500 w-[125px]"
                                                            />
                                                            <button onClick={() => handleSaveSchedule(pasien.id)} className="text-teal-600 hover:text-teal-700 p-1 bg-teal-50 hover:bg-teal-100 rounded transition-colors" title="Simpan">
                                                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"></path></svg>
                                                            </button>
                                                            <button onClick={handleCancelEditSchedule} className="text-slate-400 hover:text-slate-600 p-1 bg-slate-100 hover:bg-slate-200 rounded transition-colors" title="Batal">
                                                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                                            </button>
                                                        </div>
                                                    ) : (
                                                        <div
                                                            className={`font-medium cursor-pointer hover:bg-slate-100 p-1.5 rounded -ml-1 flex items-center justify-between group transition-colors ${
                                                                pasien.tanggal_jadwal_berikutnya
                                                                    ? 'text-slate-800'
                                                                    : 'text-slate-400 italic'
                                                            }`}
                                                            onClick={(e) => { e.stopPropagation(); handleEditSchedule(pasien); }}
                                                            title="Klik untuk mengubah jadwal manual"
                                                        >
                                                            <span>
                                                                {pasien.tanggal_jadwal_berikutnya
                                                                    ? formatTanggal(
                                                                          pasien.tanggal_jadwal_berikutnya
                                                                      )
                                                                    : 'Belum ada jadwal'}
                                                            </span>
                                                            <span className="opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity ml-2">
                                                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                                            </span>
                                                        </div>
                                                    )}
                                                </td>

                                                {/* 6. EARLY WARNING */}
                                                <td className="px-5 py-4">
                                                    {(() => {
                                                        if (pasien.status === 'Pasif') {
                                                            return <span className="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded-full border text-slate-500 bg-slate-100 border-slate-200">Tidak Berlaku</span>;
                                                        }

                                                        const ew = pasien.early_warning;
                                                        if (!ew || ew === '-') {
                                                            return <span className="text-slate-400 italic text-xs">Tidak tersedia</span>;
                                                        }

                                                        let ewColor = 'text-slate-500 bg-slate-50 border-slate-200';
                                                        let ewLabel = ew;
                                                        let ewIcon = '';

                                                        if (ew === 'Biru - Belum memasuki jadwal') {
                                                            ewColor = 'text-blue-700 bg-blue-50 border-blue-200';
                                                            ewIcon = '🔵 ';
                                                        } else if (ew === 'Kuning - Jadwal mendekat') {
                                                            ewColor = 'text-amber-700 bg-amber-50 border-amber-200';
                                                            ewIcon = '🟡 ';
                                                        } else if (ew.startsWith('Oranye - ')) {
                                                            ewColor = 'text-orange-700 bg-orange-50 border-orange-200';
                                                            ewIcon = '🟠 ';
                                                        } else if (ew === 'Merah - Tidak patuh/Overdue') {
                                                            ewColor = 'text-rose-700 bg-rose-50 border-rose-200';
                                                            ewIcon = '🔴 ';
                                                        } else if (ew === 'Hijau - Patuh') {
                                                            ewColor = 'text-emerald-700 bg-emerald-50 border-emerald-200';
                                                            ewIcon = '🟢 ';
                                                        } else if (ew === 'Abu-abu' || ew === 'Tidak Berlaku') {
                                                            ewColor = 'text-slate-500 bg-slate-100 border-slate-200';
                                                        }

                                                        return (
                                                            <span className={`inline-flex items-center text-xs font-medium px-2.5 py-1 rounded-full border ${ewColor}`}>
                                                                {ewIcon}{ewLabel}
                                                            </span>
                                                        );
                                                    })()}
                                                </td>

                                                {/* 7. TANGGAL AMBIL OBAT (Realisasi) */}
                                                <td className="px-5 py-4">
                                                    {pasien.status === 'Pasif' ? (
                                                        <div className="font-medium text-slate-400 italic">—</div>
                                                    ) : (
                                                        <div
                                                            className={`font-medium ${
                                                                pasien.tanggal_realisasi
                                                                    ? 'text-emerald-700'
                                                                    : 'text-slate-400 italic'
                                                            }`}
                                                        >
                                                            {pasien.tanggal_realisasi
                                                                ? formatTanggal(
                                                                      pasien.tanggal_realisasi
                                                                  )
                                                                : 'Belum diambil'}
                                                        </div>
                                                    )}
                                                </td>

                                                {/* 8. STATUS KEPATUHAN */}
                                                <td className="px-5 py-4">
                                                    <StatusBadge
                                                        status={
                                                            pasien.status_kepatuhan ||
                                                            'Belum Ada Jadwal'
                                                        }
                                                    />
                                                </td>

                                                {/* 9. AKSI */}
                                                <td className="px-5 py-4 text-center">
                                                    <div className="flex items-center justify-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                                        {pasien.status ===
                                                            'Aktif' && (
                                                            <button
                                                                onClick={() =>
                                                                    setRealisasiPasien(
                                                                        pasien
                                                                    )
                                                                }
                                                                className="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50 transition-colors"
                                                                title="Catat Realisasi Pengambilan"
                                                            >
                                                                <RefreshCw className="w-5 h-5" />
                                                            </button>
                                                        )}

                                                        <WhatsAppButton
                                                            pasien={
                                                                pasien
                                                            }
                                                            tanggalEvaluasi={
                                                                evalDate
                                                            }
                                                            variant="icon"
                                                        />

                                                        <button
                                                            onClick={() =>
                                                                setDetailPasienId(
                                                                    pasien.id
                                                                )
                                                            }
                                                            className="p-1.5 rounded-lg text-slate-600 hover:bg-slate-100 transition-colors"
                                                            title="Detail Pasien"
                                                        >
                                                            <MoreVertical className="w-5 h-5" />
                                                        </button>

                                                        <button
                                                            onClick={() =>
                                                                setDeletePasien(
                                                                    pasien
                                                                )
                                                            }
                                                            className="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50 transition-colors"
                                                            title="Hapus Pasien"
                                                        >
                                                            <Trash2 className="w-5 h-5" />
                                                        </button>
                                                    </div>
                                                </td>
                                            </motion.tr>
                                        );
                                    }
                                )
                            ) : (
                                <tr>
                                    <td
                                        colSpan={10}
                                        className="px-5 py-16 text-center text-slate-500"
                                    >
                                        <div className="flex flex-col items-center">
                                            <Search className="w-10 h-10 text-slate-300 mb-3" />

                                            <p className="text-base font-medium">
                                                Tidak ada data pasien ditemukan.
                                            </p>

                                            <p className="text-sm mt-1">
                                                Coba sesuaikan kata kunci atau filter pencarian Anda.
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {pasienList.length > 0 && (
                    <div className="px-5 py-4 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between text-sm text-slate-500">
                        <span>Menampilkan {pasienList.length} pasien</span>
                        {selectedIds.size > 0 && (
                            <span className="text-teal-600 font-medium">
                                {selectedIds.size} dipilih
                            </span>
                        )}
                    </div>
                )}
            </div>

            {/* MODAL TAMBAH PASIEN */}
            {addModalOpen && (
                <AddPatientModal
                    isOpen={addModalOpen}
                    onClose={() =>
                        setAddModalOpen(false)
                    }
                />
            )}

            {/* MODAL IMPORT */}
            {importModalOpen && (
                <ImportExcelModal
                    isOpen={importModalOpen}
                    onClose={() =>
                        setImportModalOpen(false)
                    }
                />
            )}

            {/* MODAL REALISASI */}
            {realisasiPasien && (
                <QuickRealisasiModal
                    isOpen={!!realisasiPasien}
                    onClose={() =>
                        setRealisasiPasien(null)
                    }
                    pasien={realisasiPasien}
                />
            )}

            {/* MODAL DETAIL */}
            <PatientDetailModal
                isOpen={!!detailPasienId}
                onClose={() =>
                    setDetailPasienId(null)
                }
                pasienId={detailPasienId}
                tanggalEvaluasi={evalDate}
            />

            {/* MODAL KONFIRMASI HAPUS */}
            <AnimatePresence>
                {deletePasien && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center">
                        {/* Backdrop */}
                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            exit={{ opacity: 0 }}
                            className="absolute inset-0 bg-black/40 backdrop-blur-sm"
                            onClick={() => !isDeleting && setDeletePasien(null)}
                        />

                        {/* Dialog */}
                        <motion.div
                            initial={{ opacity: 0, scale: 0.95, y: 10 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.95, y: 10 }}
                            transition={{ duration: 0.2 }}
                            className="relative bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden"
                        >
                            <div className="p-6">
                                <div className="flex items-start gap-4">
                                    <div className="flex-shrink-0 w-12 h-12 rounded-full bg-rose-100 flex items-center justify-center">
                                        <AlertTriangle className="w-6 h-6 text-rose-600" />
                                    </div>

                                    <div className="flex-1">
                                        <h3 className="text-lg font-semibold text-slate-900">
                                            Hapus Data Pasien
                                        </h3>

                                        <p className="mt-2 text-sm text-slate-600">
                                            Apakah Anda yakin ingin menghapus data pasien{' '}
                                            <span className="font-semibold text-slate-800">
                                                {deletePasien.nama_pasien}
                                            </span>
                                            ? Tindakan ini tidak dapat dibatalkan dan semua riwayat terkait akan ikut terhapus.
                                        </p>

                                        <div className="mt-3 p-3 bg-slate-50 rounded-lg border border-slate-200">
                                            <div className="text-xs text-slate-500 space-y-1">
                                                <div>
                                                    <span className="font-medium">Kode:</span>{' '}
                                                    {deletePasien.kode_pasien}
                                                </div>
                                                <div>
                                                    <span className="font-medium">No. BPJS:</span>{' '}
                                                    {deletePasien.no_kartu_bpjs}
                                                </div>
                                                <div>
                                                    <span className="font-medium">Diagnosis:</span>{' '}
                                                    {Array.isArray(deletePasien.diagnosis_prb) ? deletePasien.diagnosis_prb.join(', ') : deletePasien.diagnosis_prb}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center justify-end gap-3 px-6 py-4 bg-slate-50 border-t border-slate-200">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setDeletePasien(null)}
                                    disabled={isDeleting}
                                >
                                    Batal
                                </Button>

                                <Button
                                    variant="danger"
                                    size="sm"
                                    onClick={handleDelete}
                                    isLoading={isDeleting}
                                >
                                    <Trash2 className="w-4 h-4 mr-1" />
                                    Ya, Hapus
                                </Button>
                            </div>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>

            {/* MODAL KONFIRMASI HAPUS MASSAL */}
            <AnimatePresence>
                {showBulkDeleteConfirm && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center">
                        {/* Backdrop */}
                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            exit={{ opacity: 0 }}
                            className="absolute inset-0 bg-black/40 backdrop-blur-sm"
                            onClick={() => !isBulkDeleting && setShowBulkDeleteConfirm(false)}
                        />

                        {/* Dialog */}
                        <motion.div
                            initial={{ opacity: 0, scale: 0.95, y: 10 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.95, y: 10 }}
                            transition={{ duration: 0.2 }}
                            className="relative bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden"
                        >
                            <div className="p-6">
                                <div className="flex items-start gap-4">
                                    <div className="flex-shrink-0 w-12 h-12 rounded-full bg-rose-100 flex items-center justify-center">
                                        <AlertTriangle className="w-6 h-6 text-rose-600" />
                                    </div>

                                    <div className="flex-1">
                                        <h3 className="text-lg font-semibold text-slate-900">
                                            Hapus {selectedIds.size} Pasien
                                        </h3>

                                        <p className="mt-2 text-sm text-slate-600">
                                            Apakah Anda yakin ingin menghapus{' '}
                                            <span className="font-bold text-rose-600">
                                                {selectedIds.size} pasien
                                            </span>{' '}
                                            yang dipilih? Tindakan ini tidak dapat dibatalkan dan semua riwayat terkait akan ikut terhapus.
                                        </p>

                                        <div className="mt-3 p-3 bg-rose-50 rounded-lg border border-rose-200">
                                            <div className="text-xs text-rose-700 font-medium flex items-center gap-2">
                                                <AlertTriangle className="w-3.5 h-3.5" />
                                                Data yang dihapus meliputi riwayat pengambilan obat dan tindak lanjut.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center justify-end gap-3 px-6 py-4 bg-slate-50 border-t border-slate-200">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setShowBulkDeleteConfirm(false)}
                                    disabled={isBulkDeleting}
                                >
                                    Batal
                                </Button>

                                <Button
                                    variant="danger"
                                    size="sm"
                                    onClick={handleBulkDelete}
                                    isLoading={isBulkDeleting}
                                >
                                    <Trash2 className="w-4 h-4 mr-1" />
                                    Ya, Hapus {selectedIds.size} Pasien
                                </Button>
                            </div>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>
        </AppLayout>
    );
}