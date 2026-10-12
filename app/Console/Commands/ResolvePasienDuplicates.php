<?php

namespace App\Console\Commands;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResolvePasienDuplicates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pasien:resolve-duplicates 
                            {--action=list : Action to perform: list, tag, merge}
                            {--keep= : ID of the patient to keep (for merge action)}
                            {--delete= : ID of the patient to delete and merge from (for merge action)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Handle patient BPJS duplicates (list, tag to allow unique index, or merge).';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->option('action');

        if ($action === 'list') {
            $this->listDuplicates();
        } elseif ($action === 'tag') {
            $this->tagDuplicates();
        } elseif ($action === 'merge') {
            $this->mergePatients();
        } else {
            $this->error("Invalid action. Available actions: list, tag, merge");
        }
    }

    private function listDuplicates()
    {
        $duplicates = DB::select("
            SELECT no_kartu_bpjs, COUNT(*) as cnt, GROUP_CONCAT(id ORDER BY created_at ASC) as ids
            FROM pasien_prb
            GROUP BY no_kartu_bpjs
            HAVING COUNT(*) > 1
        ");

        if (empty($duplicates)) {
            $this->info("No duplicate BPJS numbers found.");
            return;
        }

        $this->info("Found " . count($duplicates) . " duplicate BPJS groups:\n");

        foreach ($duplicates as $dup) {
            $this->warn("BPJS: {$dup->no_kartu_bpjs} (Total: {$dup->cnt})");
            $ids = explode(',', $dup->ids);
            
            foreach ($ids as $index => $id) {
                $pasien = PasienPrb::withCount('riwayatPengambilanObat')->find($id);
                if ($pasien) {
                    $mark = $index === 0 ? "[OLDEST/KEEP]" : "[DUPLICATE]";
                    $this->line("  - {$mark} ID: {$pasien->id} | Kode: {$pasien->kode_pasien} | Nama: {$pasien->nama_pasien} | Riwayat: {$pasien->riwayat_pengambilan_obat_count}");
                }
            }
            $this->line("");
        }

        $this->info("To tag duplicates so a UNIQUE index can be added, run:");
        $this->info("  php artisan pasien:resolve-duplicates --action=tag\n");
        $this->info("To merge a duplicate into the correct one, run:");
        $this->info("  php artisan pasien:resolve-duplicates --action=merge --keep=<ID_TO_KEEP> --delete=<ID_TO_DELETE>");
    }

    private function tagDuplicates()
    {
        $this->info("Tagging duplicates to resolve UNIQUE constraint violations...");
        
        $duplicates = DB::select("
            SELECT no_kartu_bpjs, COUNT(*) as cnt, GROUP_CONCAT(id ORDER BY created_at ASC) as ids
            FROM pasien_prb
            GROUP BY no_kartu_bpjs
            HAVING COUNT(*) > 1
        ");

        $taggedCount = 0;

        foreach ($duplicates as $dup) {
            $ids = explode(',', $dup->ids);
            $keepId = array_shift($ids); // leave the oldest alone

            $counter = 1;
            foreach ($ids as $dupId) {
                $pasien = PasienPrb::find($dupId);
                if ($pasien) {
                    $newBpjs = $pasien->no_kartu_bpjs . '-DUP' . $counter;
                    
                    // bypass validation in model by doing raw update or just save
                    $pasien->no_kartu_bpjs = $newBpjs;
                    $pasien->save();
                    
                    $this->line("Tagged ID {$pasien->id} ({$pasien->nama_pasien}) to BPJS: {$newBpjs}");
                    $counter++;
                    $taggedCount++;
                }
            }
        }

        $this->info("Tagged {$taggedCount} duplicate patients.");
        $this->info("You can now safely add a UNIQUE database index to `no_kartu_bpjs`.");
    }

    private function mergePatients()
    {
        $keepId = $this->option('keep');
        $deleteId = $this->option('delete');

        if (!$keepId || !$deleteId) {
            $this->error("You must provide both --keep and --delete options for merge.");
            return;
        }

        $keepPasien = PasienPrb::find($keepId);
        $deletePasien = PasienPrb::find($deleteId);

        if (!$keepPasien || !$deletePasien) {
            $this->error("One or both patients not found.");
            return;
        }

        $this->info("Merging {$deletePasien->nama_pasien} ({$deletePasien->kode_pasien}) INTO {$keepPasien->nama_pasien} ({$keepPasien->kode_pasien})...");

        DB::beginTransaction();
        try {
            // 1. Move all riwayat to keepPasien
            $riwayatCount = RiwayatPengambilanObat::where('pasien_prb_id', $deletePasien->id)
                ->update(['pasien_prb_id' => $keepPasien->id]);
            $this->line("Moved {$riwayatCount} riwayat entries.");

            // 2. We don't need to recalculate keepPasien here because they should run `pasien:recalculate-logic` after.
            // 3. Delete the duplicate patient
            $deletePasien->delete();
            $this->line("Deleted duplicate patient {$deleteId}.");

            DB::commit();
            $this->info("Merge successful. Please run `php artisan pasien:recalculate-logic` to update schedules.");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Merge failed: " . $e->getMessage());
        }
    }
}
