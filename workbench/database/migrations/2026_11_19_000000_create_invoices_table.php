<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('customer_tax_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->integer('exempt_cents')->default(0);
            $table->integer('taxable_cents')->default(0);
            $table->boolean('is_simplified')->default(false);

            // The service's answer, in columns named the app's own way.
            $table->uuid('verifactu_uuid')->nullable()->unique();
            $table->string('aeat_status')->nullable()->index();
            $table->string('number')->nullable();
            $table->string('short_number')->nullable();
            $table->date('issued_on')->nullable();
            $table->string('verification_url')->nullable();
            $table->text('qr')->nullable();

            $table->timestamps();
        });
    }
};
