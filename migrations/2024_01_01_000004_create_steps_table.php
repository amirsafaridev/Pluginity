<?php

/**
 * Migration: Create steps table
 * 
 * این جدول برای ذخیره مراحل مختلف هر تسک استفاده می‌شود
 * هر تسک می‌تواند چندین مرحله داشته باشد
 */
class CreateStepsTable {
    
    public function up() {
        return Plugitify_DB::schema()->create('steps', function($table) {
            $table->id();
            $table->bigInteger('task_id')->unsigned(); // ID تسک مربوطه
            $table->string('step_name', 255); // نام مرحله
            $table->string('step_type', 100)->nullable(); // نوع مرحله
            $table->integer('order')->default(0); // ترتیب اجرای مرحله
            $table->string('status', 50)->default('pending'); // pending, in_progress, completed, failed, skipped
            $table->text('content')->nullable(); // محتوای مرحله
            $table->text('data')->nullable(); // داده‌های مرحله (JSON)
            $table->text('result')->nullable(); // نتیجه مرحله (JSON)
            $table->text('error_message')->nullable(); // پیام خطا در صورت شکست
            $table->integer('duration')->nullable()->default(0); // زمان انجام (ثانیه)
            $table->timestamps();
        });
    }
    
    public function down() {
        Plugitify_DB::schema()->dropIfExists('steps');
    }
}

