<?php
/**
 * smtp_mailer.php
 * ─────────────────────────────────────────────────────────────
 * عميل SMTP خفيف بدون أي مكتبات خارجية (لا يحتاج Composer/PHPMailer).
 * يدعم:
 *   - اتصال SSL مباشر (المنفذ الشائع 465)
 *   - STARTTLS (المنفذ الشائع 587)
 *   - بدون تشفير (خوادم داخلية على المنفذ 25 — غير مستحسن للاستخدام العام)
 *   - AUTH LOGIN (الطريقة الأكثر توافقاً مع خوادم الاستضافة المشتركة)
 *
 * الاستخدام:
 *   require_once 'includes/smtp_mailer.php';
 *   $mailer = getSmtpMailer();                 // يقرأ الإعدادات من جدول settings
 *   $ok = $mailer->send($to, $toName, $subject, $htmlBody);
 *   if (!$ok) { echo $mailer->lastError; }
 */

class SmtpMailer
{
    private string $host;
    private int    $port;
    private string $username;
    private string $password;
    private string $encryption; // ssl | tls | none
    private string $fromEmail;
    private string $fromName;
    private int    $timeout = 15;

    /** آخر رسالة خطأ حدثت أثناء الإرسال (بالعربية، صالحة للعرض للمستخدم/الإدارة) */
    public string $lastError = '';

    /**
     * سجلّ كامل لمحادثة SMTP (أوامر مُرسَلة وردود الخادم) لأغراض التشخيص.
     * كل عنصر: ['dir' => 'C'|'S'|'i', 'text' => '...']
     * 'C' = أمر أرسلناه للخادم، 'S' = رد استلمناه من الخادم، 'i' = معلومة عامة (اتصال...الخ)
     * كلمة المرور لا تُسجَّل أبداً بشكل قابل للقراءة.
     */
    public array $log = [];

    private function logLine(string $dir, string $text): void
    {
        $this->log[] = ['dir' => $dir, 'text' => $text];
    }

    public function __construct(array $cfg)
    {
        $this->host       = trim($cfg['host'] ?? '');
        $this->port       = (int)($cfg['port'] ?? 587);
        $this->username   = trim($cfg['username'] ?? '');
        $this->password   = trim((string)($cfg['password'] ?? ''));
        $this->encryption = $cfg['encryption'] ?? 'tls';
        $this->fromEmail  = trim($cfg['from_email'] ?? $this->username);
        $this->fromName   = trim($cfg['from_name'] ?? '');
    }

