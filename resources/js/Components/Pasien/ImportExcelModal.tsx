import { useState, useRef, useMemo } from 'react';
import { Modal } from '../ui/Modal';
import { Button } from '../ui/Button';
import { router } from '@inertiajs/react';
import { UploadCloud, FileSpreadsheet, CheckCircle2, AlertCircle, ShieldCheck, Info, TriangleAlert, CalendarDays } from 'lucide-react';
import {
    parseExcelFile, validateImportData, buildImportPayload, kolomBulan,
    ValidationResult, ParseMeta, downloadImportTemplate,
} from '@/lib/excel-utils';

interface Props {
    isOpen: boolean;
    onClose: () => void;
}

interface HasilImpor {
    baru: number;
    diperbarui: number;
    duplikat: number;
    invalid: number;
}

/**
 * Baris dikirim per potongan agar file besar (ribuan transaksi) tidak
 * melewati batas waktu satu request. Urutan baris tetap dipertahankan —
 * backend menilai tiap pengambilan terhadap jadwal dari pengambilan sebelumnya.
 */
const UKURAN_POTONGAN = 500;

/** CSRF dari cookie XSRF-TOKEN (selalu terbaru), fallback meta tag. */
function csrfHeaders(): Record<string, string> {
    const xsrf = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1];
    return xsrf
        ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
        : { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '' };
}

const fmtTgl = (iso: string) => iso.split('-').reverse().join('/');

