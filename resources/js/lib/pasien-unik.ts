/**
 * Jumlah pasien unik (orang) berdasarkan NOKA — sama dengan
 * PasienPrb::jumlahPasienUnik() di server. Satu pasien dengan beberapa
 * diagnosis punya beberapa data PRB, tetapi dihitung satu kali. Data tanpa
 * NOKA tidak bisa dipastikan sama/berbeda, jadi dihitung per data.
 */
export function jumlahPasienUnik(daftarNoka: (string | number | null | undefined)[]): number {
    const unik = new Set<string>();
    let tanpaNoka = 0;

    for (const noka of daftarNoka) {
        const kunci = String(noka ?? '').replace(/\s+/g, '').toUpperCase();
        if (kunci === '') tanpaNoka++;
        else unik.add(kunci);
    }

    return unik.size + tanpaNoka;
}
