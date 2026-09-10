document.addEventListener('DOMContentLoaded', function () {
    var urlInput = document.getElementById('urlInput');
    var executeBtn = document.getElementById('executeBtn');
    var copyBtn = document.getElementById('copyBtn');
    var previewLink = document.getElementById('previewLink');
    var card = document.getElementById('card');
    var cardTitle = document.getElementById('cardTitle');
    var cardStatus = document.getElementById('cardStatus');
    var messageArea = document.getElementById('messageArea');
    var themeToggle = document.getElementById('themeToggle');
    var themeIcon = document.getElementById('themeIcon');
    var exportRow = document.getElementById('exportRow');
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    var siteUrl = document.querySelector('meta[name="site-url"]').content.replace(/\/$/, '');

    var currentShortUrl = '';

    urlInput.addEventListener('input', function () {
        if (urlInput.value.trim().length > 0) {
            executeBtn.classList.add('visible');
        } else {
            executeBtn.classList.remove('visible');
        }
    });

    urlInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            shortenLink();
        }
    });

    executeBtn.addEventListener('click', function (e) {
        e.preventDefault();
        shortenLink();
    });

    function shortenLink() {
        var url = urlInput.value.trim();
        if (!url) {
            urlInput.focus();
            urlInput.classList.add('shake');
            setTimeout(function () { urlInput.classList.remove('shake'); }, 450);
            showMessage('لطفاً آدرس لینک را وارد کنید', 'error');
            return;
        }

        if (!isValidUrl(url)) {
            showMessage('لطفاً یک لینک معتبر وارد کنید', 'error');
            return;
        }

        hideMessage();
        card.classList.add('loading');
        cardTitle.textContent = 'در حال پردازش...';
        previewLink.textContent = siteUrl;

        fetch('index.php?route=shorten', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url: url, csrf_token: csrfToken })
        })
        .then(function (r) {
            return r.text().then(function (text) {
                var data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error('invalid-response');
                }
                if (!r.ok) {
                    throw new Error(data.message || 'request-failed');
                }
                return data;
            });
        })
        .then(function (data) {
            card.classList.remove('loading');
            if (data.success) {
                currentShortUrl = siteUrl + '/' + data.code;
                var displayUrl = currentShortUrl.replace(/^https?:\/\//, '');
                previewLink.textContent = displayUrl;
                cardTitle.textContent = 'لینک کوتاه شما';
                cardStatus.textContent = 'آماده اشتراک‌گذاری';
                loadStats();
            } else {
                previewLink.textContent = siteUrl;
                cardTitle.textContent = 'لینک کوتاه شما';
                showMessage(data.message || 'خطا در پردازش', 'error');
            }
        })
        .catch(function (error) {
            card.classList.remove('loading');
            previewLink.textContent = siteUrl;
            cardTitle.textContent = 'لینک کوتاه شما';
            var message = error && error.message && error.message !== 'invalid-response' && error.message !== 'request-failed'
                ? error.message
                : 'خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.';
            showMessage(message, 'error');
        });
    }

    function isValidUrl(str) {
        if (/\s/.test(str) || !/^https?:\/\//i.test(str)) {
            return false;
        }
        try {
            var u = new URL(str);
            return (u.protocol === 'http:' || u.protocol === 'https:') && Boolean(u.hostname);
        } catch (e) {
            return false;
        }
    }

    function showMessage(msg, type) {
        messageArea.textContent = msg;
        messageArea.className = 'message-area ' + type;
    }

    function hideMessage() {
        messageArea.className = 'message-area hidden';
    }

    copyBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        copyToClipboard();
    });

    card.addEventListener('click', function () {
        if (currentShortUrl) {
            copyToClipboard();
        }
    });

    if (exportRow) {
        var exportButtons = exportRow.querySelectorAll('[data-format]');
        for (var i = 0; i < exportButtons.length; i++) {
            exportButtons[i].addEventListener('click', function () {
                exportCard(this.getAttribute('data-format'));
            });
        }
    }

    function exportCard(format) {
        if (!currentShortUrl) {
            showMessage('ابتدا یک لینک کوتاه کنید', 'error');
            return;
        }

        var canvas = document.createElement('canvas');
        var scale = 2;
        canvas.width = 840 * scale;
        canvas.height = 520 * scale;
        var context = canvas.getContext('2d');
        context.scale(scale, scale);

        var gradient = context.createLinearGradient(0, 0, 840, 520);
        gradient.addColorStop(0, '#12b886');
        gradient.addColorStop(1, '#056b4d');
        context.fillStyle = gradient;
        drawRoundedRect(context, 0, 0, 840, 520, 38);
        context.fill();

        context.fillStyle = 'rgba(255, 255, 255, .13)';
        drawRoundedRect(context, 32, 32, 776, 456, 28);
        context.fill();

        context.fillStyle = '#ffffff';
        context.font = '700 26px Arad';
        context.direction = 'rtl';
        context.textAlign = 'right';
        context.fillText('لینک کوتاه شما', 764, 86);

        context.font = '700 36px Arad';
        context.textAlign = 'center';
        context.direction = 'ltr';
        context.fillText(currentShortUrl, 420, 270);

        context.fillStyle = 'rgba(255, 255, 255, .28)';
        context.fillRect(76, 324, 688, 2);

        context.fillStyle = 'rgba(255, 255, 255, .78)';
        context.font = '600 22px Arad';
        context.direction = 'rtl';
        context.textAlign = 'right';
        context.fillText('آماده اشتراک‌گذاری', 764, 386);
        context.fillText(siteUrl, 764, 438);

        var mimeType = format === 'jpeg' ? 'image/jpeg' : 'image/' + format;
        var extension = format === 'jpeg' ? 'jpg' : format;
        var link = document.createElement('a');
        link.download = 'short-link.' + extension;
        link.href = canvas.toDataURL(mimeType, .95);
        link.click();
    }

    function drawRoundedRect(context, x, y, width, height, radius) {
        context.beginPath();
        context.moveTo(x + radius, y);
        context.arcTo(x + width, y, x + width, y + height, radius);
        context.arcTo(x + width, y + height, x, y + height, radius);
        context.arcTo(x, y + height, x, y, radius);
        context.arcTo(x, y, x + width, y, radius);
        context.closePath();
    }

    function copyToClipboard() {
        if (!currentShortUrl) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(currentShortUrl).then(function () {
                copyBtn.innerHTML = '<i class="fa-regular fa-check"></i> کپی شد';
                setTimeout(function () {
                    copyBtn.innerHTML = '<i class="fa-regular fa-copy"></i> کپی لینک';
                }, 1500);
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = currentShortUrl;
            ta.style.cssText = 'position:fixed;left:-9999px';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                copyBtn.innerHTML = '<i class="fa-regular fa-check"></i> کپی شد';
                setTimeout(function () {
                    copyBtn.innerHTML = '<i class="fa-regular fa-copy"></i> کپی لینک';
                }, 1500);
            } catch (e) {
                copyBtn.innerHTML = 'آماده کپی';
            }
            document.body.removeChild(ta);
        }
    }

    if (themeToggle) {
        var savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
            document.body.classList.add('dark');
            themeIcon.className = 'fa-regular fa-sun';
        }

        themeToggle.addEventListener('click', function () {
            document.body.classList.toggle('dark');
            var isDark = document.body.classList.contains('dark');
            themeIcon.className = isDark ? 'fa-regular fa-sun' : 'fa-regular fa-moon';
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
    }

    function loadStats() {
        fetch('index.php?route=stats')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var statClicks = document.getElementById('statClicks');
            var statLinks = document.getElementById('statLinks');
            if (statClicks) statClicks.textContent = toPersianNum(data.total_clicks);
            if (statLinks) statLinks.textContent = toPersianNum(data.total_links);
        });
    }

    function toPersianNum(num) {
        var persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        var str = String(num);
        var result = '';
        for (var i = 0; i < str.length; i++) {
            var digit = parseInt(str[i]);
            result += isNaN(digit) ? str[i] : persianDigits[digit];
        }
        return result.replace(/\B(?=(\d{3})+(?!\d))/g, '٬');
    }

    loadStats();
});
