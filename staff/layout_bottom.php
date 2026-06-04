</div><!-- closes #page-content from layout_top -->

<!-- ── GLOBAL CUSTOM CONFIRM MODAL ───────────────────────────────────────────── -->
<div id="custom-confirm-overlay"
     style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,.55); backdrop-filter:blur(4px);
            align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:1.75rem; box-shadow:0 32px 64px -12px rgba(0,0,0,.35); padding:2.5rem;
                max-width:400px; width:calc(100% - 2rem); animation:ccFadeIn .18s ease;">
        <p id="custom-confirm-title"
           style="font-weight:900; font-size:1rem; color:#0f172a; margin:0 0 .75rem; letter-spacing:-.01em;"></p>
        <p id="custom-confirm-msg"
           style="font-size:.875rem; color:#64748b; margin:0 0 2rem; line-height:1.6;"></p>
        <div style="display:flex; gap:.75rem; justify-content:flex-end;">
            <button id="custom-confirm-cancel"
                    style="padding:.65rem 1.5rem; border-radius:.75rem; border:1.5px solid #e2e8f0;
                           background:#fff; color:#64748b; font-weight:900; font-size:.7rem;
                           text-transform:uppercase; letter-spacing:.1em; cursor:pointer; transition:all .15s;">
                Cancel
            </button>
            <button id="custom-confirm-ok"
                    style="padding:.65rem 1.5rem; border-radius:.75rem; border:none;
                           background:#0f172a; color:#fff; font-weight:900; font-size:.7rem;
                           text-transform:uppercase; letter-spacing:.1em; cursor:pointer; transition:all .15s;">
                Confirm
            </button>
        </div>
    </div>
</div>
<style>
@keyframes ccFadeIn { from { opacity:0; transform:scale(.96) translateY(8px); } to { opacity:1; transform:scale(1) translateY(0); } }
#custom-confirm-cancel:hover { background:#f1f5f9; color:#334155; }
#custom-confirm-ok:hover { background:#10b981; }
</style>

<?php
$flash_msg  = htmlspecialchars($_GET['success'] ?? $_GET['error'] ?? '', ENT_QUOTES, 'UTF-8');
$flash_type = isset($_GET['success']) ? 'success' : (isset($_GET['error']) ? 'error' : '');
?>

<!-- ── GLOBAL FLASH TOAST (outside #page-content so it persists across SPA loads) ── -->
<div id="global-flash"
     class="fixed bottom-8 right-8 z-[500] max-w-sm w-full pointer-events-auto"
     style="opacity:0; transform:translateY(12px); transition:opacity .25s ease, transform .25s ease; display:none;">
</div>

</main><!-- closes #main-content from layout_top -->

<style>
    body { animation: fadeIn .3s ease-in; }
    @keyframes fadeIn { from { opacity:0; transform:translateY(5px); } to { opacity:1; transform:translateY(0); } }
</style>

<script>
// ── Silent CSV download — no new tab, no URL change, no refresh issue ─────────
function triggerDownload(url) {
    var a = document.createElement('a');
    a.href = url;
    a.setAttribute('data-download', '1');
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    setTimeout(function() { document.body.removeChild(a); }, 200);
}

// ── GLOBAL FLASH FUNCTIONS (available to all pages via layout) ────────────────
function showFlash(message, type) {
    type = type || 'success';
    const el = document.getElementById('global-flash');
    if (!el) return;

    if (window._flashTimer) { clearTimeout(window._flashTimer); window._flashTimer = null; }

    const isSuccess = (type === 'success');
    const isWarning = (type === 'warning');
    const bg     = isSuccess ? '#10b981' : (isWarning ? '#f59e0b' : '#ef4444');
    const shadow = isSuccess ? '0 20px 40px -8px rgba(16,185,129,.35)' : (isWarning ? '0 20px 40px -8px rgba(245,158,11,.35)' : '0 20px 40px -8px rgba(239,68,68,.35)');
    const icon   = isSuccess
        ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>'
        : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>';

    el.innerHTML =
        '<div style="background:' + bg + ';border-radius:1rem;box-shadow:' + shadow + ';overflow:hidden;">' +
            '<div style="padding:1rem 1.25rem;display:flex;align-items:center;gap:.75rem;">' +
                '<svg style="width:1.25rem;height:1.25rem;flex-shrink:0;color:white;" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
                    icon +
                '</svg>' +
                '<span style="font-weight:700;font-size:.875rem;color:white;flex:1;line-height:1.4;">' + message + '</span>' +
                '<button onclick="hideFlash()" style="opacity:.6;background:none;border:none;cursor:pointer;color:white;padding:0;margin-left:.5rem;flex-shrink:0;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity=.6">' +
                    '<svg style="width:1rem;height:1rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
                        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>' +
                    '</svg>' +
                '</button>' +
            '</div>' +
            '<div style="height:3px;background:rgba(255,255,255,.2);">' +
                '<div id="flash-bar" style="height:100%;background:rgba(255,255,255,.65);width:100%;"></div>' +
            '</div>' +
        '</div>';

    el.style.display = 'block';
    // Animate in
    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            el.style.opacity  = '1';
            el.style.transform = 'translateY(0)';
        });
    });

    // Start progress bar
    var bar = document.getElementById('flash-bar');
    if (bar) {
        bar.style.transition = 'none';
        bar.style.width = '100%';
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                bar.style.transition = 'width 3000ms linear';
                bar.style.width = '0%';
            });
        });
    }

    window._flashTimer = setTimeout(hideFlash, 3000);
}

