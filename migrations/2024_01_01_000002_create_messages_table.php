<?php

/**
 * Migration: Create messages table
 * 
 * این جدول برای ذخیره پیام‌های رد و بدل شده در هر چت استفاده می‌شود
 */
class Plugitify_CreateMessagesTable {
    
    public function up() {
        return Plugitify_DB::schema()->create('messages', function($table) {
            $table->id();
            $table->bigInteger('chat_history_id')->unsigned(); // ID چت مربوطه
            $table->string('role', 50); // user, assistant, system
            $table->text('content'); // محتوای پیام
            $table->text('metadata')->nullable(); // داده‌های اضافی (JSON)
            $table->text('status')->nullable(); // pending, completed
            $table->integer('tokens')->nullable()->default(0); // تعداد توکن‌های استفاده شده
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

        });
    }
    
    public function down() {
        Plugitify_DB::schema()->dropIfExists('messages');
    }
}

