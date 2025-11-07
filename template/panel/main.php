<?php
/**
 * Template file for main chat area
 * This file contains only HTML markup, no PHP code
 */
?>
<main class="ai-main">
    <div class="ai-main__inner">
        <div class="ai-thread" id="ai-chat-messages" aria-live="polite">
            <div class="ai-msg ai-msg--bot">
                <div class="ai-avatar ai-avatar--bot" aria-hidden="true"></div>
                <div class="ai-bubble">
                    Hi! Ask me anything about building WordPress plugins.
                    <div class="ai-meta"><span class="ai-meta__clock" aria-hidden="true"></span>10:01</div>
                </div>
            </div>
            <div class="ai-msg ai-msg--user">
                <div class="ai-bubble">
                    What can you help me build?
                    <div class="ai-meta"><span class="ai-meta__clock" aria-hidden="true"></span>10:02</div>
                </div>
                <div class="ai-avatar ai-avatar--user" aria-hidden="true"></div>
            </div>
            <div class="ai-msg ai-msg--bot">
                <div class="ai-avatar ai-avatar--bot" aria-hidden="true"></div>
                <div class="ai-bubble">
                    SEO, security, custom post types, REST APIs, and more.
                    <div class="ai-meta"><span class="ai-meta__clock" aria-hidden="true"></span>10:02</div>
                </div>
            </div>
            <div class="ai-msg ai-msg--user">
                <div class="ai-bubble">
                    Create CPT for Books
                    <div class="ai-meta"><span class="ai-meta__clock" aria-hidden="true"></span>10:03</div>
                </div>
                <div class="ai-avatar ai-avatar--user" aria-hidden="true"></div>
            </div>
            <div class="ai-msg ai-msg--bot">
                <div class="ai-avatar ai-avatar--bot" aria-hidden="true"></div>
                <div class="ai-bubble">
                    Sure. Do you need custom fields and taxonomy?
                    <div class="ai-meta"><span class="ai-meta__clock" aria-hidden="true"></span>10:03</div>
                </div>
            </div>
            <div class="ai-msg ai-msg--user">
                <div class="ai-bubble">
                    Yes, author and genre.
                    <div class="ai-meta"><span class="ai-meta__clock" aria-hidden="true"></span>10:04</div>
                </div>
                <div class="ai-avatar ai-avatar--user" aria-hidden="true"></div>
            </div>
            <div class="ai-msg ai-msg--bot">
                <div class="ai-avatar ai-avatar--bot" aria-hidden="true"></div>
                <div class="ai-bubble">
                    Got it. I'll include both.
                    <div class="ai-meta"><span class="ai-meta__clock" aria-hidden="true"></span>10:05</div>
                </div>
            </div>
            <div class="ai-msg ai-msg--user">
                <div class="ai-bubble">
                    Add REST endpoints
                    <div class="ai-meta"><span class="ai-meta__clock" aria-hidden="true"></span>10:06</div>
                </div>
                <div class="ai-avatar ai-avatar--user" aria-hidden="true"></div>
            </div>
            <div class="ai-msg ai-msg--bot">
                <div class="ai-avatar ai-avatar--bot" aria-hidden="true"></div>
                <div class="ai-bubble">
                    Endpoints for list, create, and update.
                    <div class="ai-meta"><span class="ai-meta__clock" aria-hidden="true"></span>10:06</div>
                </div>
            </div>
            <div class="ai-msg ai-msg--user">
                <div class="ai-bubble">
                    Perfect, proceed.
                    <div class="ai-meta"><span class="ai-meta__clock" aria-hidden="true"></span>10:07</div>
                </div>
                <div class="ai-avatar ai-avatar--user" aria-hidden="true"></div>
            </div>
        </div>
        <form class="ai-composer" method="post" action="#">
            <label for="ai-input" class="screen-reader-text">Your message</label>
            <textarea id="ai-input" name="message" rows="1" placeholder="Send a message..." required></textarea>
            <button type="submit" class="ai-btn ai-btn--send" aria-label="Send">Send</button>
        </form>
    </div>
</main>
