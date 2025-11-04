<?php

/**
 * Migration: Create messages_meta table
 * 
 * این جدول برای ذخیره متا دیتای اضافی پیام‌ها استفاده می‌شود
 * ساختار مشابه wp_postmeta و wp_usermeta در WordPress
 */
class CreateMessagesMetaTable {
    
    public function up() {
        return Plugitify_DB::schema()->create('messages_meta', function($table) {
            $table->id();
            $table->bigInteger('message_id')->unsigned(); // ID پیام مربوطه
            $table->string('meta_key', 255); // کلید متا
            $table->text('meta_value')->nullable(); // مقدار متا
            $table->timestamps();
        });
    }
    
    public function down() {
        Plugitify_DB::schema()->dropIfExists('messages_meta');
    }
}

