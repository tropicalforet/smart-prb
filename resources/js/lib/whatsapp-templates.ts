import { PasienPRB } from '@/types';

interface WhatsAppContext {
    pasien: PasienPRB;
    tanggalEvaluasi?: string;
}

function formatTanggal(dateStr: string): string {
    const date = new Date(dateStr);
    return date.toLocaleDateString('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

function hitungSelisihHari(jadwal: string, evaluasi: string): number {
    const d1 = new Date(jadwal);
    const d2 = new Date(evaluasi);
    const diff = Math.floor((d2.getTime() - d1.getTime()) / (1000 * 60 * 60 * 24));
    return diff;
}

// ── Template sederhana untuk reminder (Halaman Reminder) ───────────────────
// Sesuai arahan Bu Lente: bahasa sederhana, ramah lansia, tidak ada istilah teknis.
// nama dan jadwal diisi otomatis dari data pasien.
export function getReminderTemplate(namaPasien: string, jadwal: string | null): string {
    const BULAN = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    let jadwalFmt = '-';
    if (jadwal) {
        const d = new Date(jadwal);
        if (!isNaN(d.getTime())) {
            jadwalFmt = `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}/${d.getFullYear()}`;
        }
    }

    return `Halo Bapak/Ibu ${namaPasien}.

Kami mengingatkan bahwa jadwal pengambilan obat Bapak/Ibu adalah ${jadwalFmt}.

Mohon datang ke apotek untuk mengambil obat sesuai jadwal.

Jangan lupa membawa Kartu BPJS dan Surat Rujuk Balik.

Terima kasih.`;
}

export function getWhatsAppTemplate(ctx: WhatsAppContext): string {
    const { pasien, tanggalEvaluasi } = ctx;
    const nama = pasien.nama_pasien;
    const jadwal = pasien.tanggal_jadwal_berikutnya;
    const fktp = pasien.fktp_asal;

    if (!jadwal) {
        return `Halo Bapak/Ibu ${nama}.\n\nKami dari apotek ingin menginformasikan mengenai jadwal pengambilan obat program PRB Anda.\n\nSilakan menghubungi kami untuk informasi lebih lanjut.\n\nTerima kasih.`;
    }

    const evaluasi = tanggalEvaluasi || new Date().toISOString().split('T')[0];
    const selisih = hitungSelisihHari(jadwal, evaluasi);
    const jadwalFormatted = formatTanggal(jadwal);

    // H-3 atau sebelumnya (belum jatuh tempo)
    if (selisih < 0) {
        return `Halo Bapak/Ibu ${nama}.\n\nKami mengingatkan bahwa jadwal pengambilan obat Bapak/Ibu adalah *${jadwalFormatted}*.\n\nMohon datang ke apotek untuk mengambil obat sesuai jadwal.\n\nJangan lupa membawa:\n📋 Kartu BPJS\n📋 Surat Rujuk Balik (SRB)\n\nTerima kasih. 🙏`;
    }

    // Hari H (jatuh tempo hari ini)
    if (selisih === 0) {
        return `Halo Bapak/Ibu ${nama}.\n\n*Hari ini (${jadwalFormatted})* adalah jadwal pengambilan obat Bapak/Ibu.\n\nMohon segera datang ke apotek untuk mengambil obat.\n\nJangan lupa membawa Kartu BPJS.\n\nTerima kasih. 🙏`;
    }

    // H+1 s.d. H+3 (dalam toleransi)
    if (selisih >= 1 && selisih <= 3) {
        return `Halo Bapak/Ibu ${nama}.\n\nJadwal pengambilan obat Bapak/Ibu sudah lewat ${selisih} hari (jadwal: ${jadwalFormatted}).\n\nMohon segera datang ke apotek untuk mengambil obat.\n\nTerima kasih. 🙏`;
    }

    // Lewat H+3
    return `Halo Bapak/Ibu ${nama}.\n\nKami ingin mengingatkan bahwa pengambilan obat Bapak/Ibu sudah terlambat dari jadwal (${jadwalFormatted}).\n\nMohon segera datang ke apotek atau hubungi ${fktp} untuk bantuan lebih lanjut.\n\nTerima kasih. 🙏`;
}

export function generateWhatsAppLink(noWa: string, message: string): string {
    // Bersihkan nomor WhatsApp
    let phone = noWa.replace(/[^0-9]/g, '');
    // Konversi 08xx ke 628xx
    if (phone.startsWith('0')) {
        phone = '62' + phone.substring(1);
    }
    // Pastikan ada prefix 62
    if (!phone.startsWith('62')) {
        phone = '62' + phone;
    }

    return `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;
}
