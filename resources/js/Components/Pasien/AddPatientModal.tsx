import { useState } from 'react';
import { DIAGNOSIS_OPTIONS, ALASAN_PASIF_OPTIONS } from '@/types';
import { Modal } from '../ui/Modal';
import { Button } from '../ui/Button';
import { router } from '@inertiajs/react';
import { UserPlus, Save } from 'lucide-react';
import { samarkanNama } from '@/lib/samaran';

interface Props {
    isOpen: boolean;
    onClose: () => void;
}

export function AddPatientModal({ isOpen, onClose }: Props) {
    const [loading, setLoading] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formData, setFormData] = useState({
        noka: '',
        nama_samaran: '',
        no_whatsapp: '',
        fktp_asal: '',
        diagnosis_prb: [] as string[],
        tanggal_mulai_prb: new Date().toISOString().split('T')[0],
        tanggal_pengambilan_terakhir: '',
        status: 'Aktif',
        alasan_pasif: '',
    });

    const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
        const { name, value } = e.target;
        
        if (name === 'noka') {
            // NOKA / NOKA tersandi: format bebas, maks 100 karakter
            setFormData(prev => ({ ...prev, [name]: value.slice(0, 100) }));
        } else if (name === 'no_whatsapp') {
            // Hanya angka, spasi, tanda hubung, dan + (dirapikan di server)
            setFormData(prev => ({ ...prev, [name]: value.replace(/[^\d\s+-]/g, '').slice(0, 20) }));
        } else if (name === 'fktp_asal') {
            const cleanFktp = value.replace(/[^\p{L}\p{N}\p{P}\p{Z}]/gu, '');
            setFormData(prev => ({ ...prev, [name]: cleanFktp.slice(0, 255) }));
        } else {
            setFormData(prev => ({ ...prev, [name]: value }));
        }
    };

    const handleDiagnosisChange = (diagnosis: string) => {
        setFormData(prev => {
            const current = prev.diagnosis_prb;
            if (current.includes(diagnosis)) {
                return { ...prev, diagnosis_prb: current.filter(d => d !== diagnosis) };
            } else {
                return { ...prev, diagnosis_prb: [...current, diagnosis] };
            }
        });
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({}); // reset error sebelum submit baru

        // Rapikan spasi berlebih
        const cleanNoka = formData.noka.trim();
        const cleanFktp = formData.fktp_asal.replace(/\s+/g, ' ').trim();
        
        if (!cleanNoka || !cleanFktp || formData.diagnosis_prb.length === 0) return; // Prevent empty submission

        const payload = {
            ...formData,
            noka: cleanNoka,
            nama_samaran: samarkanNama(formData.nama_samaran),
            no_whatsapp: formData.no_whatsapp.trim(),
            fktp_asal: cleanFktp
        };

        setLoading(true);

        router.post('/pasien', payload, {
            onSuccess: () => {
                setLoading(false);
                setErrors({});
                onClose();
            },
            onError: (errs) => {
                setLoading(false);
                // Tampilkan error validasi dari server (mis. NOKA sudah terdaftar)
                const mapped: Record<string, string> = {};
                Object.entries(errs).forEach(([k, v]) => {
                    mapped[k] = Array.isArray(v) ? v[0] : String(v);
                });
                setErrors(mapped);
            },
            preserveScroll: true,
        });
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} title="Tambah Pasien PRB" maxWidth="2xl">
            <form onSubmit={handleSubmit} className="space-y-6">
                {/* Error summary dari server */}
                {Object.keys(errors).length > 0 && (
                    <div className="bg-rose-50 border border-rose-200 rounded-lg px-4 py-3 text-sm text-rose-700 space-y-1">
                        {Object.entries(errors).map(([field, msg]) => (
                            <div key={field} className="flex items-start gap-1.5">
                                <span className="font-medium shrink-0">⚠</span>
                                <span>{msg}</span>
                            </div>
                        ))}
                    </div>
                )}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Data Pribadi */}
                    <div className="space-y-4">
                        <h4 className="text-sm font-semibold text-slate-800 border-b border-slate-200 pb-2">Identitas Pasien</h4>

                        <div>
                            <label htmlFor="noka" className="block text-xs font-medium text-slate-700 mb-1">NOKA / NOKA Tersandi *</label>
                            <input
                                type="text"
                                id="noka"
                                name="noka"
                                value={formData.noka}
                                onChange={handleChange}
                                required
                                maxLength={100}
                                placeholder="Contoh: PRB KPMA 001"
                                className={`w-full rounded-lg border px-3 py-2 text-sm font-mono focus:border-teal-500 focus:ring-teal-500 ${errors.noka ? 'border-rose-400 bg-rose-50' : 'border-slate-300'}`}
                            />
                            {errors.noka && (
                                <p className="text-xs text-rose-600 mt-1 font-medium">⚠ {errors.noka}</p>
                            )}
                        </div>

                        <div>
                            <label htmlFor="nama_samaran" className="block text-xs font-medium text-slate-700 mb-1">Nama Pasien (Opsional)</label>
                            <input
                                type="text"
                                id="nama_samaran"
                                name="nama_samaran"
                                value={formData.nama_samaran}
                                onChange={handleChange}
                                maxLength={150}
                                placeholder="Contoh: Rizka Dwi Putri"
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500"
                            />
                            <p className="text-xs text-slate-500 mt-1">
                                {formData.nama_samaran.trim()
                                    ? <>Disimpan tersamar: <span className="font-mono">{samarkanNama(formData.nama_samaran)}</span></>
                                    : 'Sesuai ketentuan etik, nama hanya disimpan tersamar (huruf pertama & terakhir tiap kata).'}
                            </p>
                        </div>

                        <div>
                            <label htmlFor="no_whatsapp" className="block text-xs font-medium text-slate-700 mb-1">No. WhatsApp (Opsional)</label>
                            <input
                                type="tel"
                                id="no_whatsapp"
                                name="no_whatsapp"
                                value={formData.no_whatsapp}
                                onChange={handleChange}
                                inputMode="tel"
                                placeholder="Contoh: 081234567890"
                                className={`w-full rounded-lg border px-3 py-2 text-sm font-mono focus:border-teal-500 focus:ring-teal-500 ${errors.no_whatsapp ? 'border-rose-400 bg-rose-50' : 'border-slate-300'}`}
                            />
                            {errors.no_whatsapp && (
                                <p className="text-xs text-rose-600 mt-1 font-medium">⚠ {errors.no_whatsapp}</p>
                            )}
                            <p className="text-xs text-slate-500 mt-1">
                                Isi hanya jika pasien bersedia diingatkan jadwal ambil obat lewat WhatsApp. Boleh dikosongkan.
                            </p>
                        </div>
                    </div>

                    {/* Data Medis */}
                    <div className="space-y-4">
                        <h4 className="text-sm font-semibold text-slate-800 border-b border-slate-200 pb-2">Data Program PRB</h4>
                        
                        <div>
                            <label className="block text-xs font-medium text-slate-700 mb-1">Diagnosis PRB * <span className="font-normal text-slate-500">(lebih dari satu → dibuat satu data per diagnosis)</span></label>
                            <div className="grid grid-cols-2 gap-2 mt-2">
                                {DIAGNOSIS_OPTIONS.map(opt => (
                                    <label key={opt} className="flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                                        <input 
                                            type="checkbox" 
                                            checked={formData.diagnosis_prb.includes(opt)}
                                            onChange={() => handleDiagnosisChange(opt)}
                                            className="w-4 h-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                        />
                                        {opt}
                                    </label>
                                ))}
                            </div>
                            {formData.diagnosis_prb.length === 0 && (
                                <p className="text-xs text-rose-500 mt-1">Pilih minimal satu diagnosis.</p>
                            )}
                        </div>
                        
                        <div>
                            <label htmlFor="fktp_asal" className="block text-xs font-medium text-slate-700 mb-1">FKTP Asal (Perujuk) *</label>
                            <input 
                                type="text" 
                                id="fktp_asal"
                                name="fktp_asal" 
                                value={formData.fktp_asal} 
                                onChange={handleChange} 
                                required 
                                maxLength={255}
                                onInvalid={(e) => {
                                    const target = e.target as HTMLInputElement;
                                    if (target.validity.valueMissing) {
                                        target.setCustomValidity('FKTP Asal wajib diisi.');
                                    }
                                }}
                                onInput={(e) => (e.target as HTMLInputElement).setCustomValidity('')}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500" 
                            />
                        </div>
                        
                        <div className="grid grid-cols-1 gap-3">
                            <div>
                                <label htmlFor="tanggal_mulai_prb" className="block text-xs font-medium text-slate-700 mb-1">Tgl Mulai PRB *</label>
                                <input 
                                    type="date" 
                                    id="tanggal_mulai_prb"
                                    name="tanggal_mulai_prb" 
                                    value={formData.tanggal_mulai_prb} 
                                    onChange={handleChange} 
                                    required 
                                    onInvalid={(e) => {
                                        const target = e.target as HTMLInputElement;
                                        if (target.validity.valueMissing) {
                                            target.setCustomValidity('Tgl Mulai PRB wajib diisi.');
                                        }
                                    }}
                                    onInput={(e) => (e.target as HTMLInputElement).setCustomValidity('')}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500" 
                                />
                                {!formData.tanggal_pengambilan_terakhir && formData.tanggal_mulai_prb && (
                                    <p className="text-[10px] text-teal-600 mt-1">Jadwal pertama = Tgl Mulai PRB + 23 hari bila mulai bulan ini (PRB Baru), + 30 hari bila mulai bulan sebelumnya (PRB Lama).</p>
                                )}
                            </div>
                        </div>

                        <div>
                            <label htmlFor="tanggal_pengambilan_terakhir" className="block text-xs font-medium text-slate-700 mb-1">Tgl Pengambilan Terakhir (Jika ada)</label>
                            <input 
                                type="date" 
                                id="tanggal_pengambilan_terakhir"
                                name="tanggal_pengambilan_terakhir"
                                value={formData.tanggal_pengambilan_terakhir}
                                max={new Date().toLocaleDateString('en-CA')}
                                onChange={handleChange} 
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500" 
                            />
                            {formData.tanggal_pengambilan_terakhir && (
                                <p className="text-[10px] text-teal-600 mt-1">Jadwal berikutnya = tanggal pengambilan + 30 hari (PRB Lama), atau + 23 hari bila mulai PRB di bulan yang sama (PRB Baru).</p>
                            )}
                        </div>
                    </div>
                </div>

                <div className="pt-4 flex gap-3 justify-end border-t border-slate-100">
                    <Button type="button" variant="ghost" onClick={onClose}>Batal</Button>
                    <Button type="submit" isLoading={loading} icon={<Save className="w-4 h-4" />}>Simpan Pasien</Button>
                </div>
            </form>
        </Modal>
    );
}
