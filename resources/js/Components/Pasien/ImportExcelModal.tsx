import { useState, useRef, useMemo } from 'react';
import { Modal } from '../ui/Modal';
import { Button } from '../ui/Button';
import { router } from '@inertiajs/react';
import { csrfHeaders } from '@/lib/csrf';
import { UploadCloud, FileSpreadsheet, CheckCircle2, AlertCircle, Info, TriangleAlert, CalendarDays, Download } from 'lucide-react';
import {
    parseExcelFile, validateImportData, buildImportPayload, kolomBulan, penandaBaris,
    ValidationResult, ParseMeta, downloadImportTemplate, exportToExcel,
} from '@/lib/excel-utils';
import { jumlahPasienUnik } from '@/lib/pasien-unik';

interface Props {
    isOpen: boolean;
    onClose: () => void;
}

/** Jawaban server per potongan (satuan: baris transaksi, kecuali pasien & baris_gagal). */
interface HasilPotongan {
    baru: number;
    diperbarui: number;
    duplikat: number;
    invalid: number;
    pasien?: Record<string, string[]>; // NOKA -> diagnosis yang tersimpan
    baris_gagal?: string[];
}

/** Ringkasan impor - dihitung per pasien (NOKA unik), bukan per diagnosis/transaksi. */
interface HasilImpor {
    pasien: number;            // pasien yang datanya berhasil di-import
    pasienMultiDiagnosis: number;
    transaksi: number;         // transaksi pengambilan yang tersimpan
    duplikat: number;          // transaksi yang sudah ada -> dilewati
    gagal: number;             // baris tidak valid (sama dengan "Tidak Valid" di pratinjau)
}

/**
 * Baris dikirim per potongan agar file besar (ribuan transaksi) tidak
 * melewati batas waktu satu request. Urutan baris tetap dipertahankan —
 * backend menilai tiap pengambilan terhadap jadwal dari pengambilan sebelumnya.
 */
const UKURAN_POTONGAN = 500;

/**
 * Kelompok alasan untuk ringkasan buffer: bagian sebelum ":" (bagian setelahnya
 * berisi tanggal/kolom yang berbeda-beda per baris) dan tanpa saran format.
 */
function kelompokAlasan(teks: string): string {
    return teks.split(':')[0].split('. Gunakan')[0].replace(/^Kolom "[^"]*"/, 'Kolom').trim();
}

function hitungAlasan(daftar: string[][]): [string, number][] {
    const jumlah = new Map<string, number>();
    for (const alasanBaris of daftar) {
        // Satu baris dihitung sekali per alasan
        for (const a of new Set(alasanBaris.map(kelompokAlasan))) {
            jumlah.set(a, (jumlah.get(a) ?? 0) + 1);
        }
    }
    return [...jumlah.entries()].sort((a, b) => b[1] - a[1]);
}

