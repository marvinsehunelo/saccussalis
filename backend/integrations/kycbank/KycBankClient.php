<?php
// backend/integrations/kycbank/KycBankClient.php
//
// Client for KYC Bank, the shared KYC service. The bank asks for a person's
// KYC by phone or Omang, the person approves in the KYC Bank app, KYC Bank
// sends a signed webhook (backend/integrations/kycbank/webhook.php) and the
// bank fetches the verified profile.
//
// Configuration comes only from the environment:
//   KYCBANK_API_URL        e.g. the staging API base URL
//   KYCBANK_CLIENT_ID
//   KYCBANK_CLIENT_SECRET
//   KYCBANK_WEBHOOK_SECRET (only the webhook needs it)
//
// The access token is cached in a 0600 file in sys_get_temp_dir() until 60 s
// before it expires. A 401 from any call drops the cache and retries once.
//
// Nothing here logs or puts personal data into exception messages: errors
// carry KYC Bank's code and message, which contain no personal data.

final class KycBankException extends RuntimeException
{
    /** KYC Bank's error code (validation_failed, consent_not_active, ...), or network_error / bad_response. */
    public string $errorCode;
    /** HTTP status, 0 when the request never got an answer. */
    public int $httpStatus;
    public ?string $requestId;

    public function __construct(string $errorCode, string $message, int $httpStatus = 0, ?string $requestId = null)
    {
        parent::__construct($message, $httpStatus);
        $this->errorCode  = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->requestId  = $requestId;
    }

    /** True when trying again later may work (network trouble, rate limits, KYC Bank 5xx). */
    public function isTransient(): bool
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }

    /** "code: message" for staff, plus KYC Bank's request id when there is one. */
    public function forStaff(): string
    {
        return $this->errorCode . ': ' . $this->getMessage()
            . ($this->requestId ? ' (request ' . $this->requestId . ')' : '');
    }
}

final class KycBankClient
{
    public const ENV_VARS = ['KYCBANK_API_URL', 'KYCBANK_CLIENT_ID', 'KYCBANK_CLIENT_SECRET'];

    private const CONNECT_TIMEOUT = 5;   // seconds
    private const TOTAL_TIMEOUT   = 15;  // seconds
    private const TOKEN_MARGIN    = 60;  // refresh this many seconds before expiry
    private const SIGNATURE_TOLERANCE = 300; // webhook timestamps older/newer than 5 min are rejected

    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $cacheFile;
    private ?array $memoryToken = null;