function hideFlash() {
    var el = document.getElementById('global-flash');
    if (!el || el.style.display === 'none') return;
    el.style.opacity   = '0';
    el.style.transform = 'translateY(12px)';
    setTimeout(function() {
        el.style.display = 'none';
        el.innerHTML = '';
    }, 260);
    if (window._flashTimer) { clearTimeout(window._flashTimer); window._flashTimer = null; }
}

// ── GLOBAL CUSTOM CONFIRM ─────────────────────────────────────────────────────
function customConfirm(message, title) {
    return new Promise(function(resolve) {
        var overlay  = document.getElementById('custom-confirm-overlay');
        var titleEl  = document.getElementById('custom-confirm-title');
        var msgEl    = document.getElementById('custom-confirm-msg');
        var okBtn    = document.getElementById('custom-confirm-ok');
        var cancelBtn = document.getElementById('custom-confirm-cancel');

        titleEl.textContent = title || 'Confirm Action';
        msgEl.textContent   = message;

        overlay.style.display = 'flex';

        function close(result) {
            overlay.style.display = 'none';
            okBtn.onclick = null;
            cancelBtn.onclick = null;
            overlay.onclick = null;
            resolve(result);
        }
        okBtn.onclick     = function() { close(true); };
        cancelBtn.onclick = function() { close(false); };
        overlay.onclick   = function(e) { if (e.target === overlay) close(false); };
    });
}

function confirmForm(e, form, message, title) {
    e.preventDefault();
    e.stopPropagation(); // prevent global SPA submit listener from firing before user confirms
    customConfirm(message, title).then(function(ok) {
        if (ok) {
            var fd = new FormData(form);
            var action = form.getAttribute('action') || window.location.href;
            var silentForms = ['pos_process.php', 'refund_process.php', 'api/'];
            var isSilent = silentForms.some(function(p) { return action.includes(p); });
            navigate(action, fd, isSilent);
        }
    });
}

function confirmAction(message, callback, title) {
    customConfirm(message, title).then(function(ok) { if (ok) callback(); });
}

// ── BOOT: fire PHP-generated flash — works on full-page load AND SPA re-render ─
<?php if ($flash_msg !== ''): ?>
(function() {
    function _fireFlash() {
        if (typeof showFlash === 'function') {
            showFlash('<?= addslashes($flash_msg) ?>', '<?= $flash_type ?>');
            var url = new URL(window.location.href);
            url.searchParams.delete('success');
            url.searchParams.delete('error');
            history.replaceState({}, '', url.toString());
        }
    }
    // DOMContentLoaded not yet fired → wait for it; otherwise fire immediately (SPA context)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _fireFlash);
    } else {
        _fireFlash();
    }
})();
<?php endif; ?>
</script>

</body>
</html>
