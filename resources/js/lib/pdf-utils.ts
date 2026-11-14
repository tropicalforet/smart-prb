import { jsPDF } from 'jspdf';
import { autoTable } from 'jspdf-autotable';

/**
 * =========================================================
 * EXPORT PDF
 * =========================================================
 *
 * Laporan tabel siap cetak (A4 landscape): judul, keterangan filter,
 * tabel data, tabel tambahan (opsional), dan nomor halaman. Data sama
 * dengan export Excel.
 */
interface TabelPdf {
    data: Record<string, any>[];
    /** Kolom yang ditampilkan (urut). Default: semua kunci baris pertama. */
    kolom?: string[];
    /** Kolom yang selnya diwarnai: Patuh hijau, Tidak Teratur merah. */
    kolomStatus?: string;
}

interface OpsiPdf extends Omit<TabelPdf, 'data'> {
    judul: string;
    /** Baris keterangan di bawah judul, mis. "Periode: Oktober 2026". */
    keterangan?: string[];
    /** Tabel lain setelah tabel utama, masing-masing mulai di halaman baru. */
    tabelTambahan?: (TabelPdf & { judul: string })[];
}

const WARNA_TEAL: [number, number, number] = [13, 148, 136];
const WARNA_STATUS: Record<string, [number, number, number]> = {
    'Patuh':         [5, 150, 105],
    'Tidak Teratur': [225, 29, 72],
};

function waktuCetak(): string {
    const d = new Date();
    const dua = (n: number) => String(n).padStart(2, '0');
    return `${dua(d.getDate())}/${dua(d.getMonth() + 1)}/${d.getFullYear()} ${dua(d.getHours())}:${dua(d.getMinutes())}`;
}

function gambarTabel(doc: jsPDF, tabel: TabelPdf, startY: number): void {
    const kolom = tabel.kolom ?? Object.keys(tabel.data[0] ?? {});

    autoTable(doc, {
        startY,
        head: [kolom],
        body: tabel.data.map((baris) => kolom.map((k) => String(baris[k] ?? '-'))),
        theme: 'grid',
        styles: { fontSize: 7, cellPadding: 1.5, overflow: 'linebreak', valign: 'middle' },
        headStyles: { fillColor: WARNA_TEAL, textColor: 255, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [248, 250, 252] },
        margin: { left: 14, right: 14, bottom: 14 },
        didParseCell: (cell) => {
            if (cell.section !== 'body' || !tabel.kolomStatus) return;
            if (kolom[cell.column.index] !== tabel.kolomStatus) return;
            const warna = WARNA_STATUS[String(cell.cell.raw)];
            if (warna) {
                cell.cell.styles.textColor = warna;
                cell.cell.styles.fontStyle = 'bold';
            }
        },
    });
}

export function exportToPdf(
    data: Record<string, any>[],
    filename: string,
    opsi: OpsiPdf
): void {
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    const lebar = doc.internal.pageSize.getWidth();
    const tinggi = doc.internal.pageSize.getHeight();

    // Judul & keterangan
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(14);
    doc.setTextColor(30, 41, 59);
    doc.text(opsi.judul, 14, 15);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(100, 116, 139);
    const keterangan = [...(opsi.keterangan ?? []), `Dicetak: ${waktuCetak()}`];
    keterangan.forEach((baris, i) => doc.text(baris, 14, 21 + i * 4.5));

    gambarTabel(doc, { data, kolom: opsi.kolom, kolomStatus: opsi.kolomStatus }, 21 + keterangan.length * 4.5 + 2);

    for (const tabel of opsi.tabelTambahan ?? []) {
        if (tabel.data.length === 0) continue;
        doc.addPage();
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(12);
        doc.setTextColor(30, 41, 59);
        doc.text(tabel.judul, 14, 15);
        doc.setFont('helvetica', 'normal');
        gambarTabel(doc, tabel, 20);
    }

    // Nomor halaman di setiap halaman
    const jumlahHalaman = doc.getNumberOfPages();
    for (let i = 1; i <= jumlahHalaman; i++) {
        doc.setPage(i);
        doc.setFontSize(8);
        doc.setTextColor(148, 163, 184);
        doc.text(`Halaman ${i} dari ${jumlahHalaman}`, lebar - 14, tinggi - 7, { align: 'right' });
        doc.text(opsi.judul, 14, tinggi - 7);
    }

    doc.save(filename);
}
