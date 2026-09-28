import { useState, useRef } from 'react';
import { Modal } from '../ui/Modal';
import { Button } from '../ui/Button';
import { router } from '@inertiajs/react';
import { UploadCloud, FileSpreadsheet, CheckCircle2, AlertCircle, ShieldCheck } from 'lucide-react';
import { parseExcelFile, validateImportData, ValidationResult, downloadImportTemplate } from '@/lib/excel-utils';

interface Props {
    isOpen: boolean;
    onClose: () => void;
}

export function ImportExcelModal({ isOpen, onClose }: Props) {
    const [file, setFile] = useState<File | null>(null);
    const [results, setResults] = useState<ValidationResult[]>([]);
    const [loading, setLoading] = useState(false);
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
            data: validData as any,
        }, {
            onSuccess: () => {
                setLoading(false);
                onClose();
                // Reset state
                setFile(null);
                setResults([]);
            },
            onError: (errors) => {
                setLoading(false);
                const errorMsg = Object.values(errors).flat().join('\n');
                alert('Gagal mengunggah data:\n' + (errorMsg || 'Terjadi kesalahan validasi di server.'));
            },
            preserveScroll: true,
        });
    };

    const validCount = results.filter(r => r.valid).length;
    const invalidCount = results.filter(r => !r.valid).length;

    return (
        <Modal isOpen={isOpen} onClose={onClose} title="Import Data Pasien & Resep PRB (Excel)" maxWidth="4xl">
            <div className="space-y-6">
                {!file ? (
                    <div className="flex flex-col items-center justify-center border-2 border-dashed border-slate-300 rounded-xl p-10 bg-slate-50 hover:bg-slate-100 transition-colors">
                        <UploadCloud className="w-12 h-12 text-teal-600 mb-3" />
                        <h4 className="text-slate-800 font-medium mb-1">Pilih atau letakkan file Excel di sini</h4>
                        <p className="text-slate-500 text-sm mb-4">Mendukung format .xlsx dan .xls (Wajib sertakan No. Faktur / No. Transaksi Apotek)</p>
                        
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
                                <CheckCircle2 className="w-4 h-4" /> {validCount} Baris Valid
                            </div>
                            {invalidCount > 0 && (
                                <div className="flex items-center gap-2 text-sm text-rose-700 bg-rose-50 px-3 py-1.5 rounded-lg border border-rose-100">
                                    <AlertCircle className="w-4 h-4" /> {invalidCount} Tidak Valid
                                </div>
                            )}
                        </div>

                        <div className="bg-sky-50 border border-sky-200 rounded-lg p-3 text-xs text-sky-800 flex items-start gap-2 mb-4">
                            <ShieldCheck className="w-4 h-4 text-sky-600 shrink-0 mt-0.5" />
                            <div>
                                <span className="font-semibold">Alur Buffer Staging & Anti-Duplikasi:</span> Data akan dimasukkan ke tabel penampungan <code className="bg-sky-100 px-1 py-0.5 rounded font-mono text-sky-900">resep_import</code>. Pengecekan duplikasi menggunakan <span className="font-medium">No. Faktur / Transaksi Apotek</span> dan <span className="font-medium">No. Kartu BPJS + Tanggal Berobat</span>. Data lama tidak akan ditimpa.
                            </div>
                        </div>

                        <div className="max-h-64 overflow-y-auto border border-slate-200 rounded-lg">
                            <table className="w-full text-sm text-left">
                                <thead className="bg-slate-50 sticky top-0 z-10 text-xs font-semibold text-slate-600">
                                    <tr>
                                        <th className="px-3 py-2 border-b">Baris</th>
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
                                            <td className="px-3 py-2 text-xs text-slate-600 font-mono">{r.data.tanggal_berobat || r.data.tanggal_pengambilan_terakhir || r.data.tanggal_mulai_prb || '-'}</td>
                                            <td className="px-3 py-2">
                                                {r.valid ? (
                                                    <span className="text-emerald-600 font-medium text-xs flex items-center gap-1">
                                                        <CheckCircle2 className="w-3.5 h-3.5"/> Valid
                                                    </span>
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
                                Import {validCount} Data Valid ke Data Utama
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </Modal>
    );
}