    public function __construct(string $baseUrl, string $clientId, string $clientSecret, ?string $cacheFile = null)
    {
        $this->baseUrl      = rtrim($baseUrl, '/');
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->cacheFile    = $cacheFile
            ?? rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
               . 'kycbank_token_' . hash('sha256', $this->baseUrl . '|' . $clientId) . '.json';
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';
        }
        return trim((string)$value);
    }

    /** Names of the required env vars that are not set (empty when configured). */
    public static function missingEnv(): array
    {
        return array_values(array_filter(self::ENV_VARS, fn ($n) => self::env($n) === ''));
    }

    public static function isConfigured(): bool
    {
        return self::missingEnv() === [];
    }

    /** A client from the environment, or null when KYC Bank is not configured. */
    public static function fromEnv(): ?self
    {
        if (!self::isConfigured()) {
            return null;
        }
        return new self(self::env('KYCBANK_API_URL'), self::env('KYCBANK_CLIENT_ID'), self::env('KYCBANK_CLIENT_SECRET'));
    }

    /** KYCBANK_WEBHOOK_SECRET, or '' when it is not set. */
    public static function webhookSecret(): string
    {
        return self::env('KYCBANK_WEBHOOK_SECRET');
    }

    // ------------------------------------------------------------------
    // Token
    // ------------------------------------------------------------------

    /** A valid access token, from the cache when it has one. */
    public function token(bool $forceRefresh = false): string
    {
        if (!$forceRefresh) {
            $cached = $this->memoryToken ?? $this->readCache();
            if ($cached && (int)$cached['expires_at'] - self::TOKEN_MARGIN > time()) {
                $this->memoryToken = $cached;
                return (string)$cached['access_token'];
            }
        }

        [$status, $body] = $this->send('POST', '/oauth/token', [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        if ($status < 200 || $status >= 300) {
            throw $this->errorFrom($status, $body);
        }
        if (!is_array($body) || empty($body['access_token']) || !is_string($body['access_token'])) {
            throw new KycBankException('bad_response', 'KYC Bank returned no access token.', $status);
        }
        $token = [
            'access_token' => $body['access_token'],
            'expires_at'   => time() + max(0, (int)($body['expires_in'] ?? 900)),
        ];
        $this->memoryToken = $token;
        $this->writeCache($token);
        return $token['access_token'];
    }

    public function dropToken(): void
    {
        $this->memoryToken = null;
        if (is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    private function readCache(): ?array
    {
        if (function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            $hit = apcu_fetch($this->cacheKey());
            if (is_array($hit)) {
                return $hit;
            }
        }
        if (!is_file($this->cacheFile)) {
            return null;
        }
        $raw  = @file_get_contents($this->cacheFile);
        $data = $raw === false ? null : json_decode($raw, true);
        return is_array($data) && isset($data['access_token'], $data['expires_at']) ? $data : null;
    }

    private function writeCache(array $token): void
    {
        if (function_exists('apcu_store') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            apcu_store($this->cacheKey(), $token, max(1, (int)$token['expires_at'] - time()));
            return;
        }
        // Written to a private temp file, then moved into place, so nobody
        // else on the machine can read it and readers never see half a file.
        $dir = dirname($this->cacheFile);
        $tmp = @tempnam($dir, 'kycbank_');
        if ($tmp === false) {
            return;
        }
        @chmod($tmp, 0600);
        if (@file_put_contents($tmp, json_encode($token)) === false || !@rename($tmp, $this->cacheFile)) {
            @unlink($tmp);
            return;
        }
        @chmod($this->cacheFile, 0600);
    }

    private function cacheKey(): string
    {
        return 'kycbank_token_' . hash('sha256', $this->baseUrl . '|' . $this->clientId);
    }

    // ------------------------------------------------------------------
    // API calls
    // ------------------------------------------------------------------

    /**
     * Asks KYC Bank for a person's consent. The same $idempotencyKey always
     * returns the first result, so keep it with the bank's request and reuse
     * it when retrying.
     *
     * @return array the consent (id "cns_...", state "requested", ...)
     */
    public function createConsentRequest(
        string $identifierKind,
        string $value,
        string $level,
        string $purpose,
        string $idempotencyKey,
        int $durationDays = 365,
        bool $updates = true,
        ?array $fields = null
    ): array {
        if ($identifierKind === 'mobile') {
            if (!self::isValidMobile($value)) {
                throw new KycBankException('validation_failed', 'The phone number must be in international form, e.g. +267 followed by the number.', 400);
            }
        } elseif ($identifierKind === 'omang') {
            if (!self::isValidOmang($value)) {
                throw new KycBankException('validation_failed', 'An Omang number has exactly 9 digits.', 400);
            }
        } else {
            throw new KycBankException('validation_failed', 'Identify the person by mobile or omang.', 400);
        }
        if (!in_array($level, ['basic', 'standard'], true)) {
            throw new KycBankException('validation_failed', 'Level must be basic or standard.', 400);
        }
        $purpose = trim($purpose);
        $len = function_exists('mb_strlen') ? mb_strlen($purpose, 'UTF-8') : strlen($purpose);
        if ($len < 1 || $len > 200) {
            throw new KycBankException('validation_failed', 'The purpose must be between 1 and 200 characters.', 400);
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new KycBankException('validation_failed', 'An idempotency key is required.', 400);
        }

        $body = [
            'identifier'   => ['kind' => $identifierKind, 'value' => $value],
            'level'        => $level,
            'purpose'      => $purpose,
            'durationDays' => $durationDays,
            'updates'      => $updates,
        ];
        if ($fields !== null) {
            $body['fields'] = array_values($fields);
        }
        return $this->call('POST', '/v1/consent-requests', $body, ['Idempotency-Key: ' . $idempotencyKey]);
    }

    /** The consent as KYC Bank has it now. */
    public function getConsentRequest(string $id): array
    {
        return $this->call('GET', '/v1/consent-requests/' . rawurlencode(self::checkConsentId($id)));
    }

    /**
     * The verified profile shared under an active consent (fields limited to
     * the consent). KYC Bank answers 403 consent_not_active otherwise. Use
     * getProfileRaw() to also get the exact JSON text received.
     */
    public function getProfile(string $consentId): array
    {
        return $this->getProfileRaw($consentId)['profile'];
    }

    /** @return array{profile: array, raw: string} the decoded profile and the exact JSON text received. */
    public function getProfileRaw(string $consentId): array
    {
        $raw = '';
        $profile = $this->call('GET', '/v1/consents/' . rawurlencode(self::checkConsentId($consentId)) . '/profile', null, [], $raw);
        return ['profile' => $profile, 'raw' => $raw];
    }

    public static function isValidMobile(string $value): bool
    {
        return (bool)preg_match('/^\+[1-9]\d{6,14}$/', $value);
    }

    public static function isValidOmang(string $value): bool
    {
        return (bool)preg_match('/^\d{9}$/', $value);
    }

    public static function isValidConsentId(string $id): bool
    {
        return (bool)preg_match('/^cns_[A-Za-z0-9_-]{1,100}$/', $id);
    }

    private static function checkConsentId(string $id): string
    {
        if (!self::isValidConsentId($id)) {
            throw new KycBankException('validation_failed', 'That is not a KYC Bank consent id.', 400);
        }
        return $id;
    }

    /** An authorised JSON call; on a 401 the token is renewed and the call retried once. */
    private function call(string $method, string $path, ?array $body = null, array $headers = [], ?string &$raw = null): array
    {
        for ($attempt = 0; ; $attempt++) {
            $token = $this->token($attempt > 0);
            [$status, $decoded, $text] = $this->send($method, $path, $body, array_merge($headers, ['Authorization: Bearer ' . $token]));
            if ($status === 401 && $attempt === 0) {
                $this->dropToken();
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw $this->errorFrom($status, $decoded);
            }
            if (!is_array($decoded)) {
                throw new KycBankException('bad_response', 'KYC Bank sent a response that is not JSON.', $status);
            }
            $raw = $text;
            return $decoded;
        }
    }

    /** @return array{0: int, 1: mixed, 2: string} HTTP status, decoded JSON (or null) and the body text. */
    private function send(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers[] = 'Accept: application/json';
        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $text   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno  = curl_errno($ch);
        curl_close($ch);

        if ($text === false || $errno !== 0) {
            throw new KycBankException('network_error', 'Could not reach KYC Bank (curl error ' . $errno . ').', 0);
        }
        $text = (string)$text;
        return [$status, $text === '' ? null : json_decode($text, true), $text];
    }

    private function errorFrom(int $status, $body): KycBankException
    {
        if (is_array($body) && isset($body['code']) && is_string($body['code'])) {
            return new KycBankException(
                $body['code'],
                is_string($body['message'] ?? null) ? $body['message'] : 'KYC Bank refused the request.',
                $status,
                is_string($body['requestId'] ?? null) ? $body['requestId'] : null
            );
        }
        return new KycBankException('http_' . $status, 'KYC Bank answered HTTP ' . $status . '.', $status);
    }

    // ------------------------------------------------------------------
    // Webhook signature
    // ------------------------------------------------------------------

    /**
     * Checks an x-kyc-signature header ("t=<unix seconds>,v1=<hex>"): v1 must
     * be the hex HMAC-SHA256 of "<t>.<raw body>" with $secret, and t within
     * 5 minutes of $now either way.
     */
    public static function verifyWebhookSignature(string $rawBody, ?string $header, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || $header === null || trim($header) === '') {
            return false;
        }
        $now ??= time();

        $timestamp  = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }
        if ($timestamp === null || !ctype_digit($timestamp) || $signatures === []) {
            return false;
        }
        if (abs($now - (int)$timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }
        return false;
    }
}