    /**
     * إرسال رسالة HTML واحدة.
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        $this->lastError = '';
        $this->log       = [];

        if ($this->host === '') {
            $this->lastError = 'إعدادات SMTP غير مكتملة: اسم مضيف الخادم (Host) غير محدد.';
            return false;
        }
        if ($this->fromEmail === '') {
            $this->lastError = 'إعدادات SMTP غير مكتملة: البريد المرسِل (From) غير محدد.';
            return false;
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'عنوان بريد المستلم غير صالح.';
            return false;
        }

        // ── تصحيح دفاعي: المنفذ 465 يستخدم عملياً SSL الضمني دائماً، وليس STARTTLS.
        // إذا اختار المستخدم "TLS" مع المنفذ 465 عن طريق الخطأ، نصحّح تلقائياً لتفادي
        // تعليق الاتصال (الخادم في هذه الحالة ينتظر مصافحة TLS فوراً ولا يرسل أي رد نصي).
        $effectiveEncryption = $this->encryption;
        if ($this->port === 465 && $effectiveEncryption !== 'ssl') {
            $effectiveEncryption = 'ssl';
        }

        $transport = ($effectiveEncryption === 'ssl') ? 'ssl://' : 'tcp://';
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]]);

        $errno = 0; $errstr = '';
        $this->logLine('i', "الاتصال بـ {$transport}{$this->host}:{$this->port} (تشفير فعلي: {$effectiveEncryption}) ...");
        $sock = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno, $errstr, $this->timeout,
            STREAM_CLIENT_CONNECT, $ctx
        );

        if (!$sock) {
            $this->lastError = "تعذّر الاتصال بخادم SMTP ({$this->host}:{$this->port}): {$errstr}";
            $this->logLine('i', 'فشل الاتصال: ' . $errstr);
            return false;
        }
        $this->logLine('i', 'تم الاتصال بنجاح على مستوى TCP/TLS.');
        stream_set_timeout($sock, $this->timeout);

        try {
            try {
                $this->expect($sock, [220]);
            } catch (Throwable $e) {
                // خطأ شائع جداً: عدم توافق المنفذ مع نوع التشفير المختار
                throw new Exception(
                    'لم يردّ الخادم عند الاتصال (' . $transport . $this->host . ':' . $this->port . '). '
                    . 'الأغلب أن نوع التشفير المختار لا يطابق المنفذ — '
                    . 'المنفذ 465 يتطلب "SSL"، والمنفذ 587 يتطلب "TLS (STARTTLS)". '
                    . 'تحقق أيضاً أن الاستضافة تسمح باتصالات SMTP الصادرة على هذا المنفذ.'
                );
            }
            $this->cmd($sock, 'EHLO ' . $this->heloHost(), [250]);

            if ($effectiveEncryption === 'tls') {
                $this->cmd($sock, 'STARTTLS', [220]);
                $cryptoOk = @stream_socket_enable_crypto(
                    $sock, true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                );
                if (!$cryptoOk) {
                    throw new Exception('فشل تفعيل التشفير STARTTLS مع الخادم.');
                }
                // يجب إعادة تحية EHLO بعد بدء التشفير وفق بروتوكول SMTP
                $this->cmd($sock, 'EHLO ' . $this->heloHost(), [250]);
            }

            // المصادقة — تُتخطى إذا لم يُحدَّد اسم مستخدم (خوادم استرسال داخلية بدون مصادقة)
            if ($this->username !== '') {
                $this->cmd($sock, 'AUTH LOGIN', [334]);
                $this->cmd(
                    $sock, base64_encode($this->username), [334],
                    "[Base64 لاسم المستخدم]: {$this->username}"
                );
                try {
                    $this->cmd(
                        $sock, base64_encode($this->password), [235],
                        '[Base64 لكلمة المرور] — مخفية، الطول المُرسَل: ' . strlen($this->password) . ' حرف'
                    );
                } catch (Throwable $e) {
                    throw new Exception(
                        'رفض الخادم بيانات الدخول (اسم المستخدم/كلمة المرور). '
                        . 'تأكد أن اسم المستخدم هو البريد الكامل، وأن كلمة المرور مطابقة تماماً '
                        . 'لكلمة مرور صندوق البريد في cPanel (لا مسافات زائدة). '
                        . 'التفاصيل: ' . $e->getMessage()
                    );
                }
            }

            $this->cmd($sock, "MAIL FROM:<{$this->fromEmail}>", [250]);
            $this->cmd($sock, "RCPT TO:<{$toEmail}>", [250, 251]);
            $this->cmd($sock, 'DATA', [354]);

            $payload = $this->buildHeaders($toEmail, $toName, $subject) . "\r\n" . $this->dotStuff($htmlBody) . "\r\n.";
            $this->cmd($sock, $payload, [250], '[محتوى الرسالة الكامل — مخفي من السجل لتقليل الإطالة]');

            $this->cmd($sock, 'QUIT', [221]);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->logLine('i', 'خطأ: ' . $e->getMessage());
            @fclose($sock);
            return false;
        }

        @fclose($sock);
        $this->logLine('i', 'تم الإرسال بنجاح ✅');
        return true;
    }

    private function heloHost(): string
    {
        $h = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        // إزالة أي منفذ من اسم المضيف
        return preg_replace('/:\d+$/', '', $h) ?: 'localhost';
    }

    private function encodeHeaderUtf8(string $text): string
    {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    private function buildHeaders(string $toEmail, string $toName, string $subject): string
    {
        $fromHeader = $this->fromName !== ''
            ? $this->encodeHeaderUtf8($this->fromName) . " <{$this->fromEmail}>"
            : $this->fromEmail;
        $toHeader = $toName !== ''
            ? $this->encodeHeaderUtf8($toName) . " <{$toEmail}>"
            : $toEmail;

        $h  = "From: {$fromHeader}\r\n";
        $h .= "To: {$toHeader}\r\n";
        $h .= 'Subject: ' . $this->encodeHeaderUtf8($subject) . "\r\n";
        $h .= "MIME-Version: 1.0\r\n";
        $h .= "Content-Type: text/html; charset=UTF-8\r\n";
        $h .= "Content-Transfer-Encoding: 8bit\r\n";
        $h .= 'Date: ' . date('r') . "\r\n";
        $h .= 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->heloHost() . ">\r\n";
        return $h;
    }

    /**
     * "Dot-stuffing": أي سطر يبدأ بنقطة منفردة يجب مضاعفتها لمنع خادم SMTP
     * من تفسيرها كنهاية للرسالة قبل أوانها (RFC 5321).
     */
    private function dotStuff(string $body): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $body));
        foreach ($lines as &$line) {
            $line = rtrim($line, "\r");
            if (isset($line[0]) && $line[0] === '.') $line = '.' . $line;
        }
        return implode("\r\n", $lines);
    }

    private function readLine($sock): string
    {
        $line = fgets($sock, 515);
        if ($line === false) throw new Exception('انقطع الاتصال بخادم SMTP أثناء الانتظار للرد.');
        $this->logLine('S', rtrim($line, "\r\n"));
        return $line;
    }

    /** يقرأ رداً (قد يكون متعدد الأسطر) ويتحقق أن الكود ضمن المتوقع */
    private function expect($sock, array $expectedCodes): string
    {
        $full = '';
        $code = 0;
        do {
            $line  = $this->readLine($sock);
            $full .= $line;
            $code  = (int)substr($line, 0, 3);
            $isContinuation = isset($line[3]) && $line[3] === '-';
        } while ($isContinuation);

        if (!in_array($code, $expectedCodes, true)) {
            throw new Exception('استجابة غير متوقعة من خادم SMTP: ' . trim($full));
        }
        return $full;
    }

    private function cmd($sock, string $command, array $expectedCodes, ?string $displayText = null): string
    {
        $this->logLine('C', $displayText ?? $command);
        fwrite($sock, $command . "\r\n");
        return $this->expect($sock, $expectedCodes);
    }
}

/**
 * يبني كائن SmtpMailer جاهزاً من إعدادات لوحة الإدارة (جدول settings).
 */
function getSmtpMailer(): SmtpMailer
{
    return new SmtpMailer([
        'host'       => getSetting('smtp_host'),
        'port'       => (int)(getSetting('smtp_port') ?: 587),
        'username'   => getSetting('smtp_username'),
        'password'   => getSetting('smtp_password'),
        'encryption' => getSetting('smtp_encryption') ?: 'tls',
        'from_email' => getSetting('smtp_from_email') ?: getSetting('smtp_username'),
        'from_name'  => getSetting('smtp_from_name') ?: (getSetting('site_name') ?: SITE_NAME),
    ]);
}
