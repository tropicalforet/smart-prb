/**
 * Samarkan nama pasien: tiap kata hanya huruf pertama & terakhir yang terlihat.
 * "Rizka Dwi Putri" → "R***a D*i P***i". Kata 2 huruf → "A*".
 *
 * Dipanggil di browser SEBELUM data dikirim (import & form), sehingga nama
 * asli tidak pernah sampai ke server. Server menyamarkan ulang dengan aturan
 * yang sama (PasienPrb::samarkanNama) — aman diulang.
 */
export function samarkanNama(nama: unknown): string {
    const bersih = String(nama ?? '').replace(/\s+/g, ' ').trim();
    if (!bersih) return '';

    return bersih
        .split(' ')
        .map((kata) => {
            const huruf = Array.from(kata);
            if (huruf.length <= 1) return kata;
            if (huruf.length === 2) return `${huruf[0]}*`;
            return huruf[0] + '*'.repeat(huruf.length - 2) + huruf[huruf.length - 1];
        })
        .join(' ')
        .slice(0, 150);
}
