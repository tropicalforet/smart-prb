<?php

namespace App\Exports;

use App\Models\PasienPrb;
use App\Services\KepatuhanService;
use Carbon\Carbon;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class RekapKepatuhanExport implements 
    FromCollection, 
    WithHeadings, 
    WithStyles,
    WithColumnWidths,
    WithTitle,
    ShouldAutoSize
{
    protected $filterPeriode;
    protected $filterFktp;
    protected $filterKepatuhan;
    protected $tanggalEvaluasi;
    protected $kepatuhanService;

    public function __construct(
        ?string $filterPeriode = null,
        ?string $filterFktp = null,
        ?string $filterKepatuhan = null,
        ?string $tanggalEvaluasi = null
    ) {
        $this->filterPeriode = $filterPeriode;
        $this->filterFktp = $filterFktp;
        $this->filterKepatuhan = $filterKepatuhan;
        $this->tanggalEvaluasi = $tanggalEvaluasi ?? KepatuhanService::tanggalEvaluasiBawaan();
        $this->kepatuhanService = app(KepatuhanService::class);
    }

    /**
     * Return collection data untuk Excel
     */
    public function collection(): Enumerable
    {
        // Sama dengan halaman Rekap: hanya pasien Aktif.
        $query = PasienPrb::aktif()->with([
            'riwayatPengambilanObat' => fn($q) =>
                $q->orderBy('tanggal_pengambilan', 'desc'),
            'riwayatTindakLanjut' => fn($q) =>
                $q->orderBy('created_at', 'desc')->limit(1),
        ]);

        // Filter FKTP
        if ($this->filterFktp) {
            $query->where('fktp_asal', $this->filterFktp);
        }

        // Filter Periode
        if ($this->filterPeriode) {
            $query->where(function ($q) {
                $q->whereHas('riwayatPengambilanObat', function ($qRiwayat) {
                    $qRiwayat->where('jadwal_target', 'like', $this->filterPeriode . '%');
                })->orWhere('tanggal_jadwal_berikutnya', 'like', $this->filterPeriode . '%');
            });
        }

        $allPasien = $query->get();

        // Map data dengan perhitungan kepatuhan
        $data = $allPasien->map(function ($pasien) {
            if ($this->filterPeriode) {
                $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhanPeriode(
                    $pasien, 
                    $this->filterPeriode, 
                    $this->tanggalEvaluasi
                );
            } else {
                $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhan(
                    $pasien, 
                    $this->tanggalEvaluasi
                );
            }

            // Ambil tindak lanjut terakhir
            $tindakLanjutTerakhir = $pasien->riwayatTindakLanjut->first();
            
            return [
                'kode_pasien' => $pasien->kode_pasien,
                'noka' => $pasien->noka,
                'fktp_asal' => $pasien->fktp_asal,
                'diagnosis_prb' => is_array($pasien->diagnosis_prb) 
                    ? implode(', ', $pasien->diagnosis_prb) 
                    : $pasien->diagnosis_prb,
                'jenis_prb' => $pasien->jenis_prb,
                'status_kepesertaan' => $pasien->status,
                'tanggal_pengambilan_terakhir' => $pasien->tanggal_pengambilan_terakhir 
                    ? Carbon::parse($pasien->tanggal_pengambilan_terakhir)->format('d/m/Y') 
                    : '-',
                'tanggal_jadwal_berikutnya' => !empty($kepatuhan['jadwal_target_aktif'])
                    ? Carbon::parse($kepatuhan['jadwal_target_aktif'])->format('d/m/Y')
                    : '-',
                'status_kepatuhan' => $kepatuhan['status'],
                'early_warning_status' => $kepatuhan['early_warning'] ?? '-',
                'keterangan_kepatuhan' => $kepatuhan['keterangan'] ?: '-',
                'tindak_lanjut_terakhir' => $tindakLanjutTerakhir 
                    ? $tindakLanjutTerakhir->tindakan . ' (' . 
                      Carbon::parse($tindakLanjutTerakhir->created_at)->format('d/m/Y') . ')'
                    : '-',
                // untuk menggabung baris; tidak ikut diekspor
                '_kelompok' => PasienPrb::kunciKelompok($pasien),
                '_urut' => PasienPrb::urutanDiagnosis($pasien),
            ];
        });

        // Sama dengan halaman Rekap: hanya pasien yang sudah dinilai
        $data = $data->whereIn('status_kepatuhan', KepatuhanService::STATUS_DINILAI);

        // Filter kepatuhan (jika ada)
        if ($this->filterKepatuhan) {
            $data = $data->filter(function ($row) {
                return $row['status_kepatuhan'] === $this->filterKepatuhan;
            });
        }

        // Sama dengan Data Pasien: diagnosis satu pasien dengan tanggal mulai &
        // ambil yang sama menjadi satu baris.
        return $data->groupBy('_kelompok')->map(function ($g) {
            $baris = $g->first();
            $baris['diagnosis_prb'] = $g->sortBy('_urut')->pluck('diagnosis_prb')->unique()->implode(', ');
            return collect($baris)->except(['_kelompok', '_urut'])->all();
        })->values();
    }

    /**
     * Header kolom Excel
     */
    public function headings(): array
    {
        return [
            'Kode Pasien',
            'NOKA',
            'FKTP Asal',
            'Diagnosis PRB',
            'Jenis PRB',
            'Status Kepesertaan',
            'Pengambilan Terakhir',
            'Jadwal Berikutnya',
            'Status Kepatuhan',
            'Early Warning',
            'Keterangan',
            'Tindak Lanjut Terakhir',
        ];
    }

    /**
     * Styling untuk Excel
     */
    public function styles(Worksheet $sheet): ?array
    {
        return [
            // Header row styling
            1 => [
                'font' => [
                    'bold' => true,
                    'size' => 11,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '0D9488'], // Teal-600
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Lebar kolom manual untuk kolom tertentu
     */
    public function columnWidths(): array
    {
        return [
            'A' => 15,  // Kode Pasien
            'B' => 22,  // NOKA
            'C' => 20,  // FKTP
            'D' => 30,  // Diagnosis
            'E' => 12,  // Jenis PRB
            'F' => 15,  // Status
            'G' => 18,  // Pengambilan Terakhir
            'H' => 18,  // Jadwal Berikutnya
            'I' => 18,  // Status Kepatuhan
            'J' => 18,  // Early Warning
            'K' => 30,  // Keterangan
            'L' => 30,  // Tindak Lanjut
        ];
    }

    /**
     * Nama sheet Excel
     */
    public function title(): string
    {
        return 'Rekap Kepatuhan';
    }
}
