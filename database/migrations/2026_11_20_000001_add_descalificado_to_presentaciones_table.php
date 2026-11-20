<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentaciones', function (Blueprint $table) {
            $table->boolean('descalificado')->default(false)->after('nota_final');
            $table->string('motivo_descalificacion')->nullable()->after('descalificado');
        });
    }

    public function down(): void
    {
        Schema::table('presentaciones', function (Blueprint $table) {
            $table->dropColumn(['descalificado', 'motivo_descalificacion']);
        });
    }
};
