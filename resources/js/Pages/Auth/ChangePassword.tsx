import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff, KeyRound } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';

type Field = 'password_lama' | 'password' | 'password_confirmation';

const FIELDS: { name: Field; label: string; autoComplete: string; hint?: string }[] = [
    { name: 'password_lama',         label: 'Password Lama',            autoComplete: 'current-password' },
    { name: 'password',              label: 'Password Baru',            autoComplete: 'new-password', hint: 'Minimal 8 karakter, berisi huruf dan angka.' },
    { name: 'password_confirmation', label: 'Konfirmasi Password Baru', autoComplete: 'new-password' },
];

export default function ChangePassword() {
    const [showPassword, setShowPassword] = useState(false);

    const { data, setData, put, processing, errors, reset } = useForm<Record<Field, string>>({
        password_lama: '',
        password: '',
        password_confirmation: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put('/akun/password', {
            preserveScroll: true,
            onFinish: () => reset(),
        });
    };

    return (
        <AppLayout title="Ganti Password">
            <Head title="Ganti Password" />

            <div className="max-w-md">
                <div className="flex items-center gap-3 mb-6">
                    <div className="w-10 h-10 rounded-xl bg-teal-600 flex items-center justify-center">
                        <KeyRound className="w-5 h-5 text-white" />
                    </div>
                    <div>
                        <h1 className="text-xl font-bold text-slate-800">Ganti Password</h1>
                        <p className="text-sm text-slate-500">Perangkat lain yang sedang login akan dikeluarkan.</p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-5">
                    {FIELDS.map(f => (
                        <div key={f.name}>
                            <label className="block text-sm font-medium text-slate-700 mb-1.5">{f.label}</label>
                            <input
                                type={showPassword ? 'text' : 'password'}
                                value={data[f.name]}
                                onChange={e => setData(f.name, e.target.value)}
                                autoComplete={f.autoComplete}
                                className={`w-full rounded-lg border px-3.5 py-2.5 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500 transition ${
                                    errors[f.name] ? 'border-red-400 bg-red-50' : 'border-slate-300'
                                }`}
                            />
                            {errors[f.name]
                                ? <p className="text-xs text-red-600 mt-1.5">{errors[f.name]}</p>
                                : f.hint && <p className="text-xs text-slate-400 mt-1.5">{f.hint}</p>}
                        </div>
                    ))}

                    <button
                        type="button"
                        onClick={() => setShowPassword(!showPassword)}
                        className="flex items-center gap-1.5 text-xs text-slate-500 hover:text-slate-700"
                    >
                        {showPassword ? <EyeOff className="w-3.5 h-3.5" /> : <Eye className="w-3.5 h-3.5" />}
                        {showPassword ? 'Sembunyikan password' : 'Tampilkan password'}
                    </button>

                    <div className="flex justify-end">
                        <button
                            type="submit"
                            disabled={processing}
                            className="flex items-center gap-2 bg-teal-600 hover:bg-teal-700 disabled:opacity-60 disabled:cursor-not-allowed text-white font-semibold px-4 py-2.5 rounded-lg transition text-sm"
                        >
                            {processing ? 'Menyimpan...' : 'Simpan Password'}
                        </button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
