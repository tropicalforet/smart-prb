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
        diagnosis_prb: '',
        tanggal_mulai_prb: new Date().toISOString().split('T')[0],
        tanggal_pengambilan_terakhir: '',
        status: 'Aktif',
        alasan_pasif: '',
    });

    const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
        const { name, value } = e.target;
        setFormData(prev => ({ ...prev, [name]: value }));
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);

        router.post('/pasien', formData, {
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
                            <input type="text" name="nama_pasien" value={formData.nama_pasien} onChange={handleChange} required className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                        </div>
                        
                        <div>
                            <label className="block text-xs font-medium text-slate-700 mb-1">No. Kartu BPJS *</label>
                            <input type="text" name="no_kartu_bpjs" value={formData.no_kartu_bpjs} onChange={handleChange} required className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                        </div>
                        
                        <div>
                            <label className="block text-xs font-medium text-slate-700 mb-1">No. WhatsApp</label>
                            <input type="text" name="no_whatsapp" value={formData.no_whatsapp} onChange={handleChange} placeholder="08..." className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                        </div>
                    </div>

                    {/* Data Medis */}
                    <div className="space-y-4">
                        <h4 className="text-sm font-semibold text-slate-800 border-b border-slate-200 pb-2">Data Program PRB</h4>
                        
                        <div>
                            <label className="block text-xs font-medium text-slate-700 mb-1">Diagnosis PRB *</label>
                            <select name="diagnosis_prb" value={formData.diagnosis_prb} onChange={handleChange} required className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                <option value="">Pilih diagnosis...</option>
                                {DIAGNOSIS_OPTIONS.map(opt => <option key={opt} value={opt}>{opt}</option>)}
                            </select>
                        </div>
                        
                        <div>
                            <label className="block text-xs font-medium text-slate-700 mb-1">FKTP Asal (Perujuk) *</label>
                            <input type="text" name="fktp_asal" value={formData.fktp_asal} onChange={handleChange} required className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                        </div>
                        
                        <div className="grid grid-cols-1 gap-3">
                            <div>
                                <label className="block text-xs font-medium text-slate-700 mb-1">Tgl Mulai PRB *</label>
                                <input type="date" name="tanggal_mulai_prb" value={formData.tanggal_mulai_prb} onChange={handleChange} required className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                            </div>
                        </div>

                        <div>
                            <label className="block text-xs font-medium text-slate-700 mb-1">Tgl Pengambilan Terakhir (Jika ada)</label>
                            <input type="date" name="tanggal_pengambilan_terakhir" value={formData.tanggal_pengambilan_terakhir} onChange={handleChange} max={new Date().toISOString().split('T')[0]} className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                            <p className="text-[10px] text-slate-500 mt-1">Sistem akan otomatis menghitung jadwal siklus berikutnya.</p>
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
