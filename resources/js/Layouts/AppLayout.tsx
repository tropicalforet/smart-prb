import { Link, usePage } from '@inertiajs/react';
import { ReactNode, useState, useEffect } from 'react';
import {
    LayoutDashboard,
    Users,
    Menu,
    X,
    Activity,
    ChevronRight,
} from 'lucide-react';
import { motion, AnimatePresence } from 'motion/react';

interface AppLayoutProps {
    children: ReactNode;
    title?: string;
}

const navItems = [
    { name: 'Dashboard', href: '/', icon: LayoutDashboard },
    { name: 'Data Pasien', href: '/pasien', icon: Users },
];

export default function AppLayout({ children, title }: AppLayoutProps) {
    const { url } = usePage();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [flashMessage, setFlashMessage] = useState<string | null>(null);
    const flash = (usePage().props as any).flash;

    useEffect(() => {
        if (flash?.success) {
            setFlashMessage(flash.success);
            const timer = setTimeout(() => setFlashMessage(null), 4000);
            return () => clearTimeout(timer);
        }
    }, [flash]);

    const isActive = (href: string) => {
        if (href === '/') return url === '/';
        return url.startsWith(href);
    };

    return (
        <div className="min-h-screen flex bg-slate-50">
            {/* Mobile Overlay */}
            <AnimatePresence>
                {sidebarOpen && (
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        className="fixed inset-0 bg-black/50 z-40 lg:hidden"
                        onClick={() => setSidebarOpen(false)}
                    />
                )}
            </AnimatePresence>

            {/* Sidebar */}
            <aside
                className={`fixed inset-y-0 left-0 z-50 w-64 bg-[#0f172a] transform transition-transform duration-300 ease-in-out lg:translate-x-0 lg:static lg:z-auto ${
                    sidebarOpen ? 'translate-x-0' : '-translate-x-full'
                }`}
            >
                <div className="flex flex-col h-full">
                    {/* Logo */}
                    <div className="flex items-center gap-3 px-5 py-5 border-b border-slate-700/50">
                        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-teal-400 to-emerald-500 flex items-center justify-center shadow-lg shadow-teal-500/20">
                            <Activity className="w-5 h-5 text-white" strokeWidth={2.5} />
                        </div>
                        <div>
                            <h1 className="text-white font-bold text-lg tracking-tight leading-none">SMART PRB</h1>
                            <p className="text-slate-400 text-[10px] mt-0.5 tracking-wide uppercase">Monitoring Kepatuhan Obat</p>
                        </div>
                        <button
                            onClick={() => setSidebarOpen(false)}
                            className="ml-auto lg:hidden text-slate-400 hover:text-white"
                        >
                            <X className="w-5 h-5" />
                        </button>
                    </div>

                    {/* Navigation */}
                    <nav className="flex-1 px-3 py-4 space-y-1">
                        {navItems.map((item) => {
                            const active = isActive(item.href);
                            return (
                                <Link
                                    key={item.name}
                                    href={item.href}
                                    className={`group flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 ${
                                        active
                                            ? 'bg-teal-600/20 text-teal-300 shadow-sm'
                                            : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'
                                    }`}
                                >
                                    <item.icon
                                        className={`w-5 h-5 flex-shrink-0 ${
                                            active ? 'text-teal-400' : 'text-slate-500 group-hover:text-slate-300'
                                        }`}
                                    />
                                    {item.name}
                                    {active && (
                                        <ChevronRight className="w-4 h-4 ml-auto text-teal-400" />
                                    )}
                                </Link>
                            );
                        })}
                    </nav>

                    {/* Footer */}
                    <div className="px-4 py-4 border-t border-slate-700/50">
                        <div className="bg-slate-800/50 rounded-lg p-3">
                            <p className="text-[10px] text-slate-500 uppercase tracking-wider font-semibold">Program Rujuk Balik</p>
                            <p className="text-xs text-slate-400 mt-1">BPJS Kesehatan</p>
                        </div>
                    </div>
                </div>
            </aside>

            {/* Main Content */}
            <div className="flex-1 flex flex-col min-w-0">
                {/* Top Bar */}
                <header className="sticky top-0 z-30 bg-white/80 backdrop-blur-xl border-b border-slate-200/80">
                    <div className="flex items-center justify-between px-4 lg:px-6 py-3">
                        <div className="flex items-center gap-3">
                            <button
                                onClick={() => setSidebarOpen(true)}
                                className="lg:hidden p-2 rounded-lg hover:bg-slate-100 text-slate-500"
                            >
                                <Menu className="w-5 h-5" />
                            </button>
                            {title && (
                                <h2 className="text-lg font-semibold text-slate-800">{title}</h2>
                            )}
                        </div>
                        <div className="flex items-center gap-2 text-xs text-slate-500">
                            <div className="hidden sm:flex items-center gap-1.5 bg-slate-100 rounded-full px-3 py-1.5">
                                <div className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
                                <span>Sistem Aktif</span>
                            </div>
                        </div>
                    </div>
                </header>

                {/* Flash Message */}
                <AnimatePresence>
                    {flashMessage && (
                        <motion.div
                            initial={{ opacity: 0, y: -20 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: -20 }}
                            className="mx-4 lg:mx-6 mt-4"
                        >
                            <div className="bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-3 flex items-center gap-2">
                                <div className="w-2 h-2 rounded-full bg-emerald-500" />
                                <p className="text-sm text-emerald-700">{flashMessage}</p>
                                <button
                                    onClick={() => setFlashMessage(null)}
                                    className="ml-auto text-emerald-500 hover:text-emerald-700"
                                >
                                    <X className="w-4 h-4" />
                                </button>
                            </div>
                        </motion.div>
                    )}
                </AnimatePresence>

                {/* Page Content */}
                <main className="flex-1 p-4 lg:p-6">
                    {children}
                </main>
            </div>
        </div>
    );
}
