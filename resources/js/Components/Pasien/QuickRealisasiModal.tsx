import { useState } from 'react';
import { PasienPRB } from '@/types';
import { Button } from '../ui/Button';
import { Modal } from '../ui/Modal';
import { router } from '@inertiajs/react';
import { CalendarCheck, Save } from 'lucide-react';

interface Props {
    pasien: PasienPRB;
    isOpen: boolean;
    onClose: () => void;
}

export function QuickRealisasiModal({ pasien, isOpen, onClose }: Props) {
    const [tanggal, setTanggal] = useState(new Date().toISOString().split('T')[0]);
    const [loading, setLoading] = useState(false);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);

        router.post(
            `/pasien/${pasien.id}/realisasi`,
            { tanggal_realisasi: tanggal },
            {
                onSuccess: () => {
                    setLoading(false);
                    onClose();
                },
                onError: () => {
                    setLoading(false);
                },
                preserveScroll: true,
            }
        );
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} title="Catat Realisasi Pengambilan" maxWidth="sm">
            <form onSubmit={handleSubmit} className="space-y-4">
                <div className="bg-slate-50 p-3 rounded-lg border border-slate-200 mb-4 text-sm">
                    <div className="font-medium text-slate-800">{pasien.nama_pasien}</div>
                    <div className="text-slate-500 text-xs mt-0.5">Jadwal Target: {pasien.jadwal_target_aktif ? new Date(pasien.jadwal_target_aktif).toLocaleDateString('id-ID') : (pasien.tanggal_jadwal_berikutnya ? new Date(pasien.tanggal_jadwal_berikutnya).toLocaleDateString('id-ID') : '-')}</div>
                </div>

                <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">
                        Tanggal Pengambilan Aktual
                    </label>
                    <div className="relative">
                        <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <CalendarCheck className="w-5 h-5 text-slate-400" />
                        </div>
                        <input
                            type="date"
                            value={tanggal}
                            onChange={(e) => setTanggal(e.target.value)}
                            required
                            max={new Date().toISOString().split('T')[0]} // Maksimal hari ini
                            className="block w-full pl-10 pr-3 py-2 border border-slate-300 rounded-lg focus:ring-teal-500 focus:border-teal-500 sm:text-sm"
                        />
                    </div>
                    <p className="text-xs text-slate-500 mt-1.5">
                        Menyimpan realisasi akan otomatis menghitung jadwal siklus berikutnya.
                    </p>
                </div>

                <div className="pt-4 flex gap-3 justify-end border-t border-slate-100">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" isLoading={loading} icon={<Save className="w-4 h-4" />}>
                        Simpan Realisasi
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
