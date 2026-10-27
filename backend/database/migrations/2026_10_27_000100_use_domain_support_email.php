<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Support email moves from Gmail to the domain mailbox (still editable in Filament → Settings).
return new class extends Migration
{
    public function up(): void
    {
        // Only replace the old Gmail address or an empty value; keep any address an admin chose themselves
        DB::table('settings')
            ->where('key', 'supportEmail')
            ->whereIn('value', [json_encode('joviaplatform@gmail.com'), '', '""'])
            ->update(['value' => json_encode('support@joviaplatform.ng')]);
    }

    public function down(): void
    {
        // Leave the value; it can be changed in Settings
    }
};
