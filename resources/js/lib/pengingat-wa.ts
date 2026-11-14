/**
 * Pengingat ambil obat lewat WhatsApp (Early Warning ke pasien).
 *
 * Sistem tidak mengirim pesan sendiri: tombol membuka WhatsApp dengan pesan
 * yang sudah terisi, lalu petugas menekan Kirim. Isi pesan mengikuti tahap
 * Early Warning — toleransi H-3 s/d H+3 sama dengan KepatuhanService.
 */

const TOLERANSI = 3;

const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
    'Agustus', 'September', 'Oktober', 'November', 'Desember'];

/** 'YYYY-MM-DD...' → Date lokal tengah malam (tanpa geser zona waktu). */
function tanggalLokal(iso: string): Date {
    const [y, m, d] = iso.slice(0, 10).split('-').map(Number);
    return new Date(y, m - 1, d);
}

function tambahHari(tgl: Date, hari: number): Date {
    const hasil = new Date(tgl);
    hasil.setDate(hasil.getDate() + hari);
    return hasil;
}

function formatTanggal(tgl: Date): string {
    return `${tgl.getDate()} ${BULAN[tgl.getMonth()]} ${tgl.getFullYear()}`;
}

export type TahapPengingat = 'mendatang' | 'masa_ambil' | 'hari_ini' | 'toleransi' | 'terlambat';

export const LABEL_TAHAP: Record<TahapPengingat, string> = {
    mendatang:  'Pengingat jadwal',
    masa_ambil: 'Sudah boleh diambil',
    hari_ini:   'Hari ini jadwalnya',
    toleransi:  'Belum mengambil (masih toleransi)',
    terlambat:  'Terlambat',
};

/** Tahap pengingat dari selisih hari ini terhadap jadwal (sama dengan batas EWS). */
export function tahapPengingat(jadwalIso: string, hariIni: Date = new Date()): TahapPengingat {
    const jadwal = tanggalLokal(jadwalIso);
    const hari = new Date(hariIni.getFullYear(), hariIni.getMonth(), hariIni.getDate());
    const selisih = Math.round((hari.getTime() - jadwal.getTime()) / 86_400_000);

    if (selisih < -TOLERANSI) return 'mendatang';
    if (selisih < 0) return 'masa_ambil';
    if (selisih === 0) return 'hari_ini';
    if (selisih <= TOLERANSI) return 'toleransi';
    return 'terlambat';
}

/**
 * Isi pesan pengingat sesuai tahap Early Warning. `nama` = nama pasien yang
 * tersimpan (sudah tersamar, mis. "R***a D*i P***i"); kosong → sapaan umum.
 */
export function pesanPengingat(jadwalIso: string, fktp: string, hariIni: Date = new Date(), nama?: string | null): string {
    const jadwal = tanggalLokal(jadwalIso);
    const awal   = formatTanggal(tambahHari(jadwal, -TOLERANSI));
    const akhir  = formatTanggal(tambahHari(jadwal, TOLERANSI));
    const tgl    = formatTanggal(jadwal);

    const isi: Record<TahapPengingat, string> = {
        mendatang:
            `Jadwal pengambilan obat PRB Bapak/Ibu adalah tanggal ${tgl}. ` +
            `Obat dapat diambil mulai ${awal} sampai ${akhir}.`,
        masa_ambil:
            `Obat PRB Bapak/Ibu sudah dapat diambil. Jadwalnya tanggal ${tgl}, ` +
            `dan masih dapat diambil sampai ${akhir}.`,
        hari_ini:
            `Hari ini (${tgl}) adalah jadwal pengambilan obat PRB Bapak/Ibu. ` +
            `Obat masih dapat diambil sampai ${akhir}.`,
        toleransi:
            `Bapak/Ibu belum mengambil obat PRB yang dijadwalkan tanggal ${tgl}. ` +
            `Mohon segera diambil, paling lambat ${akhir}.`,
        terlambat:
            `Bapak/Ibu belum mengambil obat PRB sejak jadwal tanggal ${tgl}. ` +
            `Mohon segera datang ke apotek atau menghubungi ${fktp || 'FKTP'} ` +
            `agar pengobatan tidak terputus.`,
    };

    return [
        nama?.trim() ? `Yth. Bapak/Ibu ${nama.trim()},` : 'Yth. Bapak/Ibu peserta PRB,',
        '',
        isi[tahapPengingat(jadwalIso, hariIni)],
        '',
        'Jangan lupa membawa kartu BPJS dan resep/buku kontrol PRB.',
        'Terima kasih.',
        '— Petugas Apotek PRB',
    ].join('\n');
}

/** Tautan wa.me yang membuka WhatsApp dengan pesan terisi. Nomor: 628xxxxxxxxxx. */
export function tautanWhatsapp(nomor: string, pesan: string): string {
    return `https://wa.me/${nomor.replace(/\D/g, '')}?text=${encodeURIComponent(pesan)}`;
}

/** Tampilan nomor: 6281234567890 → 0812-3456-7890. */
export function formatNoWhatsapp(nomor: string | null | undefined): string {
    if (!nomor) return '-';
    const lokal = nomor.startsWith('62') ? '0' + nomor.slice(2) : nomor;
    return lokal.replace(/(\d{4})(\d{4})(\d+)/, '$1-$2-$3');
}
