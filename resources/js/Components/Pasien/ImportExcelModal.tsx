import { useState, useRef } from 'react';
import { Modal } from '../ui/Modal';
import { Button } from '../ui/Button';
import { router } from '@inertiajs/react';
import { UploadCloud, FileSpreadsheet, CheckCircle2, AlertCircle } from 'lucide-react';
import { parseExcelFile, validateImportData, ValidationResult, downloadImportTemplate } from '@/lib/excel-utils';

interface Props {
    isOpen: boolean;
    onClose: () => void;
}

export function ImportExcelModal({ isOpen, onClose }: Props) {
    const [file, setFile] = useState<File | null>(null);
    const [results, setResults] = useState<ValidationResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [mode, setMode] = useState<'update' | 'skip'>('skip');
    const fileInputRef = useRef<HTMLInputElement>(null);

    const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const selected = e.target.files?.[0];
        if (!selected) return;

        setFile(selected);
        try {
            const data = await parseExcelFile(selected);
            const validated = validateImportData(data);
            setResults(validated);
        } catch (err: any) {
            alert(err.message || 'Gagal memproses file Excel.');
            setFile(null);
            setResults([]);
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
            data: validData,
            mode,
        }, {
            onSuccess: () => {
                setLoading(false);
                onClose();
                // Reset state
                setFile(null);
                setResults([]);
            },
            onError: () => setLoading(false),
            preserveScroll: true,
        });
    };

    const validCount = results.filter(r => r.valid).length;
    const invalidCount = results.filter(r => !r.valid).length;

    return (
        <Modal isOpen={isOpen} onClose={onClose} title="Import Data Pasien (Excel)" maxWidth="3xl">
            <div className="space-y-6">
                {!file ? (
                    <div className="flex flex-col items-center justify-center border-2 border-dashed border-slate-300 rounded-xl p-10 bg-slate-50 hover:bg-slate-100 transition-colors">
                        <UploadCloud className="w-12 h-12 text-slate-400 mb-3" />
                        <h4 className="text-slate-800 font-medium mb-1">Pilih atau letakkan file Excel di sini</h4>
                        <p className="text-slate-500 text-sm mb-4">Mendukung format .xlsx dan .xls</p>
                        
                        <div className="flex gap-3">
                            <Button variant="outline" onClick={downloadImportTemplate} icon={<FileSpreadsheet className="w-4 h-4 text-emerald-600" />}>
                                Unduh Template
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
                ) : (
                    <div>
                        <div className="flex items-center justify-between bg-slate-50 p-4 rounded-lg border border-slate-200 mb-4">
                            <div className="flex items-center gap-3">
                                <FileSpreadsheet className="w-8 h-8 text-emerald-600" />
                                <div>
                                    <div className="font-semibold text-slate-800">{file.name}</div>
                                    <div className="text-xs text-slate-500">{results.length} baris data diproses</div>
                                </div>
                            </div>
                            <Button variant="ghost" size="sm" onClick={() => { setFile(null); setResults([]); }}>
                                Ganti File
                            </Button>
                        </div>

                        <div className="flex gap-4 mb-4">
                            <div className="flex items-center gap-2 text-sm text-emerald-700 bg-emerald-50 px-3 py-1.5 rounded-lg border border-emerald-100">
                                <CheckCircle2 className="w-4 h-4" /> {validCount} Siap Diunggah
                            </div>
                            {invalidCount > 0 && (
                                <div className="flex items-center gap-2 text-sm text-rose-700 bg-rose-50 px-3 py-1.5 rounded-lg border border-rose-100">
                                    <AlertCircle className="w-4 h-4" /> {invalidCount} Tidak Valid
                                </div>
                            )}
                        </div>

                        <div className="mb-4">
                            <label className="block text-sm font-medium text-slate-700 mb-2">Penanganan Duplikasi (Kode Pasien)</label>
                            <div className="flex gap-4">
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="radio" checked={mode === 'skip'} onChange={() => setMode('skip')} className="text-teal-600 focus:ring-teal-500" />
                                    Lewati (Jangan Timpa)
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="radio" checked={mode === 'update'} onChange={() => setMode('update')} className="text-teal-600 focus:ring-teal-500" />
                                    Timpa Data (Update)
                                </label>
                            </div>
                        </div>

                        <div className="max-h-60 overflow-y-auto border border-slate-200 rounded-lg">
                            <table className="w-full text-sm text-left">
                                <thead className="bg-slate-50 sticky top-0 z-10">
                                    <tr>
                                        <th className="px-4 py-2 border-b">Baris</th>
                                        <th className="px-4 py-2 border-b">Nama / BPJS</th>
                                        <th className="px-4 py-2 border-b">Status Validasi</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {results.map((r, i) => (
                                        <tr key={i} className={r.valid ? '' : 'bg-rose-50/50'}>
                                            <td className="px-4 py-2">{r.row}</td>
                                            <td className="px-4 py-2">
                                                <div className="font-medium">{r.data.nama_pasien || '-'}</div>
                                                <div className="text-xs text-slate-500">{r.data.no_kartu_bpjs || '-'}</div>
                                            </td>
                                            <td className="px-4 py-2">
                                                {r.valid ? (
                                                    <span className="text-emerald-600 flex items-center gap-1"><CheckCircle2 className="w-3 h-3"/> Valid</span>
                                                ) : (
                                                    <ul className="text-rose-600 text-xs list-disc pl-4 space-y-0.5">
                                                        {r.errors.map((err, idx) => <li key={idx}>{err}</li>)}
                                                    </ul>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="mt-6 flex justify-end gap-3 pt-4 border-t border-slate-100">
                            <Button variant="ghost" onClick={onClose}>Batal</Button>
                            <Button onClick={handleUpload} isLoading={loading} disabled={validCount === 0} icon={<UploadCloud className="w-4 h-4"/>}>
                                Unggah {validCount} Data
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </Modal>
    );
}
