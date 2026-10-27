import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff, LogIn, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

export default function Login() {
    const [showPassword, setShowPassword] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/login');
    };

    return (
        <>
            <Head title="Login" />

            <div className="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-teal-900 flex items-center justify-center p-4">
                <div className="w-full max-w-md">

                    {/* Logo & Title */}
                    <div className="text-center mb-8">
                        <div className="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-teal-500/20 border border-teal-500/30 mb-4">
                            <ShieldCheck className="w-8 h-8 text-teal-400" />
                        </div>
                        <h1 className="text-2xl font-bold text-white">Dashboard PRB</h1>
                        <p className="text-slate-400 text-sm mt-1">Monitoring Kepatuhan Obat PRB</p>
                    </div>

                    {/* Card */}
                    <div className="bg-white rounded-2xl shadow-2xl p-8">
                        <h2 className="text-lg font-semibold text-slate-800 mb-6">Masuk ke sistem</h2>

                        <form onSubmit={handleSubmit} className="space-y-5">

                            {/* Email */}
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                                    Email
                                </label>
                                <input
                                    type="email"
                                    value={data.email}
                                    onChange={e => setData('email', e.target.value)}
                                    placeholder="admin@example.com"
                                    autoComplete="email"
                                    autoFocus
                                    className={`w-full rounded-lg border px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500 transition ${
                                        errors.email ? 'border-red-400 bg-red-50' : 'border-slate-300'
                                    }`}
                                />
                                {errors.email && (
                                    <p className="text-xs text-red-600 mt-1.5">{errors.email}</p>
                                )}
                            </div>

                            {/* Password */}
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                                    Password
                                </label>
                                <div className="relative">
                                    <input
                                        type={showPassword ? 'text' : 'password'}
                                        value={data.password}
                                        onChange={e => setData('password', e.target.value)}
                                        placeholder="••••••••"
                                        autoComplete="current-password"
                                        className={`w-full rounded-lg border px-3.5 py-2.5 pr-10 text-sm text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500 transition ${
                                            errors.password ? 'border-red-400 bg-red-50' : 'border-slate-300'
                                        }`}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                                        tabIndex={-1}
                                    >
                                        {showPassword
                                            ? <EyeOff className="w-4 h-4" />
                                            : <Eye className="w-4 h-4" />
                                        }
                                    </button>
                                </div>
                                {errors.password && (
                                    <p className="text-xs text-red-600 mt-1.5">{errors.password}</p>
                                )}
                            </div>

                            {/* Remember me */}
                            <div className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    id="remember"
                                    checked={data.remember}
                                    onChange={e => setData('remember', e.target.checked)}
                                    className="rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                />
                                <label htmlFor="remember" className="text-sm text-slate-600 cursor-pointer">
                                    Ingat saya
                                </label>
                            </div>

                            {/* Submit */}
                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full flex items-center justify-center gap-2 bg-teal-600 hover:bg-teal-700 disabled:opacity-60 disabled:cursor-not-allowed text-white font-semibold py-2.5 rounded-lg transition text-sm"
                            >
                                <LogIn className="w-4 h-4" />
                                {processing ? 'Memproses...' : 'Masuk'}
                            </button>

                        </form>
                    </div>

                    <p className="text-center text-xs text-slate-500 mt-6">
                        Hanya admin yang berwenang dapat mengakses sistem ini.
                    </p>
                </div>
            </div>
        </>
    );
}
