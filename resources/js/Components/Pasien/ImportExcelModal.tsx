import { useState, useRef } from 'react';
import { Modal } from '../ui/Modal';
import { Button } from '../ui/Button';
import { router } from '@inertiajs/react';
import { UploadCloud, FileSpreadsheet, CheckCircle2, AlertCircle, ShieldCheck, Info } from 'lucide-react';
import { parseExcelFile, validateImportData, ValidationResult, ParseMeta, downloadImportTemplate } from '@/lib/excel-utils';

interface Props {
    isOpen: boolean;
    onClose: () => void;
}

export function ImportExcelModal({ isOpen, onClose }: Props) {
    const [file, setFile]             = useState<File | null>(null);
    const [results, setResults]       = useState<ValidationResult[]>([]);
    const [parseMeta, setParseMeta]   = useState<ParseMeta | null>(null);
    const [loading, setLoading]       = useState(false);
    const [parseError, setParseError] = useState<string | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const selected = e.target.files?.[0];
        if (!selected) return;

        setFile(selected);
        setParseError(null);
        setResults([]);
        setParseMeta(null);

        try {
            const data = await parseExcelFile(selected);

            // Ambil _parse_meta dari baris pertama
            const meta: ParseMeta | null = (data[0]?.['_parse_meta'] as ParseMeta) ?? null;
            setParseMeta(meta);

            const validated = validateImportData(data);
            setResults(validated);
        } catch (err: any) {
            setParseError(err.message || 'Gagal memproses file Excel.');
        }
    };

    const handleUpload = () => {
        const validData = results.filter(r => r.valid).map(r => r.data);
        if (validData.length === 0) {
            alert('Tidak ada baris data valid untuk diunggah.');
            return;
        }

        setLoading(true);
        router.post('/pasien/import/batch', {
            data: validData as any,
        }, {
            onSuccess: () => {
                setLoading(false);
                onClose();
                setFile(null);
                setResults([]);
                setParseMeta(null);
                setParseError(null);
            },
            onError: (errors) => {
                setLoading(false);
                const errorMsg = Object.values(errors).flat().join('\n');
                alert('Gagal mengunggah data:\n' + (errorMsg || 'Terjadi kesalahan validasi di server.'));
            },
            preserveScroll: true,
        });
    };

    const handleReset = () => {
        setFile(null);
        setResults([]);
        setParseMeta(null);
        setParseError(null);
        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    const validCount   = results.filter(r => r.valid).length;
    const invalidCount = results.filter(r => !r.valid).length;

    const sheetNames = Array.from(new Set(results.map(r => r.sheet)));
    const sheetCount = sheetNames.length;
    const multiSheet = sheetCount > 1;

    return (
        <Modal isOpen={isOpen} onClose={onClose} title="Import Data Pasien & Resep PRB (Excel)" maxWidth="4xl">
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
                            Sheet tanpa header data (mis. sheet Petunjuk) dilewati secara otomatis.
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
                        <input
                            type="file"
                            ref={fileInputRef}
                            className="hidden"
                            accept=".xlsx, .xls"
                            onChange={handleFileChange}
                        />
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
                                        {results.length} baris data terdeteksi
                                        {multiSheet
                                            ? ` dari ${sheetCount} sheet`
                                            : sheetCount === 1 ? ` — sheet: ${sheetNames[0]}` : ''}
                                    </div>
                                </div>
                            </div>
                            <Button variant="ghost" size="sm" onClick={handleReset}>
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
                                    <span>Baris data terdeteksi: <strong>{parseMeta.totalDataRows}</strong></span>
                                </div>
                            </div>
                        )}

                        {/* Ringkasan validasi */}
                        <div className="flex flex-wrap gap-3 mb-4">
                            <div className="flex items-center gap-2 text-sm text-emerald-700 bg-emerald-50 px-3 py-1.5 rounded-lg border border-emerald-100">
                                <CheckCircle2 className="w-4 h-4" /> {validCount} Baris Valid
                            </div>
                            {invalidCount > 0 && (
                                <div className="flex items-center gap-2 text-sm text-rose-700 bg-rose-50 px-3 py-1.5 rounded-lg border border-rose-100">
                                    <AlertCircle className="w-4 h-4" /> {invalidCount} Tidak Valid
                                </div>
                            )}
                            {multiSheet && (
                                <div className="flex items-center gap-2 text-sm text-blue-700 bg-blue-50 px-3 py-1.5 rounded-lg border border-blue-100">
                                    <FileSpreadsheet className="w-4 h-4" /> {sheetCount} Sheet data dibaca
                                </div>
                            )}
                        </div>

                        {/* Info anti-duplikasi */}
                        <div className="bg-sky-50 border border-sky-200 rounded-lg p-3 text-xs text-sky-800 flex items-start gap-2 mb-4">
                            <ShieldCheck className="w-4 h-4 text-sky-600 shrink-0 mt-0.5" />
                            <div>
                                <span className="font-semibold">Deteksi otomatis &amp; anti-duplikasi:</span>{' '}
                                Sheet diidentifikasi dari <span className="font-medium">struktur header</span>,
                                bukan dari nama atau posisi sheet. Sheet tanpa header data dilewati.
                                Data valid masuk ke staging <code className="bg-sky-100 px-1 py-0.5 rounded font-mono text-sky-900">resep_import</code>.
                                Duplikasi dicek via <span className="font-medium">No. Faktur</span> (UPDATE jika koreksi)
                                dan <span className="font-medium">No. BPJS + Tanggal Berobat</span> sebagai fallback.
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
                            <div className="max-h-64 overflow-y-auto border border-slate-200 rounded-lg">
                                <table className="w-full text-sm text-left">
                                    <thead className="bg-slate-50 sticky top-0 z-10 text-xs font-semibold text-slate-600">
                                        <tr>
                                            <th className="px-3 py-2 border-b">Baris</th>
                                            {multiSheet && <th className="px-3 py-2 border-b whitespace-nowrap">Sheet</th>}
                                            <th className="px-3 py-2 border-b">No. Faktur</th>
                                            <th className="px-3 py-2 border-b">Nama / BPJS</th>
                                            <th className="px-3 py-2 border-b">WhatsApp</th>
                                            <th className="px-3 py-2 border-b">FKTP Asal</th>
                                            <th className="px-3 py-2 border-b">Diagnosis PRB</th>
                                            <th className="px-3 py-2 border-b">Tgl Berobat</th>
                                            <th className="px-3 py-2 border-b">Status Validasi</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {results.map((r, i) => (
                                            <tr key={i} className={r.valid ? 'hover:bg-slate-50/50' : 'bg-rose-50/50 hover:bg-rose-50'}>
                                                <td className="px-3 py-2 text-slate-500 font-mono text-xs">{r.row}</td>
                                                {multiSheet && (
                                                    <td className="px-3 py-2 text-xs text-blue-700 font-medium whitespace-nowrap">{r.sheet}</td>
                                                )}
                                                <td className="px-3 py-2 text-xs font-mono text-slate-700">{r.data.no_faktur || '-'}</td>
                                                <td className="px-3 py-2">
                                                    <div className="font-medium text-slate-800">{r.data.nama_pasien || '-'}</div>
                                                    <div className="text-xs text-slate-500 font-mono">{r.data.no_kartu_bpjs || '-'}</div>
                                                </td>
                                                <td className="px-3 py-2 text-xs text-slate-600">{r.data.no_whatsapp || '-'}</td>
                                                <td className="px-3 py-2 text-xs text-slate-600">{r.data.fktp_asal || '-'}</td>
                                                <td className="px-3 py-2">
                                                    <div className="text-xs text-slate-700 font-medium">
                                                        {r.data.diagnosis_prb?.length > 0 ? r.data.diagnosis_prb.join(', ') : '-'}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2 text-xs text-slate-600 font-mono">
                                                    {r.data.tanggal_berobat || r.data.tanggal_pengambilan || r.data.tanggal_mulai_prb || '-'}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {r.valid ? (
                                                        <span className="text-emerald-600 font-medium text-xs flex items-center gap-1">
                                                            <CheckCircle2 className="w-3.5 h-3.5"/> Valid
                                                        </span>
                                                    ) : (
                                                        <ul className="text-rose-600 text-xs list-disc pl-4 space-y-0.5">
                                                            {r.errors.map((err, eIdx) => <li key={eIdx}>{err}</li>)}
                                                        </ul>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        <div className="mt-6 flex justify-end gap-3 pt-4 border-t border-slate-100">
                            <Button variant="ghost" onClick={onClose}>Batal</Button>
                            <Button
                                onClick={handleUpload}
                                isLoading={loading}
                                disabled={validCount === 0}
                                icon={<UploadCloud className="w-4 h-4"/>}
                            >
                                Import {validCount} Data Valid ke Data Utama
                            </Button>
                        </div>
                    </div>
                )}

            </div>
        </Modal>
    );
}
