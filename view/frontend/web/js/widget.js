(function () {
    'use strict';

    var cfgEl = document.getElementById('yu-aichat-config');
    var root = document.getElementById('yu-aichat');
    if (!cfgEl || !root) {
        return;
    }
    var cfg = JSON.parse(cfgEl.textContent);
    var SS = window.sessionStorage;
    var LS = window.localStorage;
    var KEY_OPEN = 'yu-aichat-open';
    var KEY_ACTIVE = 'yu-aichat-active';
    var KEY_GREETED = 'yu-aichat-greeted';
    var GREET_DELAY_MS = 4000;
    var GREET_DURATION_MS = 5000;
    var activeUuid = SS.getItem(KEY_ACTIVE) || null;
    var pending = false;

    /* ---------- helpers ---------- */

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text) { e.textContent = text; }
        return e;
    }

    function esc(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* Minimal markdown renderer. Input is HTML-escaped BEFORE any markup is
       applied: model output must never reach the DOM unescaped. */
    function mdInline(s) {
        return s
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\*([^*]+)\*/g, '<em>$1</em>')
            .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]*)\)/g,
                '<a href="$2" target="_blank" rel="noopener">$1</a>')
            /* Bare URLs the model printed outside markdown syntax. The
               leading group keeps URLs already inside href="..." intact;
               trailing punctuation stays outside the link. */
            .replace(/(^|[^"'>])(https?:\/\/[^\s<]*[^\s<.,!?;:)])/g,
                '$1<a href="$2" target="_blank" rel="noopener">$2</a>');
    }

    function mdRender(text) {
        return esc(text).split(/\n{2,}/).map(function (block) {
            if (/^```/.test(block)) {
                return '<pre><code>' + block.replace(/^```\w*\n?/, '').replace(/\n?```$/, '') + '</code></pre>';
            }
            var lines = block.split('\n');
            if (lines.every(function (l) { return /^[-*] /.test(l); })) {
                return '<ul>' + lines.map(function (l) {
                    return '<li>' + mdInline(l.slice(2)) + '</li>';
                }).join('') + '</ul>';
            }
            if (lines.every(function (l) { return /^\d+\. /.test(l); })) {
                return '<ol>' + lines.map(function (l) {
                    return '<li>' + mdInline(l.replace(/^\d+\. /, '')) + '</li>';
                }).join('') + '</ol>';
            }
            var h = lines.length === 1 && block.match(/^(#{1,3}) (.+)$/);
            if (h) {
                var n = h[1].length + 3;
                return '<h' + n + '>' + mdInline(h[2]) + '</h' + n + '>';
            }
            return '<p>' + lines.map(mdInline).join('<br>') + '</p>';
        }).join('');
    }

    function getFormKey() {
        var m = document.cookie.match(/(?:^|; )form_key=([^;]+)/);
        if (m) {
            return decodeURIComponent(m[1]);
        }
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        var key = '';
        for (var i = 0; i < 16; i++) {
            key += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.cookie = 'form_key=' + key + '; path=/';
        return key;
    }

    function apiGet(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.json();
        });
    }

    function apiPost(url, data) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data).toString()
        }).then(function (r) {
            return r.json().then(function (json) {
                if (!r.ok) { throw new Error(json.error || 'HTTP ' + r.status); }
                return json;
            });
        });
    }

    /* ---------- DOM ---------- */

    var button = el('button', 'yu-aichat-button yu-aichat-pos-' + cfg.position);
    button.type = 'button';
    button.setAttribute('aria-label', cfg.title);
    button.innerHTML = '<svg class="yu-aichat-button-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
        + '<rect x="3" y="4" width="18" height="13" rx="6" fill="currentColor"/>'
        + '<polygon points="8,17 8,21 12,17" fill="currentColor"/>'
        + '<circle class="yu-aichat-button-dot" cx="8.5" cy="10.5" r="1.3"/>'
        + '<circle class="yu-aichat-button-dot" cx="12" cy="10.5" r="1.3"/>'
        + '<circle class="yu-aichat-button-dot" cx="15.5" cy="10.5" r="1.3"/>'
        + '</svg>';
    button.appendChild(el('span', 'yu-aichat-button-label', cfg.title));

    var panel = el('div', 'yu-aichat-panel yu-aichat-pos-' + cfg.position);
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', cfg.title);
    panel.hidden = true;

    var header = el('div', 'yu-aichat-header');
    var headerTitle = el('span', 'yu-aichat-title', cfg.title);
    var btnList = el('button', 'yu-aichat-hbtn', 'Chats');
    btnList.type = 'button';
    btnList.title = 'Conversation history';
    btnList.setAttribute('aria-label', 'Conversation history');
    var btnNew = el('button', 'yu-aichat-hbtn', '+ New');
    btnNew.type = 'button';
    btnNew.title = 'Start a new conversation';
    btnNew.setAttribute('aria-label', 'Start a new conversation');
    var btnClose = el('button', 'yu-aichat-hbtn yu-aichat-hbtn-close', '×');
    btnClose.type = 'button';
    btnClose.title = 'Close';
    btnClose.setAttribute('aria-label', 'Close');
    header.append(btnList, headerTitle, btnNew, btnClose);

    var viewChat = el('div', 'yu-aichat-view-chat');
    var feed = el('div', 'yu-aichat-feed');
    var form = el('form', 'yu-aichat-form');
    var input = el('textarea', 'yu-aichat-input');
    input.rows = 3;
    input.placeholder = 'Type a message…';
    var btnSend = el('button', 'yu-aichat-send');
    btnSend.type = 'submit';
    btnSend.setAttribute('aria-label', 'Send');
    btnSend.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
        + '<polygon points="3,11 21,3 13,21 11,13" fill="currentColor"/>'
        + '</svg>';
    form.append(input, btnSend);
    viewChat.append(feed, form);

    var viewList = el('div', 'yu-aichat-view-list');
    viewList.hidden = true;

    panel.append(header, viewChat, viewList);
    root.append(button, panel);

    /* ---------- rendering ---------- */

    function bubble(role, html) {
        var b = el('div', 'yu-aichat-msg yu-aichat-msg-' + role);
        b.innerHTML = html;
        feed.appendChild(b);
        feed.scrollTop = feed.scrollHeight;
        return b;
    }

    function showWelcome() {
        feed.innerHTML = '';
        if (cfg.welcomeMessage) {
            bubble('assistant', mdRender(cfg.welcomeMessage));
        }
        if (cfg.suggestedQuestions.length) {
            var wrap = el('div', 'yu-aichat-suggest');
            cfg.suggestedQuestions.forEach(function (q) {
                var chip = el('button', 'yu-aichat-chip', q);
                chip.type = 'button';
                chip.addEventListener('click', function () {
                    sendMessage(q);
                });
                wrap.appendChild(chip);
            });
            feed.appendChild(wrap);
        }
    }

    function showTyping() {
        var t = el('div', 'yu-aichat-msg yu-aichat-msg-assistant yu-aichat-typing');
        t.innerHTML = '<span></span><span></span><span></span>';
        feed.appendChild(t);
        feed.scrollTop = feed.scrollHeight;
        return t;
    }

    function showChatView() {
        viewList.hidden = true;
        viewChat.hidden = false;
        btnList.textContent = 'Chats';
        btnList.title = 'Conversation history';
        btnList.setAttribute('aria-label', 'Conversation history');
    }

    function openConversation(uuid) {
        activeUuid = uuid;
        if (uuid) {
            SS.setItem(KEY_ACTIVE, uuid);
        } else {
            SS.removeItem(KEY_ACTIVE);
        }
        showChatView();
        if (!uuid) {
            showWelcome();
            return;
        }
        feed.innerHTML = '';
        apiGet(cfg.urls.messages + '?uuid=' + encodeURIComponent(uuid)).then(function (json) {
            feed.innerHTML = '';
            json.messages.forEach(function (m) {
                bubble(m.role, mdRender(m.content));
            });
        }).catch(function () {
            // Foreign or expired conversation: silently start a fresh one.
            openConversation(null);
        });
    }

    function showListView() {
        viewChat.hidden = true;
        viewList.hidden = false;
        btnList.textContent = '← Back';
        btnList.title = 'Back to chat';
        btnList.setAttribute('aria-label', 'Back to chat');
        viewList.innerHTML = '';
        var loading = el('div', 'yu-aichat-empty', 'Loading…');
        viewList.appendChild(loading);
        apiGet(cfg.urls.conversations).then(function (json) {
            viewList.innerHTML = '';
            if (!json.conversations.length) {
                viewList.appendChild(el('div', 'yu-aichat-empty', 'No conversations yet.'));
                return;
            }
            json.conversations.forEach(function (c) {
                var item = el('button', 'yu-aichat-conv');
                item.type = 'button';
                item.appendChild(el('span', 'yu-aichat-conv-title', c.title || 'Conversation'));
                var date = new Date(c.updated_at.replace(' ', 'T') + 'Z');
                item.appendChild(el('span', 'yu-aichat-conv-date', date.toLocaleDateString()));
                item.addEventListener('click', function () {
                    openConversation(c.uuid);
                });
                viewList.appendChild(item);
            });
        }).catch(function () {
            viewList.innerHTML = '';
            viewList.appendChild(el('div', 'yu-aichat-empty', 'Could not load conversations.'));
        });
    }

    /* ---------- actions ---------- */

    function sendMessage(text) {
        text = (text || '').trim();
        if (!text || pending) {
            return;
        }
        pending = true;
        btnSend.disabled = true;
        var suggest = feed.querySelector('.yu-aichat-suggest');
        if (suggest) {
            suggest.remove();
        }
        bubble('user', mdRender(text));
        input.value = '';
        var typing = showTyping();
        apiPost(cfg.urls.send, {
            form_key: getFormKey(),
            message: text,
            conversation_uuid: activeUuid || '',
            'context[page_type]': cfg.context.page_type,
            'context[product_id]': cfg.context.product_id || '',
            'context[category_id]': cfg.context.category_id || '',
            'context[url]': window.location.href,
            'context[referrer]': document.referrer
        }).then(function (json) {
            activeUuid = json.conversation_uuid;
            SS.setItem(KEY_ACTIVE, activeUuid);
            typing.remove();
            bubble('assistant', mdRender(json.message.content));
        }).catch(function (err) {
            typing.remove();
            bubble('error', esc(err.message || 'Something went wrong.'));
        }).finally(function () {
            pending = false;
            btnSend.disabled = false;
            input.focus();
        });
    }

    function openPanel(immediate) {
        panel.hidden = false;
        if (immediate === true) {
            panel.classList.add('yu-aichat-no-anim');
        }
        void panel.offsetWidth; // force reflow so the transition starts from the closed state
        panel.classList.add('yu-aichat-panel-open');
        if (immediate === true) {
            void panel.offsetWidth;
            panel.classList.remove('yu-aichat-no-anim');
        }
        button.hidden = true;
        SS.setItem(KEY_OPEN, '1');
        openConversation(activeUuid);
        input.focus();
    }

    function closePanel() {
        panel.classList.remove('yu-aichat-panel-open');
        button.hidden = false;
        SS.removeItem(KEY_OPEN);
        panel.addEventListener('transitionend', function onEnd() {
            panel.hidden = true;
            panel.removeEventListener('transitionend', onEnd);
        });
    }

    /* ---------- first-visit greeting (idle pulse + expanding label) ---------- */

    var greetTimer = null;

    function cancelGreeting() {
        if (greetTimer !== null) {
            clearTimeout(greetTimer);
            greetTimer = null;
        }
        button.classList.remove('yu-aichat-button-greet', 'yu-aichat-button-pulse');
        LS.setItem(KEY_GREETED, '1');
    }

    function maybeGreet() {
        // Only for a genuinely new/idle visitor: never greeted before on this
        // browser, chat is not already open, and no conversation in progress.
        if (LS.getItem(KEY_GREETED) === '1' || SS.getItem(KEY_OPEN) === '1' || activeUuid) {
            return;
        }
        greetTimer = setTimeout(function () {
            button.classList.add('yu-aichat-button-greet', 'yu-aichat-button-pulse');
            greetTimer = setTimeout(function () {
                button.classList.remove('yu-aichat-button-greet', 'yu-aichat-button-pulse');
                LS.setItem(KEY_GREETED, '1');
            }, GREET_DURATION_MS);
        }, GREET_DELAY_MS);
    }

    /* ---------- events ---------- */

    button.addEventListener('click', cancelGreeting);
    button.addEventListener('click', openPanel);
    btnClose.addEventListener('click', closePanel);
    btnNew.addEventListener('click', function () {
        openConversation(null);
    });
    btnList.addEventListener('click', function () {
        if (viewChat.hidden) {
            showChatView();
        } else {
            showListView();
        }
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        sendMessage(input.value);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(input.value);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !panel.hidden) {
            closePanel();
        }
    });

    if (SS.getItem(KEY_OPEN) === '1') {
        openPanel(true);
    } else {
        maybeGreet();
    }
})();
