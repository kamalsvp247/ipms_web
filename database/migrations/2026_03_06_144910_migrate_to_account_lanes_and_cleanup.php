<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Migrate account_proxy rows → account_lanes (lane_type = 'proxy')
        DB::table('account_proxy')->orderBy('id')->each(function ($row) {
            DB::table('account_lanes')->updateOrInsert(
                ['account_id' => $row->account_id, 'position' => $row->position],
                [
                    'lane_type' => 'proxy',
                    'proxy_id' => $row->proxy_id,
                    'local_ip_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        });

        // Migrate account_local_ip rows → account_lanes (lane_type = 'local_ip')
        // Offset position to avoid collision with proxy positions
        DB::table('account_local_ip')->orderBy('id')->each(function ($row) {
            // Find next available position for this account
            $maxPosition = DB::table('account_lanes')
                ->where('account_id', $row->account_id)
                ->max('position');
            $position = $maxPosition !== null ? $maxPosition + 1 : $row->position;

            DB::table('account_lanes')->insert([
                'account_id' => $row->account_id,
                'position' => $position,
                'lane_type' => 'local_ip',
                'proxy_id' => null,
                'local_ip_id' => $row->local_ip_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // Drop old pivot tables
        Schema::dropIfExists('account_proxy');
        Schema::dropIfExists('account_local_ip');

        // Remove deprecated columns from accounts table
        Schema::table('accounts', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('accounts', 'burst_enabled')) {
                $columns[] = 'burst_enabled';
            }
            if (Schema::hasColumn('accounts', 'proxy_enabled')) {
                $columns[] = 'proxy_enabled';
            }
            if (Schema::hasColumn('accounts', 'burst_proxy_count')) {
                $columns[] = 'burst_proxy_count';
            }
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }

    public function down(): void
    {
        // Restore proxy_enabled and burst_enabled columns
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('proxy_enabled')->default(false);
            $table->boolean('burst_enabled')->default(false);
        });

        // Recreate old pivot tables
        Schema::create('account_proxy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proxy_id')->constrained()->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->unique(['account_id', 'proxy_id']);
        });

        Schema::create('account_local_ip', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('local_ip_id')->constrained()->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->unique(['account_id', 'local_ip_id']);
        });

        // Restore data from account_lanes back to old tables
        DB::table('account_lanes')->where('lane_type', 'proxy')->each(function ($row) {
            DB::table('account_proxy')->insert([
                'account_id' => $row->account_id,
                'proxy_id' => $row->proxy_id,
                'position' => $row->position,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        DB::table('account_lanes')->where('lane_type', 'local_ip')->each(function ($row) {
            DB::table('account_local_ip')->insert([
                'account_id' => $row->account_id,
                'local_ip_id' => $row->local_ip_id,
                'position' => $row->position,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
};
