/**
 * 8 FKTP penelitian dengan nama lengkap (notulensi meeting 15 November 2026,
 * Tabel 3.6) — sama dengan PasienPrb::FKTP_OPTIONS. FKTP lain di luar penelitian.
 */
export const FKTP_OPTIONS = [
    'Puskesmas Babakan Sari',
    'Puskesmas Babakan Surabaya',
    'Puskesmas Griya Antapani',
    'Puskesmas Ibrahim Adjie',
    'Puskesmas Padasuka',
    'Klinik Pratama Asri Husada I',
    'Klinik Pratama Medika Antapani',
    'Klinik Pratama Seno Medika',
] as const;

/** Nama inti / singkatan di data apotek → nama lengkap (sama dengan PasienPrb::ALIAS_FKTP). */
const ALIAS_FKTP: Record<string, string> = {
    'BABAKAN SARI': 'Puskesmas Babakan Sari', 'BBKN SARI': 'Puskesmas Babakan Sari',
    'B SARI': 'Puskesmas Babakan Sari', 'BB SARI': 'Puskesmas Babakan Sari',
    'BABAKAN SURABAYA': 'Puskesmas Babakan Surabaya', 'B SBY': 'Puskesmas Babakan Surabaya',
    'B SURABAYA': 'Puskesmas Babakan Surabaya', 'BBKN SBY': 'Puskesmas Babakan Surabaya',
    'BBKN SURABAYA': 'Puskesmas Babakan Surabaya', 'BABAKAN SBY': 'Puskesmas Babakan Surabaya',
    'GRIYA ANTAPANI': 'Puskesmas Griya Antapani', GRIYA: 'Puskesmas Griya Antapani',
    'IBRAHIM ADJIE': 'Puskesmas Ibrahim Adjie', 'IBRAHIM ADJI': 'Puskesmas Ibrahim Adjie',
    IBRA: 'Puskesmas Ibrahim Adjie',
    PADASUKA: 'Puskesmas Padasuka',
    'ASRI HUSADA I': 'Klinik Pratama Asri Husada I', 'ASRI HUSADA 1': 'Klinik Pratama Asri Husada I',
    'ASRI HUSADA': 'Klinik Pratama Asri Husada I',
    'MEDIKA ANTAPANI': 'Klinik Pratama Medika Antapani', KPMA: 'Klinik Pratama Medika Antapani',
    'SENO MEDIKA': 'Klinik Pratama Seno Medika', SENO: 'Klinik Pratama Seno Medika',
};

/**
 * "KPMA", "B. SBY", "Puskesmas Ibrahim Adjie" → nama lengkap FKTP;
 * null bila di luar 8 FKTP penelitian.
 */
export function bakukanFktp(raw: unknown): string | null {
    const kunci = String(raw ?? '')
        .replace(/\./g, ' ').replace(/\s+/g, ' ').trim().toUpperCase()
        .replace(/^(UPT\s+)?(PUSKESMAS|PKM|KLINIK PRATAMA|KLINIK|KP)\s+/, '').trim();
    return ALIAS_FKTP[kunci] ?? null;
}
