import { Head, router } from '@inertiajs/react';
import { useState, useCallback, useMemo } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import {
    PasienPRB,
    Filters,
    DiagnosisPRB,
    StatusKepatuhan,
    KATEGORI_TINDAK_LANJUT_OPTIONS,
} from '@/types';
import { Button } from '@/Components/ui/Button';
import { StatusBadge } from '@/Components/ui/Badge';

import { PatientDetailModal } from '@/Components/Pasien/PatientDetailModal';
import { AddPatientModal } from '@/Components/Pasien/AddPatientModal';
import { ImportExcelModal } from '@/Components/Pasien/ImportExcelModal';
import { exportToExcel } from '@/lib/excel-utils';
import { exportToPdf } from '@/lib/pdf-utils';
import { csrfHeaders } from '@/lib/csrf';
import { jumlahPasienUnik } from '@/lib/pasien-unik';
import { Menu, MenuButton, MenuItem, MenuItems, Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import {
    Search,
    ChevronDown,
    FileText,
    Filter,
    Plus,
    FileSpreadsheet,
    Download,
    CalendarDays,
    MoreVertical,
    Trash2,
    AlertTriangle,
    CheckSquare,
    X,
    IdCard,
} from 'lucide-react';
import { motion, AnimatePresence } from 'motion/react';

interface Props {
    pasienList: PasienPRB[];
    tanggalEvaluasi: string;
    tanggalEvaluasiBawaan: string; // akhir periode penelitian, mis. 2026-05-31
    filters: Filters;
    diagnosisOptions: string[]; // 9 diagnosis baku + diagnosis lain yang ada di data
    alasanPasifOptions: string[];
    fktpOptions: string[];
    periodeOptions: { value: string; label: string }[];
}

export default function PasienIndex({
    pasienList,
    tanggalEvaluasi,
    tanggalEvaluasiBawaan,
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
    const [deletePasien, setDeletePasien] =
        useState<PasienPRB | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    // Inline edit schedule
    const [editingScheduleId, setEditingScheduleId] = useState<string | null>(null);
    const [scheduleValue, setScheduleValue] = useState<string>('');

    // Inline edit terakhir ambil obat
    const [editingTerakhirId, setEditingTerakhirId] = useState<string | null>(null);
    const [terakhirValue, setTerakhirValue] = useState<string>('');

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
            preserveScroll: true,
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

    // Inline edit terakhir ambil obat handlers
    const handleEditTerakhir = (pasien: PasienPRB) => {
        setEditingTerakhirId(pasien.id);
        setTerakhirValue(pasien.tanggal_pengambilan_terakhir || '');
    };

    const handleSaveTerakhir = (pasienId: string) => {
        router.put(`/pasien/${pasienId}/terakhir-ambil`, {
            tanggal_pengambilan_terakhir: terakhirValue
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingTerakhirId(null);
            }
        });
    };

    const handleCancelEditTerakhir = () => {
        setEditingTerakhirId(null);
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

    // Filter diagnosis (ceklis, bisa lebih dari satu)
    const diagnosisTerpilih: string[] = Array.isArray(filters.filter_diagnosis)
        ? filters.filter_diagnosis
        : filters.filter_diagnosis ? [filters.filter_diagnosis] : [];
    const toggleDiagnosis = (diag: string) => {
        const baru = diagnosisTerpilih.includes(diag)
            ? diagnosisTerpilih.filter((d) => d !== diag)
            : [...diagnosisTerpilih, diag];
        applyFilters({ filter_diagnosis: baru });
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
                    // Urutan selalu bawaan: prioritas early warning
                    sort_by: undefined,
                    sort_order: undefined,
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

    // Reset filters
    const resetFilters = () => {
        setSearchTerm('');
        setEvalDate(tanggalEvaluasiBawaan); // akhir periode penelitian, mis. 2026-05-31
        router.get('/pasien', {}, { preserveState: true, preserveScroll: true });
    };

    // Handle search input with Enter
    const handleSearch = (
        e: React.KeyboardEvent<HTMLInputElement>
    ) => {
        if (e.key === 'Enter') {
            applyFilters();
        }
    };

    // Kepala laporan PDF
    const labelPeriode = () => filters.filter_periode
        ? periodeOptions?.find((o) => o.value === filters.filter_periode)?.label ?? filters.filter_periode
        : 'Semua Periode';
    const ringkasanStatus = (rows: Record<string, any>[]) => {
        const jumlah = rows.reduce<Record<string, number>>((acc, row) => {
            const st = row['Status Kepatuhan'] || '-';
            acc[st] = (acc[st] ?? 0) + 1;
            return acc;
        }, {});
        const pasienUnik = jumlahPasienUnik(rows.map((row) => row['NOKA']));
        return `Jumlah pasien: ${pasienUnik}  ·  ` + Object.entries(jumlah).map(([st, n]) => `${st}: ${n}`).join('  ·  ');
    };

    // Export data — Excel atau PDF, isi sama (sesuai filter yang aktif)
    const handleExport = async (format: 'excel' | 'pdf' = 'excel') => {
        try {
            const queryParams = new URLSearchParams({
                tanggal_evaluasi: evalDate,
                search: filters.search || '',
                filter_status: filters.filter_status || '',
                filter_periode: filters.filter_periode || '',
                filter_kepatuhan: filters.filter_kepatuhan || '',
                filter_fktp: filters.filter_fktp || '',
                filter_jenis_prb: filters.filter_jenis_prb || '',
                filter_jumlah_diagnosis: filters.filter_jumlah_diagnosis || '',
                filter_early_warning: filters.filter_early_warning || '',
                filter_tindak_lanjut: filters.filter_tindak_lanjut || '',
            });
            diagnosisTerpilih.forEach((d) => queryParams.append('filter_diagnosis[]', d));
            // PDF memuat Histori Pengambilan; Excel biasa tetap satu sheet
            if (format === 'pdf') queryParams.set('dengan_histori', '1');

            const res = await fetch(
                `/pasien-export?${queryParams.toString()}`
            );

            const data = await res.json();

            let fktpName = 'Semua_FKTP';
            if (filters.filter_fktp) {
                fktpName = filters.filter_fktp.replace(/\s+/g, '_');
            }

            if (format === 'excel') {
                exportToExcel(
                    data,
                    `Laporan_PRB_${fktpName}_${evalDate}.xlsx`
                );
                return;
            }

            const filterLain = [
                filters.filter_status && `Status: ${filters.filter_status}`,
                filters.filter_kepatuhan && `Kepatuhan: ${filters.filter_kepatuhan}`,
                diagnosisTerpilih.length > 0 && `Diagnosis: ${diagnosisTerpilih.join(', ')}`,
                filters.filter_jenis_prb && `Jenis PRB: ${filters.filter_jenis_prb}`,
                filters.filter_jumlah_diagnosis && `Jumlah diagnosis: ${filters.filter_jumlah_diagnosis === 'satu' ? 'satu diagnosis' : 'lebih dari satu'}`,
                filters.filter_early_warning && `Early Warning: ${filters.filter_early_warning}`,
                filters.filter_tindak_lanjut && `Tindak Lanjut: ${filters.filter_tindak_lanjut}`,
                filters.search && `Pencarian: "${filters.search}"`,
            ].filter(Boolean).join(' · ');

            // PDF: { pasien, histori } — histori sama dengan tab Histori Pengambilan
            const barisPasien: Record<string, any>[] = data.pasien;
            const histori: Record<string, any>[] = data.histori;

            exportToPdf(
                barisPasien,
                `Laporan_PRB_${fktpName}_${evalDate}.pdf`,
                {
                    judul: 'Laporan Data Pasien PRB',
                    keterangan: [
                        `Periode: ${labelPeriode()}  ·  FKTP: ${filters.filter_fktp || 'Semua FKTP'}  ·  Tanggal evaluasi: ${evalDate.split('-').reverse().join('/')}`,
                        ...(filterLain ? [`Filter: ${filterLain}`] : []),
                        ringkasanStatus(barisPasien),
                    ],
                    // Periode sudah tertulis di kepala laporan
                    kolom: Object.keys(barisPasien[0] ?? {}).filter((k) => k !== 'Periode Laporan'),
                    kolomStatus: 'Status Kepatuhan',
                    tabelTambahan: [{
                        judul: `Histori Pengambilan Obat (${histori.length} pengambilan)`,
                        data: histori,
                        kolomStatus: 'Status Kepatuhan',
                    }],
                }
            );
        } catch (e) {
            alert('Gagal mengekspor data.');
        }
    };

    // Export pasien yang dicentang, beserta Histori Pengambilan (sheet kedua / tabel kedua di PDF)
    const [isExportingTerpilih, setIsExportingTerpilih] = useState(false);
    const handleExportTerpilih = async (format: 'excel' | 'pdf' = 'excel') => {
        setIsExportingTerpilih(true);
        try {
            const res = await fetch('/pasien-export', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                body: JSON.stringify({
                    ids: Array.from(selectedIds),
                    tanggal_evaluasi: evalDate,
                    filter_periode: filters.filter_periode || '',
                }),
            });
            if (!res.ok) {
                throw new Error(res.status === 419 ? 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.' : `Kode ${res.status}`);
            }

            const data = await res.json();

            if (format === 'excel') {
                exportToExcel(
                    data.pasien,
                    `Laporan_PRB_${selectedIds.size}_Pasien_${evalDate}.xlsx`,
                    [{ nama: 'Histori Pengambilan', data: data.histori }]
                );
                return;
            }

            exportToPdf(
                data.pasien,
                `Laporan_PRB_${selectedIds.size}_Pasien_${evalDate}.pdf`,
                {
                    judul: 'Laporan Pasien PRB Terpilih',
                    keterangan: [
                        `Periode: ${labelPeriode()}  ·  Tanggal evaluasi: ${evalDate.split('-').reverse().join('/')}`,
                        ringkasanStatus(data.pasien),
                    ],
                    kolom: Object.keys(data.pasien[0] ?? {}).filter((k) => k !== 'Periode Laporan'),
                    kolomStatus: 'Status Kepatuhan',
                    tabelTambahan: [{
                        judul: `Histori Pengambilan Obat (${data.histori.length} pengambilan)`,
                        data: data.histori,
                        kolomStatus: 'Status Kepatuhan',
                    }],
                }
            );
        } catch (e: any) {
            alert(`Gagal mengekspor data. ${e?.message ?? ''}`);
        } finally {
            setIsExportingTerpilih(false);
        }
    };

    // Filter status options
    // Hanya status yang sudah dinilai (sama dengan Rekap Kepatuhan)
    const statusKepatuhanOptions: StatusKepatuhan[] = [
        'Patuh',
        'Tidak Teratur',
    ];

    // Format tanggal: DD/MM/YYYY
    const formatTanggal = (tanggal?: string | null): string => {
        if (!tanggal) return '-';
        const d = new Date(tanggal);
        if (isNaN(d.getTime())) return '-';
        const hari  = String(d.getDate()).padStart(2, '0');
        const bulan = String(d.getMonth() + 1).padStart(2, '0');
        const tahun = d.getFullYear();
        return `${hari}/${bulan}/${tahun}`;
    };

    return (
        <AppLayout title="Data Pasien PRB">
            <Head title="Data Pasien PRB" />

            {/* Header Actions — pencarian satu baris penuh agar mudah terlihat */}
            <div className="flex flex-col gap-4 mb-6">
                <div className="w-full">
                    <div className="relative group">
                        <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <Search className="w-6 h-6 text-slate-400 group-focus-within:text-teal-500 transition-colors" />
                        </div>

                        <input
                            type="text"
                            placeholder="Cari NOKA atau Kode PRB... (Tekan Enter)"
                            value={searchTerm}
                            onChange={(e) =>
                                setSearchTerm(e.target.value)
                            }
                            onKeyDown={handleSearch}
                            className="block w-full pl-13 pr-4 py-3.5 text-base bg-white border-2 border-slate-200 rounded-xl focus:ring-4 focus:ring-teal-500/20 focus:border-teal-500 shadow-sm transition-all placeholder:text-slate-400"
                        />
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex items-center gap-2">
                        <select
                            className="text-sm font-medium text-slate-700 bg-white border border-slate-200 rounded-xl px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 cursor-pointer h-[42px]"
                            value={filters.filter_periode || ''}
                            onChange={(e) => applyFilters({ filter_periode: e.target.value })}
                            title="Periode Pengambilan"
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

                    <Menu as="div" className="relative">
                        <MenuButton className="inline-flex items-center justify-center font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 focus:ring-slate-500 px-4 py-2 text-sm gap-2">
                            <Download className="w-4 h-4" />
                            Export
                            <ChevronDown className="w-4 h-4 text-slate-400" />
                        </MenuButton>
                        <MenuItems
                            anchor="bottom end"
                            className="z-50 mt-1 w-48 rounded-xl border border-slate-200 bg-white p-1 shadow-lg focus:outline-none"
                        >
                            <MenuItem>
                                <button
                                    type="button"
                                    onClick={() => handleExport('excel')}
                                    className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-700 data-[focus]:bg-slate-100"
                                >
                                    <FileSpreadsheet className="w-4 h-4 text-emerald-600" />
                                    Excel (.xlsx)
                                </button>
                            </MenuItem>
                            <MenuItem>
                                <button
                                    type="button"
                                    onClick={() => handleExport('pdf')}
                                    className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-700 data-[focus]:bg-slate-100"
                                >
                                    <FileText className="w-4 h-4 text-rose-600" />
                                    PDF (.pdf)
                                </button>
                            </MenuItem>
                        </MenuItems>
                    </Menu>

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
                        <div className="bg-white p-3 rounded-xl border border-slate-200 shadow-sm grid grid-cols-[repeat(auto-fill,minmax(130px,1fr))] gap-x-3 gap-y-2.5">
                            {/* Status Pasien */}
                            <div>
                                <label className="block text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-1">
                                    Status Pasien
                                </label>

                                <select
                                    value={
                                        filters.filter_status || ''
                                    }
                                    onChange={(e) =>
                                        applyFilters({
                                            filter_status:
                                                e.target.value,
                                        })
                                    }
                                    className="w-full text-xs border border-slate-300 rounded-lg pl-2.5 pr-7 py-1.5 focus:ring-teal-500 focus:border-teal-500"
                                >
                                    <option value="">
                                        Semua
                                    </option>

                                    <option value="Aktif">
                                        Aktif
                                    </option>

                                    <option value="Pasif">
                                        Pasif
                                    </option>
                                </select>
                            </div>

                            {/* Jenis PRB */}
                            <div>
                                <label className="block text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-1">
                                    Jenis PRB
                                </label>

                                <select
                                    value={
                                        filters.filter_jenis_prb || ''
                                    }
                                    onChange={(e) =>
                                        applyFilters({
                                            filter_jenis_prb:
                                                e.target.value,
                                        })
                                    }
                                    className="w-full text-xs border border-slate-300 rounded-lg pl-2.5 pr-7 py-1.5 focus:ring-teal-500 focus:border-teal-500"
                                >
                                    <option value="">
                                        Semua
                                    </option>
                                    <option value="Baru">
                                        PRB Baru
                                    </option>
                                    <option value="Lama">
                                        PRB Lama
                                    </option>
                                </select>
                            </div>

                            {/* Jumlah diagnosis per pasien (NOKA) */}
                            <div>
                                <label className="block text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-1">
                                    Jumlah Diagnosis
                                </label>

                                <select
                                    value={filters.filter_jumlah_diagnosis || ''}
                                    onChange={(e) => applyFilters({ filter_jumlah_diagnosis: e.target.value })}
                                    className="w-full text-xs border border-slate-300 rounded-lg pl-2.5 pr-7 py-1.5 focus:ring-teal-500 focus:border-teal-500"
                                >
                                    <option value="">Semua</option>
                                    <option value="lebih_dari_satu">Lebih dari satu diagnosis</option>
                                    <option value="satu">Satu diagnosis</option>
                                </select>
                            </div>

                            {/* Status Kepatuhan */}
                            <div>
                                <label className="block text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-1">
                                    Status Kepatuhan
                                </label>

                                <select
                                    value={
                                        filters.filter_kepatuhan || ''
                                    }
                                    onChange={(e) =>
                                        applyFilters({
                                            filter_kepatuhan:
                                                e.target.value,
                                        })
                                    }
                                    className="w-full text-xs border border-slate-300 rounded-lg pl-2.5 pr-7 py-1.5 focus:ring-teal-500 focus:border-teal-500"
                                >
                                    <option value="">
                                        Semua
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

                            {/* Early Warning */}
                            <div>
                                <label className="block text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-1">
                                    Early Warning
                                </label>

                                <select
                                    value={
                                        filters.filter_early_warning || ''
                                    }
                                    onChange={(e) =>
                                        applyFilters({
                                            filter_early_warning:
                                                e.target.value,
                                        })
                                    }
                                    className="w-full text-xs border border-slate-300 rounded-lg pl-2.5 pr-7 py-1.5 focus:ring-teal-500 focus:border-teal-500"
                                >
                                    <option value="">
                                        Semua
                                    </option>
                                    <option value="Jadwal Mendekat">
                                        Jadwal Mendekat
                                    </option>
                                    <option value="Jatuh Tempo">
                                        Jatuh Tempo
                                    </option>
                                    <option value="Belum Mengambil">
                                        Belum Mengambil
                                    </option>
                                    <option value="Tidak Teratur">
                                        Tidak Teratur / Overdue
                                    </option>
                                </select>
                            </div>

                            {/* FKTP Asal */}
                            <div>
                                <label className="block text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-1">
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
                                    className="w-full text-xs border border-slate-300 rounded-lg pl-2.5 pr-7 py-1.5 focus:ring-teal-500 focus:border-teal-500"
                                >
                                    <option value="">
                                        Semua
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

                            {/* Diagnosis — ceklis, bisa lebih dari satu */}
                            <div>
                                <label className="block text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-1">
                                    Diagnosis
                                </label>

                                <Popover className="relative">
                                    <PopoverButton className="w-full flex items-center justify-between gap-2 text-xs border border-slate-300 rounded-lg px-2.5 py-1.5 bg-white text-left focus:outline-none focus:ring-2 focus:ring-teal-500/30 focus:border-teal-500">
                                        <span className="truncate">
                                            {diagnosisTerpilih.length === 0
                                                ? 'Semua'
                                                : diagnosisTerpilih.length === 1
                                                    ? diagnosisTerpilih[0]
                                                    : `${diagnosisTerpilih.length} diagnosis dipilih`}
                                        </span>
                                        <ChevronDown className="w-4 h-4 text-slate-400 shrink-0" />
                                    </PopoverButton>
                                    <PopoverPanel
                                        anchor="bottom start"
                                        className="z-50 mt-1 w-64 rounded-xl border border-slate-200 bg-white p-2 shadow-lg focus:outline-none"
                                    >
                                        <div className="max-h-64 overflow-y-auto">
                                            {diagnosisOptions.map((d) => (
                                                <label key={d} className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-50 cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        checked={diagnosisTerpilih.includes(d)}
                                                        onChange={() => toggleDiagnosis(d)}
                                                        className="w-4 h-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                                    />
                                                    {d}
                                                </label>
                                            ))}
                                        </div>
                                        {diagnosisTerpilih.length > 0 && (
                                            <button
                                                type="button"
                                                onClick={() => applyFilters({ filter_diagnosis: [] })}
                                                className="mt-1 w-full text-xs text-rose-600 hover:text-rose-700 px-2 py-1.5 text-left border-t border-slate-100"
                                            >
                                                Hapus pilihan diagnosis
                                            </button>
                                        )}
                                    </PopoverPanel>
                                </Popover>
                            </div>

                            {/* Tindak Lanjut — kategori tindak lanjut terakhir */}
                            <div>
                                <label className="block text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-1">
                                    Tindak Lanjut
                                </label>

                                <select
                                    value={filters.filter_tindak_lanjut || ''}
                                    onChange={(e) =>
                                        applyFilters({
                                            filter_tindak_lanjut: e.target.value,
                                        })
                                    }
                                    className="w-full text-xs border border-slate-300 rounded-lg pl-2.5 pr-7 py-1.5 focus:ring-teal-500 focus:border-teal-500"
                                >
                                    <option value="">Semua</option>
                                    {KATEGORI_TINDAK_LANJUT_OPTIONS.map((k) => (
                                        <option key={k} value={k}>{k}</option>
                                    ))}
                                </select>
                            </div>

                            {/* Tombol Reset */}
                            <div className="flex items-end">
                                <button
                                    type="button"
                                    onClick={resetFilters}
                                    className="w-full h-[30px] inline-flex items-center justify-center rounded-lg border border-rose-200 bg-rose-50 text-xs font-semibold text-rose-600 transition-colors hover:bg-rose-100 hover:border-rose-300 focus:outline-none focus:ring-2 focus:ring-rose-500/30"
                                >
                                    <X className="w-3.5 h-3.5 mr-1.5" />
                                    Reset Filter
                                </button>
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

                            <div className="flex items-center gap-2">
                                <Menu as="div" className="relative">
                                    <MenuButton
                                        disabled={isExportingTerpilih}
                                        className="inline-flex items-center justify-center font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 focus:ring-slate-500 px-3 py-1.5 text-xs gap-1.5 disabled:opacity-50"
                                    >
                                        <Download className="w-4 h-4" />
                                        {isExportingTerpilih ? 'Menyiapkan...' : `Export ${selectedIds.size} Pasien`}
                                        <ChevronDown className="w-3.5 h-3.5 text-slate-400" />
                                    </MenuButton>
                                    <MenuItems
                                        anchor="bottom end"
                                        className="z-50 mt-1 w-48 rounded-xl border border-slate-200 bg-white p-1 shadow-lg focus:outline-none"
                                    >
                                        <MenuItem>
                                            <button
                                                type="button"
                                                onClick={() => handleExportTerpilih('excel')}
                                                className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-700 data-[focus]:bg-slate-100"
                                            >
                                                <FileSpreadsheet className="w-4 h-4 text-emerald-600" />
                                                Excel (.xlsx)
                                            </button>
                                        </MenuItem>
                                        <MenuItem>
                                            <button
                                                type="button"
                                                onClick={() => handleExportTerpilih('pdf')}
                                                className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-700 data-[focus]:bg-slate-100"
                                            >
                                                <FileText className="w-4 h-4 text-rose-600" />
                                                PDF (.pdf)
                                            </button>
                                        </MenuItem>
                                    </MenuItems>
                                </Menu>
                                <Button
                                    variant="danger"
                                    size="sm"
                                    onClick={() => setShowBulkDeleteConfirm(true)}
                                >
                                    <Trash2 className="w-4 h-4 mr-1" />
                                    Hapus {selectedIds.size} Pasien
                                </Button>
                            </div>
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* Data Table */}
            <div className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div className="px-5 py-3 border-b border-slate-200 flex items-center justify-between text-sm text-slate-500">
                    <span>
                        {(() => {
                            // Baris = pasien kecuali pasien dengan diagnosis bertanggal berbeda
                            const pasien = jumlahPasienUnik(pasienList.map((p) => p.noka));
                            return pasienList.length === pasien ? (
                                <>Total <span className="font-semibold text-slate-800">{pasien}</span> pasien</>
                            ) : (
                                <>
                                    Total <span className="font-semibold text-slate-800">{pasien}</span> pasien · {pasienList.length} baris
                                </>
                            );
                        })()}
                    </span>
                    {selectedIds.size > 0 && (
                        <span className="text-teal-600 font-medium">
                            {selectedIds.size} dipilih
                        </span>
                    )}
                </div>
                <div className="overflow-x-auto max-h-[70vh] overflow-y-auto">
                    <table className="w-full min-w-[1600px] text-left text-sm">
                        <thead className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold text-xs uppercase tracking-wider sticky top-0 z-10">
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
                                    Diagnosis & FKTP
                                </th>

                                <th className="px-5 py-4 min-w-[130px]">
                                    Tanggal Aktif
                                </th>

                                <th className="px-5 py-4 min-w-[150px]">
                                    Terakhir Ambil Obat
                                </th>

                                <th className="px-5 py-4 min-w-[150px]">
                                    {filters.filter_periode
                                        ? 'Jadwal Pengambilan'
                                        : 'Jadwal Selanjutnya'}
                                </th>

                                <th className="px-5 py-4 min-w-[170px]">
                                    Early Warning
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
                                                <td className="px-5 py-4 min-w-[250px]">
                                                    <div className="flex items-start gap-3">
                                                        <div className="w-9 h-9 rounded-full bg-slate-100 flex items-center justify-center text-slate-600 font-bold shrink-0 group-hover:bg-teal-50 group-hover:text-teal-600 transition-colors mt-0.5">
                                                            <IdCard className="w-4 h-4" />
                                                        </div>

                                                        <div className="min-w-0">
                                                            <div className="font-semibold text-slate-800 leading-tight whitespace-normal break-words">
                                                                {pasien.kode_pasien}
                                                            </div>
                                                            {pasien.nama_samaran && (
                                                                <div className="text-xs text-slate-600 whitespace-normal">
                                                                    {pasien.nama_samaran}
                                                                </div>
                                                            )}
                                                            <div className="mt-0.5 mb-1">
                                                                <span
                                                                    className={`inline-block text-[10px] px-1.5 py-0.5 rounded-sm border font-medium uppercase tracking-wider ${
                                                                        pasien.jenis_prb === 'Baru'
                                                                            ? 'bg-blue-50 text-blue-600 border-blue-200'
                                                                            : 'bg-slate-100 text-slate-600 border-slate-200'
                                                                    }`}
                                                                >
                                                                    PRB {pasien.jenis_prb}
                                                                </span>
                                                            </div>
                                                            <div className="text-xs text-slate-500 whitespace-nowrap">
                                                                <span className="font-mono">NOKA {pasien.noka}</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                {/* 2. DIAGNOSIS & FKTP */}
                                                <td className="px-5 py-4 min-w-[180px]">
                                                    <div className="font-medium text-slate-700 whitespace-normal leading-snug">
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
                                                    </div>
                                                    <div className="text-xs text-slate-500 mt-0.5 whitespace-normal">
                                                        {pasien.fktp_asal}
                                                    </div>
                                                </td>

                                                {/* 3. MULAI PRB */}
                                                <td className="px-5 py-4 whitespace-nowrap">
                                                    <div className="font-medium text-slate-800">
                                                        {formatTanggal(pasien.tanggal_mulai_prb)}
                                                    </div>
                                                </td>

                                                {/* 4. TERAKHIR AMBIL OBAT */}
                                                <td className="px-5 py-4 whitespace-nowrap">
                                                    {pasien.status !== 'Aktif' ? (
                                                        <span className="text-slate-400 text-xs italic">
                                                            Pasien {pasien.status}
                                                        </span>
                                                    ) : editingTerakhirId === pasien.id ? (
                                                        <div className="flex items-center gap-1.5" onClick={(e) => e.stopPropagation()}>
                                                            <input
                                                                type="date"
                                                                value={terakhirValue}
                                                                onChange={(e) => setTerakhirValue(e.target.value)}
                                                                className="rounded border border-slate-300 text-xs py-1 px-2 focus:ring-teal-500 focus:border-teal-500 w-[130px]"
                                                            />
                                                            <button onClick={() => handleSaveTerakhir(pasien.id)} className="text-teal-600 hover:text-teal-700 p-1 bg-teal-50 hover:bg-teal-100 rounded transition-colors" title="Simpan">
                                                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"></path></svg>
                                                            </button>
                                                            <button onClick={handleCancelEditTerakhir} className="text-slate-400 hover:text-slate-600 p-1 bg-slate-100 hover:bg-slate-200 rounded transition-colors" title="Batal">
                                                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                                            </button>
                                                        </div>
                                                    ) : (
                                                        <div
                                                            className={`font-medium cursor-pointer hover:bg-slate-100 p-1.5 rounded -ml-1 flex items-center justify-between group transition-colors ${
                                                                pasien.tanggal_pengambilan_terakhir
                                                                    ? 'text-slate-800'
                                                                    : 'text-slate-400 italic'
                                                            }`}
                                                            onClick={(e) => { e.stopPropagation(); handleEditTerakhir(pasien); }}
                                                            title="Klik untuk mengubah tanggal terakhir ambil obat"
                                                        >
                                                            <span>
                                                                {pasien.tanggal_pengambilan_terakhir
                                                                    ? formatTanggal(
                                                                          pasien.tanggal_pengambilan_terakhir
                                                                      )
                                                                    : 'Belum pernah'}
                                                            </span>
                                                            <span className="opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity ml-2">
                                                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                                            </span>
                                                        </div>
                                                    )}
                                                </td>

                                                {/* 5. JADWAL SELANJUTNYA */}
                                                <td className="px-5 py-4 whitespace-nowrap">
                                                    {pasien.status !== 'Aktif' ? (
                                                        <span className="text-slate-400 text-xs italic">
                                                            Pasien {pasien.status}
                                                        </span>
                                                    ) : (
                                                        <div
                                                            className={`font-medium p-1.5 rounded -ml-1 ${
                                                                (pasien.jadwal_target_aktif || pasien.tanggal_jadwal_berikutnya)
                                                                    ? 'text-slate-800'
                                                                    : 'text-slate-400 italic'
                                                            }`}
                                                        >
                                                            {(pasien.jadwal_target_aktif || pasien.tanggal_jadwal_berikutnya)
                                                                ? formatTanggal(
                                                                      pasien.jadwal_target_aktif || pasien.tanggal_jadwal_berikutnya
                                                                  )
                                                                : 'Belum ada jadwal'}
                                                        </div>
                                                    )}
                                                </td>

                                                {/* 6. EARLY WARNING */}
                                                <td className="px-5 py-4 whitespace-nowrap">
                                                    {(() => {
                                                        if (pasien.status !== 'Aktif') {
                                                            return <span className="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded-full border text-slate-500 bg-slate-100 border-slate-200">Tidak Berlaku</span>;
                                                        }

                                                        const ew = pasien.early_warning;
                                                        if (!ew || ew === '-') {
                                                            return <span className="text-slate-400 italic text-xs">Tidak tersedia</span>;
                                                        }

                                                        let ewColor = 'text-slate-500 bg-slate-50 border-slate-200';
                                                        let ewLabel = ew;

                                                        if (ew === 'Belum memasuki jadwal') {
                                                            ewColor = 'text-blue-700 bg-blue-50 border-blue-200';
                                                        } else if (ew === 'Jadwal Mendekat' || ew === 'Jadwal mendekat') {
                                                            ewColor = 'text-amber-700 bg-amber-50 border-amber-200';
                                                        } else if (ew === 'Jatuh Tempo' || ew === 'Jatuh tempo' || ew === 'Masih Toleransi' || ew === 'Belum mengambil, masih toleransi') {
                                                            ewColor = 'text-orange-700 bg-orange-50 border-orange-200';
                                                        } else if (ew === 'Tidak Teratur/Overdue' || ew === 'Tidak teratur/Overdue') {
                                                            ewColor = 'text-rose-700 bg-rose-50 border-rose-200';
                                                        } else if (ew === 'Patuh') {
                                                            ewColor = 'text-emerald-700 bg-emerald-50 border-emerald-200';
                                                        } else if (ew === 'Pasif' || ew === 'Tidak Berlaku') {
                                                            ewColor = 'text-slate-500 bg-slate-100 border-slate-200';
                                                        }

                                                        return (
                                                            <span className={`inline-flex items-center text-xs font-medium px-2.5 py-1 rounded-full border ${ewColor}`}>
                                                                {ewLabel}
                                                            </span>
                                                        );
                                                    })()}
                                                </td>

                                                {/* 7. STATUS KEPATUHAN */}
                                                <td className="px-5 py-4 whitespace-nowrap">
                                                    {pasien.status !== 'Aktif' ? (
                                                        <StatusBadge status={pasien.status} />
                                                    ) : (
                                                        <StatusBadge
                                                            status={
                                                                pasien.status_kepatuhan ||
                                                                'Belum Ada Jadwal'
                                                            }
                                                        />
                                                    )}
                                                </td>

                                                {/* 9. AKSI */}
                                                <td className="px-5 py-4 text-center whitespace-nowrap">
                                                    {/* Selalu terlihat (layar sentuh tidak punya hover); lebih tegas saat baris di-hover */}
                                                    <div className="flex items-center justify-center gap-1 opacity-60 group-hover:opacity-100 focus-within:opacity-100 transition-opacity">

                                                        <button
                                                            onClick={() =>
                                                                setDetailPasienId(
                                                                    pasien.id
                                                                )
                                                            }
                                                            className="p-1.5 rounded-lg text-slate-600 hover:bg-slate-100 transition-colors"
                                                            title="Detail Pasien"
                                                            aria-label="Detail Pasien"
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
                                                            aria-label="Hapus Pasien"
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
                                        colSpan={9}
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



            {/* MODAL DETAIL */}
            <PatientDetailModal
                isOpen={!!detailPasienId}
                onClose={() =>
                    setDetailPasienId(null)
                }
                pasienId={detailPasienId}
                tanggalEvaluasi={evalDate}
                filterPeriode={filters.filter_periode || ''}
                // Setelah tindak lanjut disimpan, filter Tindak Lanjut ikut kategori itu (filter lain tetap)
                onTindakLanjutTersimpan={(kategori) => applyFilters({ filter_tindak_lanjut: kategori })}
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
                                                {deletePasien.kode_pasien} · {(deletePasien.diagnosis_prb ?? []).join(', ')}
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
                                                    <span className="font-medium">Diagnosis:</span>{' '}
                                                    {(deletePasien.diagnosis_prb ?? []).join(', ') || '-'}
                                                </div>
                                                <div>
                                                    <span className="font-medium">NOKA:</span>{' '}
                                                    {deletePasien.noka}
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