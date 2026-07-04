<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->text('page_header')->nullable()->after('footer');
            $table->text('page_footer')->nullable()->after('page_header');
            $table->json('groups')->nullable()->after('page_footer'); // [{level, header, footer}, ...]
            $table->text('parameter_screen')->nullable()->after('groups');
            $table->json('meta')->nullable()->after('parameter_screen'); // {prompt: string}
        });

        // Fold any existing single-level group_header/group_footer into the new groups array.
        \DB::table('templates')
            ->whereNotNull('group_header')->orWhereNotNull('group_footer')
            ->get(['id', 'group_header', 'group_footer'])
            ->each(function ($row) {
                \DB::table('templates')->where('id', $row->id)->update([
                    'groups' => json_encode([[
                        'level'  => 1,
                        'header' => $row->group_header,
                        'footer' => $row->group_footer,
                    ]]),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn(['page_header', 'page_footer', 'groups', 'parameter_screen', 'meta']);
        });
    }
};
