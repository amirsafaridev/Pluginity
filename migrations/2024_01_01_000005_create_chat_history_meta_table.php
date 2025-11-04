<?php

/**
 * Migration: Create chat_history_meta table
 * 
 * این جدول برای ذخیره متا دیتای اضافی چت‌ها استفاده می‌شود
 * ساختار مشابه wp_postmeta و wp_usermeta در WordPress
 */
class CreateChatHistoryMetaTable {
    
    public function up() {
        return Plugitify_DB::schema()->create('chat_history_meta', function($table) {
            $table->id();
            $table->bigInteger('chat_history_id')->unsigned(); // ID چت مربوطه
            $table->string('meta_key', 255); // کلید متا
            $table->text('meta_value')->nullable(); // مقدار متا
            $table->timestamps();
        });
    }
    
    public function down() {
        Plugitify_DB::schema()->dropIfExists('chat_history_meta');
    }
}

