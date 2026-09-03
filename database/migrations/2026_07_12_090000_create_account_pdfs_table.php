<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moves the Base64 applicant PDFs out of the wide accounts.pdfs JSON column into a dedicated
     * account_pdfs table. The base64 payload is large and was loaded on every `SELECT *` against
     * accounts (list pages, config export), so splitting it out keeps the accounts row lean and
     * page/query loads fast. The payload is fetched only when explicitly needed (edit modal, bot).
     */
    public function up(): void
    {
        Schema::create('account_pdfs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->longText('base64');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index('account_id');
        });

        // Backfill existing PDFs from the JSON column into the child table.
        DB::table('accounts')
            ->select('id', 'pdfs')
            ->whereNotNull('pdfs')
            ->orderBy('id')
            ->chunk(100, function ($accounts): void {
                $rows = [];
                $now = now();
                foreach ($accounts as $account) {
                    $pdfs = json_decode($account->pdfs, true);
                    if (! is_array($pdfs)) {
                        continue;
                    }
                    foreach ($pdfs as $pdf) {
                        if (! isset($pdf['base64']) || $pdf['base64'] === '') {
                            continue;
                        }
                        $rows[] = [
                            'account_id' => $account->id,
                            'name' => $pdf['name'] ?? null,
                            'base64' => $pdf['base64'],
                            'is_primary' => filter_var($pdf['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                if ($rows !== []) {
                    DB::table('account_pdfs')->insert($rows);
                }
            });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('pdfs');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->json('pdfs')->nullable()->after('appointment_dates');
        });

        DB::table('account_pdfs')
            ->orderBy('account_id')
            ->orderBy('id')
            ->get()
            ->groupBy('account_id')
            ->each(function ($pdfs, $accountId): void {
                $payload = $pdfs->map(fn ($pdf): array => [
                    'name' => $pdf->name,
                    'base64' => $pdf->base64,
                    'is_primary' => (bool) $pdf->is_primary,
                ])->values()->all();

                DB::table('accounts')->where('id', $accountId)->update(['pdfs' => json_encode($payload)]);
            });

        Schema::dropIfExists('account_pdfs');
    }
};
