<?php

/**
 * Migration: Create tasks table
 * 
 * این جدول برای ذخیره تسک‌ها و کارهایی که هوش مصنوعی باید انجام دهد استفاده می‌شود
 */
class Plugitify_CreateTasksTable {
    
    public function up() {
        return Plugitify_DB::schema()->create('tasks', function($table) {
            $table->id();
            $table->bigInteger('chat_history_id')->unsigned()->nullable(); // ID چت مربوطه (اختیاری)
            $table->bigInteger('message_id')->unsigned()->nullable(); // ID پیام مربوطه (اختیاری)
            $table->bigInteger('user_id')->unsigned(); // ادمین که تسک را ایجاد کرده
            $table->string('task_name', 255); // نام تسک
            $table->string('task_type', 100)->nullable(); // نوع تسک (plugin_creation, code_generation, etc.)
            $table->text('description')->nullable(); // توضیحات تسک
            $table->text('requirements')->nullable(); // نیازمندی‌های تسک (JSON)
            $table->string('status', 50)->default('pending'); // pending, in_progress, completed, failed, cancelled
            $table->text('result')->nullable(); // نتیجه تسک (JSON)
            $table->text('error_message')->nullable(); // پیام خطا در صورت شکست
            $table->integer('progress')->default(0); // درصد پیشرفت (0-100)
            $table->timestamps();
        });
    }
    
    public function down() {
        Plugitify_DB::schema()->dropIfExists('tasks');
    }
}

