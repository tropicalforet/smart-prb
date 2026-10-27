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
    protected $description = 'Handle patient NOKA duplicates (list, tag to allow unique index, or merge).';

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
        $duplicateGroups = DB::table('pasien_prb')
            ->select('noka', DB::raw('COUNT(*) as cnt'))
            ->groupBy('noka')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        if ($duplicateGroups->isEmpty()) {
            $this->info("No duplicate NOKA found.");
            return;
        }

        $this->info("Found " . $duplicateGroups->count() . " duplicate NOKA groups:\n");

        foreach ($duplicateGroups as $dup) {
            $this->warn("NOKA: {$dup->noka} (Total: {$dup->cnt})");
            $patients = PasienPrb::withCount('riwayatPengambilanObat')
                ->where('noka', $dup->noka)
                ->orderBy('created_at', 'asc')
                ->get();
            
            foreach ($patients as $index => $pasien) {
                $mark = $index === 0 ? "[OLDEST/KEEP]" : "[DUPLICATE]";
                $this->line("  - {$mark} ID: {$pasien->id} | Kode: {$pasien->kode_pasien} | Riwayat: {$pasien->riwayat_pengambilan_obat_count}");
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
        
        $duplicateGroups = DB::table('pasien_prb')
            ->select('noka', DB::raw('COUNT(*) as cnt'))
            ->groupBy('noka')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        $taggedCount = 0;

        foreach ($duplicateGroups as $dup) {
            $patients = PasienPrb::where('noka', $dup->noka)
                ->orderBy('created_at', 'asc')
                ->get();

            // leave the oldest alone
            $keep = $patients->shift();

            $counter = 1;
            foreach ($patients as $pasien) {
                $newNoka = $pasien->noka . '-DUP' . $counter;
                
                // bypass validation in model by doing raw update or just save
                $pasien->noka = $newNoka;
                $pasien->save();
                
                $this->line("Tagged ID {$pasien->id} to NOKA: {$newNoka}");
                $counter++;
                $taggedCount++;
            }
        }

        $this->info("Tagged {$taggedCount} duplicate patients.");
        $this->info("You can now safely add a UNIQUE database index to `noka`.");
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

        $this->info("Merging {$deletePasien->identitas()} INTO {$keepPasien->identitas()}...");

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
