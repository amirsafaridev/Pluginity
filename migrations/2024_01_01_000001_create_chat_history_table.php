<?php

/**
 * Migration: Create chat_history table
 * 
 * این جدول برای ذخیره تاریخچه چت‌های ادمین با هوش مصنوعی استفاده می‌شود
 */
class CreateChatHistoryTable {
    
    public function up() {
        return Plugitify_DB::schema()->create('chat_history', function($table) {
            $table->id();
            $table->bigInteger('user_id')->unsigned(); // ادمین که چت را ایجاد کرده
            $table->string('title', 255)->nullable(); // عنوان چت
            $table->string('status', 50)->default('active'); // active, completed, archived
            $table->text('summary')->nullable(); // خلاصه چت
            $table->timestamps();
        });
    }
    
    public function down() {
        Plugitify_DB::schema()->dropIfExists('chat_history');
    }
}

