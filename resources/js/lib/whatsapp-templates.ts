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

export function getWhatsAppTemplate(ctx: WhatsAppContext): string {
    const { pasien, tanggalEvaluasi } = ctx;
    const nama = pasien.nama_pasien;
    const jadwal = pasien.tanggal_jadwal_berikutnya;
    const fktp = pasien.fktp_asal;

    if (!jadwal) {
        return `Yth. Bpk/Ibu ${nama},\n\nKami dari apotek mitra BPJS Kesehatan ingin menginformasikan mengenai program Rujuk Balik (PRB) Anda.\n\nSilakan menghubungi kami untuk informasi jadwal pengambilan obat.\n\nTerima kasih. 🙏`;
    }

    const evaluasi = tanggalEvaluasi || new Date().toISOString().split('T')[0];
    const selisih = hitungSelisihHari(jadwal, evaluasi);
    const jadwalFormatted = formatTanggal(jadwal);

    // H-3 atau sebelumnya (belum jatuh tempo)
    if (selisih < 0) {
        return `Yth. Bpk/Ibu ${nama},\n\nKami mengingatkan bahwa jadwal pengambilan obat PRB Anda adalah *${jadwalFormatted}*.\n\nSilakan datang ke apotek kami paling cepat 3 hari sebelum jadwal untuk mengambil obat rutin Anda.\n\nJangan lupa membawa:\n📋 Kartu BPJS\n📋 Surat Rujuk Balik (SRB)\n📋 Resep dari dokter/FKTP\n\nTerima kasih atas kepatuhan Anda. 🙏\n\n_Pesan otomatis dari SMART PRB_`;
    }

    // Hari H (jatuh tempo hari ini)
    if (selisih === 0) {
        return `Yth. Bpk/Ibu ${nama},\n\n*HARI INI (${jadwalFormatted})* adalah jadwal pengambilan obat PRB Anda.\n\nSilakan segera datang ke apotek mitra kami untuk mengambil obat rutin Anda.\n\nJangan lupa membawa:\n📋 Kartu BPJS\n📋 Surat Rujuk Balik (SRB)\n\nTerima kasih. 🙏\n\n_Pesan otomatis dari SMART PRB_`;
    }

    // H+1 s.d. H+7 (dalam toleransi)
    if (selisih >= 1 && selisih <= 7) {
        return `Yth. Bpk/Ibu ${nama},\n\nJadwal pengambilan obat PRB Anda sudah lewat *${selisih} hari* (jadwal: ${jadwalFormatted}).\n\n⚠️ Anda masih dalam *batas toleransi* (maks. 7 hari). Mohon segera datang ke apotek kami untuk mengambil obat.\n\nKepatuhan minum obat sangat penting untuk pengendalian kondisi kesehatan Anda.\n\nTerima kasih. 🙏\n\n_Pesan otomatis dari SMART PRB_`;
    }

    // Lewat H+7 (overdue / tidak patuh)
    return `Yth. Bpk/Ibu ${nama},\n\n🔴 Pengambilan obat PRB Anda sudah *terlambat ${selisih} hari* dari jadwal (${jadwalFormatted}).\n\nKami sangat mengimbau Anda untuk:\n1️⃣ Segera datang ke apotek untuk mengambil obat\n2️⃣ Atau menghubungi *${fktp}* untuk konsultasi lebih lanjut\n\nKepatuhan pengobatan kronis sangat penting untuk mencegah komplikasi.\n\nMohon hubungi kami jika ada kendala. 🙏\n\n_Pesan otomatis dari SMART PRB_`;
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
