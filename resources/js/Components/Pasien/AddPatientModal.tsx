import { useState } from 'react';
import { DIAGNOSIS_OPTIONS, ALASAN_PASIF_OPTIONS } from '@/types';
import { Modal } from '../ui/Modal';
import { Button } from '../ui/Button';
import { router } from '@inertiajs/react';
import { UserPlus, Save } from 'lucide-react';

interface Props {
    isOpen: boolean;
    onClose: () => void;
}

export function AddPatientModal({ isOpen, onClose }: Props) {
    const [loading, setLoading] = useState(false);
    const [formData, setFormData] = useState({
        nama_pasien: '',
        no_kartu_bpjs: '',
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
        
        if (name === 'no_kartu_bpjs') {
            // Hanya izinkan angka, tolak yang lain
            const onlyNums = value.replace(/\D/g, '');
            // Maksimal 13 digit
            const truncated = onlyNums.slice(0, 13);
            setFormData(prev => ({ ...prev, [name]: truncated }));
        } else if (name === 'no_whatsapp') {
            const onlyNums = value.replace(/\D/g, '');
            const truncated = onlyNums.slice(0, 13);
            setFormData(prev => ({ ...prev, [name]: truncated }));
        } else if (name === 'nama_pasien') {
            const onlyLetters = value.replace(/[^a-zA-Z\s']/g, '');
            setFormData(prev => ({ ...prev, [name]: onlyLetters }));
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

        // Rapikan spasi berlebih
        const cleanName = formData.nama_pasien.replace(/\s+/g, ' ').trim();
        const cleanFktp = formData.fktp_asal.replace(/\s+/g, ' ').trim();
        
        if (!cleanName || !cleanFktp || formData.diagnosis_prb.length === 0) return; // Prevent empty submission

        const payload = {
            ...formData,
            nama_pasien: cleanName,
            fktp_asal: cleanFktp
        };

        setLoading(true);

        router.post('/pasien', payload, {
            onSuccess: () => {
                setLoading(false);
                onClose();
            },
            onError: () => {
                setLoading(false);
            },
            preserveScroll: true,
        });
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} title="Tambah Pasien PRB" maxWidth="2xl">
            <form onSubmit={handleSubmit} className="space-y-6">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Data Pribadi */}
                    <div className="space-y-4">
                        <h4 className="text-sm font-semibold text-slate-800 border-b border-slate-200 pb-2">Data Pribadi</h4>
                        
                        <div>
                            <label className="block text-xs font-medium text-slate-700 mb-1">Nama Pasien *</label>
                            <input 
                                type="text" 
                                name="nama_pasien" 
                                value={formData.nama_pasien} 
                                onChange={handleChange} 
                                required 
                                pattern="^[a-zA-Z\s']*[a-zA-Z][a-zA-Z\s']*$"
                                title="Nama pasien hanya boleh berisi huruf dan spasi."
                                className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" 
                            />
                        </div>
                        
                        <div>
                            <label className="block text-xs font-medium text-slate-700 mb-1">No. Kartu BPJS *</label>
                            <input 
                                type="text" 
                                name="no_kartu_bpjs" 
                                value={formData.no_kartu_bpjs} 
                                onChange={handleChange} 
                                required 
                                pattern="\d{13}"
                                minLength={13}
                                maxLength={13}
                                inputMode="numeric"
                                title="No. Kartu BPJS harus terdiri dari tepat 13 digit angka."
                                className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" 
                            />
                            {formData.no_kartu_bpjs.length > 0 && formData.no_kartu_bpjs.length < 13 && (
                                <p className="text-xs text-rose-500 mt-1">No. Kartu BPJS harus terdiri dari tepat 13 digit angka.</p>
                            )}
                        </div>
                        
                        <div>
                            <label className="block text-xs font-medium text-slate-700 mb-1">No. WhatsApp</label>
                            <input 
                                type="text" 
                                name="no_whatsapp" 
                                value={formData.no_whatsapp} 
                                onChange={handleChange} 
                                placeholder="08..." 
                                pattern="^$|\d{10,13}"
                                minLength={10}
                                maxLength={13}
                                inputMode="numeric"
                                title="No. WhatsApp harus terdiri dari 10–13 digit angka."
                                className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" 
                            />
                            {formData.no_whatsapp.length > 0 && formData.no_whatsapp.length < 10 && (
                                <p className="text-xs text-rose-500 mt-1">No. WhatsApp harus terdiri dari 10–13 digit angka.</p>
                            )}
                        </div>
                    </div>

                    {/* Data Medis */}
                    <div className="space-y-4">
                        <h4 className="text-sm font-semibold text-slate-800 border-b border-slate-200 pb-2">Data Program PRB</h4>
                        
                        <div>
                            <label className="block text-xs font-medium text-slate-700 mb-1">Diagnosis PRB *</label>
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
                            <label className="block text-xs font-medium text-slate-700 mb-1">FKTP Asal (Perujuk) *</label>
                            <input 
                                type="text" 
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
                                className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" 
                            />
                        </div>
                        
                        <div className="grid grid-cols-1 gap-3">
                            <div>
                                <label className="block text-xs font-medium text-slate-700 mb-1">Tgl Mulai PRB *</label>
                                <input 
                                    type="date" 
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
                                    className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" 
                                />
                                {!formData.tanggal_pengambilan_terakhir && formData.tanggal_mulai_prb && (
                                    <p className="text-[10px] text-teal-600 mt-1">PRB Baru — Jadwal pertama: Tgl Mulai + 23 hari.</p>
                                )}
                            </div>
                        </div>

                        <div>
                            <label className="block text-xs font-medium text-slate-700 mb-1">Tgl Pengambilan Terakhir (Jika ada)</label>
                            <input 
                                type="date" 
                                name="tanggal_pengambilan_terakhir" 
                                value={formData.tanggal_pengambilan_terakhir} 
                                onChange={handleChange} 
                                max={new Date().toISOString().split('T')[0]} 
                                onInvalid={(e) => {
                                    const target = e.target as HTMLInputElement;
                                    if (target.validity.rangeOverflow) {
                                        target.setCustomValidity('Tgl Pengambilan Terakhir tidak boleh lebih dari hari ini.');
                                    }
                                }}
                                onInput={(e) => (e.target as HTMLInputElement).setCustomValidity('')}
                                className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" 
                            />
                            {formData.tanggal_pengambilan_terakhir && (
                                <p className="text-[10px] text-teal-600 mt-1">PRB Lama — Jadwal berikutnya: Pengambilan Terakhir + 30 hari.</p>
                            )}
                            {!formData.tanggal_pengambilan_terakhir && (
                                <p className="text-[10px] text-slate-500 mt-1">Sistem akan otomatis menghitung jadwal siklus berikutnya.</p>
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