/** CSRF dari cookie XSRF-TOKEN (selalu terbaru), fallback meta tag. */
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
        const diagnosisPerNoka = new Map<string, Set<string>>();
        const barisGagal = new Set(results.filter(r => !r.valid).map(penandaBaris));
        let transaksi = 0, duplikat = 0;
        const ringkas = (): HasilImpor => ({
            pasien: jumlahPasienUnik([...diagnosisPerNoka.keys()]),
            pasienMultiDiagnosis: [...diagnosisPerNoka.values()].filter(d => d.size > 1).length,
            transaksi, duplikat, gagal: barisGagal.size,
        });
        setProgres({ selesai: 0, total: payload.length });

        // Baris tidak valid dicatat ke tabel buffer beserta alasannya
        const tidakValid = results.filter(r => !r.valid).map(r => ({
            kode_pasien: r.data.kode_pasien, noka: r.data.noka, fktp_asal: r.data.fktp_asal,
            diagnosis_prb: r.data.diagnosis_prb, no_faktur: r.data.no_faktur,
            tanggal_mulai_prb: r.data.tanggal_mulai_prb, tanggal_berobat: r.data.tanggal_berobat,
            tanggal_pengambilan: r.pengambilan[0] ?? r.data.tanggal_pengambilan,
            keterangan: `${r.sheet} baris ${r.row}: ${r.errors.join('; ')}`.slice(0, 2000),
        }));
        for (let i = 0; i < tidakValid.length; i += UKURAN_POTONGAN) {
            try {
                await fetch('/pasien/import/tidak-valid', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                    body: JSON.stringify({ data: tidakValid.slice(i, i + UKURAN_POTONGAN) }),
                });
            } catch {
                // Hanya catatan buffer - tidak menghalangi impor data valid
            }
        }

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
                const json: HasilPotongan = await res.json();
                transaksi += (json.baru ?? 0) + (json.diperbarui ?? 0);
                duplikat  += json.duplikat ?? 0;
                Object.entries(json.pasien ?? {}).forEach(([noka, daftar]) => {
                    const kunci = noka.replace(/\s+/g, '').toUpperCase();
                    const set = diagnosisPerNoka.get(kunci) ?? new Set<string>();
                    daftar.forEach(d => set.add(d));
                    diagnosisPerNoka.set(kunci, set);
                });
                (json.baris_gagal ?? []).forEach(b => barisGagal.add(b));
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
                setHasil(ringkas());
                router.reload();
                return;
            }
        }

        setProgres(null);
        setHasil(ringkas());
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

    // Pasien (NOKA unik) yang siap diimpor — sama dengan "Jumlah pasien di-import" sesudahnya
    const pasienValid    = jumlahPasienUnik(results.filter(r => r.valid).map(r => r.data.noka));
    const invalidCount   = results.filter(r => !r.valid).length;
    const peringatanCount = results.filter(r => r.valid && r.warnings.length > 0).length;

    const sheetNames = Array.from(new Set(results.map(r => r.sheet)));
    const sheetCount = sheetNames.length;
    const multiSheet = sheetCount > 1;

    const ditampilkan = hanyaPerluDicek
        ? results.filter(r => !r.valid || r.warnings.length > 0)
        : results;

    // Rincian buffer: alasan baris tidak valid & yang perlu dicek
    const alasanTidakValid = useMemo(() => hitungAlasan(results.filter(r => !r.valid).map(r => r.errors)), [results]);
    const alasanPerluDicek = useMemo(() => hitungAlasan(results.filter(r => r.valid && r.warnings.length > 0).map(r => r.warnings)), [results]);

    const unduhRincian = () => {
        const baris = results
            .filter(r => !r.valid || r.warnings.length > 0)
            .map(r => ({
                'Sheet': r.sheet,
                'Baris': r.row,
                'NOKA': r.data.noka || '-',
                'Nama Pasien': r.data.nama_samaran || '-',
                'FKTP Asal': r.data.fktp_asal || '-',
                'Status': r.valid ? 'Perlu Dicek (tetap diimpor)' : 'Tidak Valid (tidak diimpor)',
                'Keterangan': (r.valid ? r.warnings : r.errors).join('; '),
            }));
        exportToExcel(baris, `Rincian_Validasi_Import_${new Date().toLocaleDateString('en-CA')}.xlsx`);
    };

    return (
        <Modal isOpen={isOpen} onClose={handleClose} title="Import Data Pasien & Resep PRB (Excel)" maxWidth="4xl">
            <div className="space-y-6">

                {!file ? (
                    /* ── Belum pilih file ─────────────────────────────────── */
                    <div className="flex flex-col items-center justify-center border-2 border-dashed border-slate-300 rounded-xl p-10 bg-slate-50 hover:bg-slate-100 transition-colors">
                        <UploadCloud className="w-12 h-12 text-teal-600 mb-3" />
                        <h4 className="text-slate-800 font-medium mb-1">Pilih file Excel</h4>
                        <p className="text-slate-500 text-sm mb-4">Format .xlsx atau .xls</p>
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
                                // `arti` tampil saat kursor diarahkan ke kartu
                                { label: 'Jumlah pasien di-import',                        value: hasil.pasien,               color: 'bg-emerald-50 text-emerald-700', arti: 'Pasien (NOKA unik) yang tersimpan; beberapa diagnosis dihitung satu pasien' },
                                { label: 'Jumlah pasien dengan diagnosis lebih dari satu', value: hasil.pasienMultiDiagnosis, color: 'bg-teal-50 text-teal-700',       arti: 'Bagian dari pasien di-import yang punya 2 diagnosis atau lebih' },
                                { label: 'Duplikat dilewati',                              value: hasil.duplikat,             color: 'bg-slate-100 text-slate-700',   arti: 'Transaksi pengambilan yang sudah ada, tidak disimpan ulang' },
                                { label: 'Gagal karena tidak valid',                       value: hasil.gagal,                color: 'bg-rose-50 text-rose-700',      arti: 'Baris tidak memenuhi syarat; dicatat di tabel buffer, tidak masuk data pasien' },
                            ].map(c => (
                                <div key={c.label} title={c.arti} className={`${c.color} rounded-xl p-3 text-center`}>
                                    <div className="text-2xl font-bold">{c.value}</div>
                                    <div className="text-xs font-medium mt-0.5">{c.label}</div>
                                </div>
                            ))}
                        </div>
                        <p className="text-xs text-slate-500">{hasil.transaksi} transaksi pengambilan obat tersimpan.</p>
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
                                        Sheet: {sheetNames.join(', ') || '-'}
                                        {parseMeta && parseMeta.skippedSheets.length > 0 && ` · dilewati: ${parseMeta.skippedSheets.join(', ')}`}
                                    </div>
                                </div>
                            </div>
                            <Button variant="ghost" size="sm" onClick={handleReset} disabled={!!progres}>
                                Ganti File
                            </Button>
                        </div>

                        {/* Tahun untuk kolom bulan (MARET / APRIL / MEI tanpa tahun di judul) */}
                        {adaKolomBulan && (
                            <label className="flex items-center gap-2 text-xs text-slate-600 mb-4">
                                <CalendarDays className="w-4 h-4 text-slate-400 shrink-0" />
                                Tahun data
                                <input
                                    type="number"
                                    min={2000}
                                    max={2100}
                                    value={tahunBulan}
                                    onChange={e => setTahunBulan(Number(e.target.value) || new Date().getFullYear())}
                                    disabled={!!progres}
                                    className="w-20 rounded-md border-slate-300 text-xs py-1 focus:border-teal-500 focus:ring-teal-500"
                                />
                            </label>
                        )}

                        {/* Ringkasan validasi */}
                        <div className="flex flex-wrap items-center gap-3 mb-4">
                            <div className="flex items-center gap-2 text-sm text-emerald-700 bg-emerald-50 px-3 py-1.5 rounded-lg border border-emerald-100">
                                <CheckCircle2 className="w-4 h-4" /> {pasienValid} Pasien Valid
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
                                    Hanya yang bermasalah
                                </label>
                            )}
                        </div>

                        {/* Rincian buffer per alasan */}
                        {(alasanTidakValid.length > 0 || alasanPerluDicek.length > 0) && (
                            <div className="border border-slate-200 rounded-lg p-3 mb-4 text-xs">
                                <div className="flex items-center justify-between gap-2 mb-2">
                                    <span className="font-semibold text-slate-700">Rincian</span>
                                    <button
                                        type="button"
                                        onClick={unduhRincian}
                                        className="inline-flex items-center gap-1 text-teal-700 hover:text-teal-900 font-medium"
                                    >
                                        <Download className="w-3.5 h-3.5" /> Unduh rincian
                                    </button>
                                </div>
                                <div className="grid sm:grid-cols-2 gap-3">
                                    {alasanTidakValid.length > 0 && (
                                        <div>
                                            <div className="font-medium text-rose-700 mb-1">Tidak valid (tidak diimpor)</div>
                                            <ul className="space-y-0.5 text-slate-600">
                                                {alasanTidakValid.map(([alasan, n]) => (
                                                    <li key={alasan} className="flex justify-between gap-2"><span>{alasan}</span><span className="font-mono text-rose-700">{n}</span></li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}
                                    {alasanPerluDicek.length > 0 && (
                                        <div>
                                            <div className="font-medium text-amber-700 mb-1">Perlu dicek (tetap diimpor)</div>
                                            <ul className="space-y-0.5 text-slate-600">
                                                {alasanPerluDicek.map(([alasan, n]) => (
                                                    <li key={alasan} className="flex justify-between gap-2"><span>{alasan}</span><span className="font-mono text-amber-700">{n}</span></li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Peringatan 0 baris */}
                        {results.length === 0 && (
                            <div className="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800 mb-4">
                                <Info className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" />
                                <span>Tidak ada baris data di bawah baris judul kolom.</span>
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
                                            <th className="px-3 py-2 border-b whitespace-nowrap">Nama (tersamar)</th>
                                            <th className="px-3 py-2 border-b">FKTP Asal</th>
                                            <th className="px-3 py-2 border-b">Diagnosis PRB</th>
                                            <th className="px-3 py-2 border-b whitespace-nowrap">Mulai PRB</th>
                                            <th className="px-3 py-2 border-b">Pengambilan</th>
                                            <th className="px-3 py-2 border-b">Keterangan</th>
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
                                                <td className="px-3 py-2 text-xs text-slate-700 whitespace-nowrap">{r.data.nama_samaran || '-'}</td>
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
                                    <span>Mengimpor… jangan tutup halaman ini.</span>
                                    <span>{Math.round((progres.selesai / progres.total) * 100)}%</span>
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
                                Import {pasienValid} Pasien
                            </Button>
                        </div>
                    </div>
                )}

            </div>
        </Modal>
    );
}