export function ImportExcelModal({ isOpen, onClose }: Props) {
    const [file, setFile]             = useState<File | null>(null);
    const [rawRows, setRawRows]       = useState<Record<string, any>[]>([]);
    const [parseMeta, setParseMeta]   = useState<ParseMeta | null>(null);
    const [parseError, setParseError] = useState<string | null>(null);
    const [tahunBulan, setTahunBulan] = useState<number>(new Date().getFullYear());
    const [hanyaPerluDicek, setHanyaPerluDicek] = useState(false);
    const [progres, setProgres]       = useState<{ selesai: number; total: number } | null>(null);
    const [hasil, setHasil]           = useState<HasilImpor | null>(null);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    // Format data apotek per bulan (kolom MARET/APRIL/MEI) tanpa tahun di
    // judul → perlu tahun. Kolom seperti "JANUARI 2027" sudah membawa tahunnya.
    const adaKolomBulan = useMemo(
        () => rawRows.some(r => Object.keys(r).some(k => !k.startsWith('_') && kolomBulan(k)?.tahun === null)),
        [rawRows]
    );

    const results: ValidationResult[] = useMemo(
        () => validateImportData(rawRows, { tahunBulan }),
        [rawRows, tahunBulan]
    );
    const payload = useMemo(() => buildImportPayload(results), [results]);

    const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const selected = e.target.files?.[0];
        if (!selected) return;

        setFile(selected);
        setParseError(null);
        setRawRows([]);
        setParseMeta(null);
        setHasil(null);
        setUploadError(null);

        try {
            const data = await parseExcelFile(selected);
            // Ambil _parse_meta dari baris pertama
            setParseMeta((data[0]?.['_parse_meta'] as ParseMeta) ?? null);
            setRawRows(data);
        } catch (err: any) {
            setParseError(err.message || 'Gagal memproses file Excel.');
        }
    };

    const handleUpload = async () => {
        if (payload.length === 0) {
            alert('Tidak ada baris data valid untuk diunggah.');
            return;
        }

        setUploadError(null);
        const total: HasilImpor = { baru: 0, diperbarui: 0, duplikat: 0, invalid: 0 };
        setProgres({ selesai: 0, total: payload.length });

        for (let i = 0; i < payload.length; i += UKURAN_POTONGAN) {
            const potongan = payload.slice(i, i + UKURAN_POTONGAN);
            try {
                const res = await fetch('/pasien/import/batch', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                    body: JSON.stringify({ data: potongan }),
                });
                if (!res.ok) {
                    throw new Error(res.status === 419
                        ? 'Sesi kedaluwarsa. Muat ulang halaman lalu impor lagi.'
                        : `Server menolak data (kode ${res.status}).`);
                }
                const json: HasilImpor = await res.json();
                (Object.keys(total) as (keyof HasilImpor)[]).forEach(k => { total[k] += json[k] ?? 0; });
                setProgres({ selesai: i + potongan.length, total: payload.length });
            } catch (err: any) {
                // Potongan sebelumnya sudah tersimpan. Mengimpor ulang file yang
                // sama aman: transaksi yang sudah ada terdeteksi sebagai duplikat.
                setUploadError(
                    `${err.message || 'Gagal mengunggah data.'} ` +
                    `${i} dari ${payload.length} baris sudah tersimpan; impor ulang file ini untuk melanjutkan ` +
                    `(baris yang sudah masuk akan dilewati sebagai duplikat).`
                );
                setProgres(null);
                setHasil(total);
                router.reload();
                return;
            }
        }

        setProgres(null);
        setHasil(total);
        router.reload();
    };

    const handleReset = () => {
        setFile(null);
        setRawRows([]);
        setParseMeta(null);
        setParseError(null);
        setHasil(null);
        setUploadError(null);
        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    const handleClose = () => {
        if (progres) return; // jangan tutup saat masih mengunggah
        if (hasil) handleReset();
        onClose();
    };

    const validCount     = results.filter(r => r.valid).length;
    const invalidCount   = results.filter(r => !r.valid).length;
    const peringatanCount = results.filter(r => r.valid && r.warnings.length > 0).length;

    const sheetNames = Array.from(new Set(results.map(r => r.sheet)));
    const sheetCount = sheetNames.length;
    const multiSheet = sheetCount > 1;

    const ditampilkan = hanyaPerluDicek
        ? results.filter(r => !r.valid || r.warnings.length > 0)
        : results;

    return (
        <Modal isOpen={isOpen} onClose={handleClose} title="Import Data Pasien & Resep PRB (Excel)" maxWidth="4xl">
            <div className="space-y-6">

                {!file ? (
                    /* ── Belum pilih file ─────────────────────────────────── */
                    <div className="flex flex-col items-center justify-center border-2 border-dashed border-slate-300 rounded-xl p-10 bg-slate-50 hover:bg-slate-100 transition-colors">
                        <UploadCloud className="w-12 h-12 text-teal-600 mb-3" />
                        <h4 className="text-slate-800 font-medium mb-1">Pilih atau letakkan file Excel di sini</h4>
                        <p className="text-slate-500 text-sm mb-1">Mendukung format .xlsx dan .xls</p>
                        <p className="text-slate-400 text-xs mb-1">
                            Sistem membaca <span className="font-medium">semua sheet</span> secara otomatis —
                            sheet data dideteksi dari struktur header, bukan dari nama atau posisi sheet.
                        </p>
                        <p className="text-slate-400 text-xs mb-4">
                            Mendukung template sistem maupun data apotek per bulan
                            (kolom <span className="font-medium">MARET / APRIL / MEI</span> berisi tanggal ambil obat).
                        </p>
                        <div className="flex gap-3">
                            <Button variant="outline" onClick={downloadImportTemplate} icon={<FileSpreadsheet className="w-4 h-4 text-emerald-600" />}>
                                Unduh Template Excel
                            </Button>
                            <Button onClick={() => fileInputRef.current?.click()}>
                                Pilih File
                            </Button>
                        </div>
                        <input
                            type="file"
                            ref={fileInputRef}
                            className="hidden"
                            accept=".xlsx, .xls"
                            onChange={handleFileChange}
                        />
                    </div>

                ) : parseError ? (
                    /* ── Error parse ──────────────────────────────────────── */
                    <div className="space-y-4">
                        <div className="flex items-start gap-3 bg-rose-50 border border-rose-200 rounded-lg p-4">
                            <AlertCircle className="w-5 h-5 text-rose-500 shrink-0 mt-0.5" />
                            <div>
                                <p className="text-sm font-semibold text-rose-800 mb-1">File tidak dapat diproses</p>
                                <p className="text-sm text-rose-700 whitespace-pre-line">{parseError}</p>
                            </div>
                        </div>
                        <div className="flex justify-end">
                            <Button variant="outline" onClick={handleReset}>Pilih File Lain</Button>
                        </div>
                    </div>

                ) : hasil ? (
                    /* ── Selesai mengunggah ───────────────────────────────── */
                    <div className="space-y-4">
                        {uploadError ? (
                            <div className="flex items-start gap-3 bg-rose-50 border border-rose-200 rounded-lg p-4">
                                <AlertCircle className="w-5 h-5 text-rose-500 shrink-0 mt-0.5" />
                                <p className="text-sm text-rose-700">{uploadError}</p>
                            </div>
                        ) : (
                            <div className="flex items-center gap-3 bg-emerald-50 border border-emerald-200 rounded-lg p-4">
                                <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0" />
                                <p className="text-sm font-semibold text-emerald-800">Impor selesai.</p>
                            </div>
                        )}
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            {[
                                { label: 'Pasien baru',           value: hasil.baru,       color: 'bg-emerald-50 text-emerald-700' },
                                { label: 'Pasien lama diperbarui', value: hasil.diperbarui, color: 'bg-teal-50 text-teal-700' },
                                { label: 'Duplikat dilewati',     value: hasil.duplikat,   color: 'bg-slate-100 text-slate-700' },
                                { label: 'Gagal',                 value: hasil.invalid,    color: 'bg-rose-50 text-rose-700' },
                            ].map(c => (
                                <div key={c.label} className={`${c.color} rounded-xl p-3 text-center`}>
                                    <div className="text-2xl font-bold">{c.value}</div>
                                    <div className="text-xs font-medium mt-0.5">{c.label}</div>
                                </div>
                            ))}
                        </div>
                        <div className="flex justify-end">
                            <Button onClick={handleClose}>Tutup</Button>
                        </div>
                    </div>

                ) : (
                    /* ── File sudah diparse ───────────────────────────────── */
                    <div>

                        {/* Info file */}
                        <div className="flex items-center justify-between bg-slate-50 p-4 rounded-lg border border-slate-200 mb-4">
                            <div className="flex items-center gap-3">
                                <FileSpreadsheet className="w-8 h-8 text-emerald-600" />
                                <div>
                                    <div className="font-semibold text-slate-800">{file.name}</div>
                                    <div className="text-xs text-slate-500">
                                        {results.length} pasien terdeteksi
                                        {multiSheet
                                            ? ` dari ${sheetCount} sheet`
                                            : sheetCount === 1 ? ` — sheet: ${sheetNames[0]}` : ''}
                                        {' · '}{payload.length} baris transaksi akan dikirim
                                    </div>
                                </div>
                            </div>
                            <Button variant="ghost" size="sm" onClick={handleReset} disabled={!!progres}>
                                Ganti File
                            </Button>
                        </div>

                        {/* Info sheet (ParseMeta) */}
                        {parseMeta && (
                            <div className="bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs text-slate-700 mb-4">
                                <div className="flex flex-wrap gap-x-6 gap-y-1">
                                    <span>Sheet ditemukan: <strong>{parseMeta.totalSheets}</strong></span>
                                    <span>
                                        Sheet diproses: <strong>{parseMeta.processedSheets.length}</strong>
                                        {parseMeta.processedSheets.length > 0 && (
                                            <span className="text-slate-400"> ({parseMeta.processedSheets.join(', ')})</span>
                                        )}
                                    </span>
                                    {parseMeta.skippedSheets.length > 0 && (
                                        <span>
                                            Sheet dilewati: <strong>{parseMeta.skippedSheets.length}</strong>
                                            <span className="text-slate-400"> ({parseMeta.skippedSheets.join(', ')})</span>
                                        </span>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Tahun untuk kolom bulan */}
                        {adaKolomBulan && (
                            <div className="flex flex-wrap items-center gap-3 bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-900 mb-4">
                                <CalendarDays className="w-4 h-4 text-amber-600 shrink-0" />
                                <span>
                                    File berformat <span className="font-semibold">per bulan</span> — kolom bulan hanya berisi tanggal.
                                    Tahun untuk kolom bulan tanpa tahun di judulnya (kolom seperti "JANUARI 2027" memakai tahunnya sendiri):
                                </span>
                                <input
                                    type="number"
                                    min={2000}
                                    max={2100}
                                    value={tahunBulan}
                                    onChange={e => setTahunBulan(Number(e.target.value) || new Date().getFullYear())}
                                    disabled={!!progres}
                                    className="w-24 rounded-md border-amber-300 text-xs py-1 focus:border-teal-500 focus:ring-teal-500"
                                />
                            </div>
                        )}

                        {/* Ringkasan validasi */}
                        <div className="flex flex-wrap items-center gap-3 mb-4">
                            <div className="flex items-center gap-2 text-sm text-emerald-700 bg-emerald-50 px-3 py-1.5 rounded-lg border border-emerald-100">
                                <CheckCircle2 className="w-4 h-4" /> {validCount} Pasien Valid
                            </div>
                            {peringatanCount > 0 && (
                                <div className="flex items-center gap-2 text-sm text-amber-700 bg-amber-50 px-3 py-1.5 rounded-lg border border-amber-100">
                                    <TriangleAlert className="w-4 h-4" /> {peringatanCount} Perlu Dicek
                                </div>
                            )}
                            {invalidCount > 0 && (
                                <div className="flex items-center gap-2 text-sm text-rose-700 bg-rose-50 px-3 py-1.5 rounded-lg border border-rose-100">
                                    <AlertCircle className="w-4 h-4" /> {invalidCount} Tidak Valid
                                </div>
                            )}
                            {(peringatanCount > 0 || invalidCount > 0) && (
                                <label className="flex items-center gap-2 text-xs text-slate-600 ml-auto cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={hanyaPerluDicek}
                                        onChange={e => setHanyaPerluDicek(e.target.checked)}
                                        className="rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                    />
                                    Tampilkan hanya yang perlu dicek / tidak valid
                                </label>
                            )}
                        </div>

                        {/* Info anti-duplikasi */}
                        <div className="bg-sky-50 border border-sky-200 rounded-lg p-3 text-xs text-sky-800 flex items-start gap-2 mb-4">
                            <ShieldCheck className="w-4 h-4 text-sky-600 shrink-0 mt-0.5" />
                            <div>
                                <span className="font-semibold">Deteksi otomatis &amp; anti-duplikasi:</span>{' '}
                                Duplikasi dicek via <span className="font-medium">No. Faktur</span> (UPDATE jika koreksi),
                                lalu <span className="font-medium">NOKA + tanggal</span> — mengimpor ulang file yang sama aman.
                                Kolom Nama Pasien &amp; No. HP (jika ada di file) <span className="font-medium">diabaikan</span> sesuai ketentuan etik.
                                Baris <span className="font-medium">Perlu Dicek</span> tetap diimpor; periksa keterangannya.
                            </div>
                        </div>

                        {/* Peringatan 0 baris */}
                        {results.length === 0 && (
                            <div className="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800 mb-4">
                                <Info className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" />
                                <span>
                                    Tidak ada baris data yang dapat diproses dari sheet yang terdeteksi.
                                    Pastikan baris data sudah diisi di bawah baris header.
                                </span>
                            </div>
                        )}

                        {/* Tabel preview */}
                        {results.length > 0 && (
                            <div className="max-h-72 overflow-y-auto border border-slate-200 rounded-lg">
                                <table className="w-full text-sm text-left">
                                    <thead className="bg-slate-50 sticky top-0 z-10 text-xs font-semibold text-slate-600">
                                        <tr>
                                            <th className="px-3 py-2 border-b">Baris</th>
                                            {multiSheet && <th className="px-3 py-2 border-b whitespace-nowrap">Sheet</th>}
                                            <th className="px-3 py-2 border-b">NOKA</th>
                                            <th className="px-3 py-2 border-b">FKTP Asal</th>
                                            <th className="px-3 py-2 border-b">Diagnosis PRB</th>
                                            <th className="px-3 py-2 border-b whitespace-nowrap">Mulai PRB</th>
                                            <th className="px-3 py-2 border-b">Pengambilan</th>
                                            <th className="px-3 py-2 border-b">Status Validasi</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {ditampilkan.map((r, i) => (
                                            <tr key={i} className={!r.valid ? 'bg-rose-50/50 hover:bg-rose-50' : r.warnings.length ? 'bg-amber-50/40 hover:bg-amber-50' : 'hover:bg-slate-50/50'}>
                                                <td className="px-3 py-2 text-slate-500 font-mono text-xs">{r.row}</td>
                                                {multiSheet && (
                                                    <td className="px-3 py-2 text-xs text-blue-700 font-medium whitespace-nowrap">{r.sheet}</td>
                                                )}
                                                <td className="px-3 py-2 text-xs font-mono text-slate-800 whitespace-nowrap">{r.data.noka || '-'}</td>
                                                <td className="px-3 py-2 text-xs text-slate-600">{r.data.fktp_asal || '-'}</td>
                                                <td className="px-3 py-2 text-xs text-slate-700 font-medium">
                                                    {r.data.diagnosis_prb?.length > 0 ? r.data.diagnosis_prb.join(', ') : '-'}
                                                </td>
                                                <td className="px-3 py-2 text-xs text-slate-600 font-mono whitespace-nowrap">
                                                    {r.data.tanggal_mulai_prb ? fmtTgl(r.data.tanggal_mulai_prb) : '-'}
                                                </td>
                                                <td className="px-3 py-2 text-xs text-slate-600 font-mono">
                                                    {r.pengambilan.length > 0 ? r.pengambilan.map(fmtTgl).join(', ') : '-'}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {!r.valid ? (
                                                        <ul className="text-rose-600 text-xs list-disc pl-4 space-y-0.5">
                                                            {r.errors.map((err, eIdx) => <li key={eIdx}>{err}</li>)}
                                                        </ul>
                                                    ) : r.warnings.length > 0 ? (
                                                        <ul className="text-amber-700 text-xs list-disc pl-4 space-y-0.5">
                                                            {r.warnings.map((w, wIdx) => <li key={wIdx}>{w}</li>)}
                                                        </ul>
                                                    ) : (
                                                        <span className="text-emerald-600 font-medium text-xs flex items-center gap-1">
                                                            <CheckCircle2 className="w-3.5 h-3.5"/> Valid
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {/* Progres unggah */}
                        {progres && (
                            <div className="mt-4">
                                <div className="flex justify-between text-xs text-slate-600 mb-1">
                                    <span>Mengunggah… jangan tutup halaman ini.</span>
                                    <span>{progres.selesai} / {progres.total} baris</span>
                                </div>
                                <div className="w-full bg-slate-100 rounded-full h-2">
                                    <div
                                        className="bg-teal-500 h-2 rounded-full transition-all duration-300"
                                        style={{ width: `${Math.round((progres.selesai / progres.total) * 100)}%` }}
                                    />
                                </div>
                            </div>
                        )}

                        <div className="mt-6 flex justify-end gap-3 pt-4 border-t border-slate-100">
                            <Button variant="ghost" onClick={handleClose} disabled={!!progres}>Batal</Button>
                            <Button
                                onClick={handleUpload}
                                isLoading={!!progres}
                                disabled={payload.length === 0}
                                icon={<UploadCloud className="w-4 h-4"/>}
                            >
                                Import {validCount} Pasien ({payload.length} baris)
                            </Button>
                        </div>
                    </div>
                )}

            </div>
        </Modal>
    );
}
