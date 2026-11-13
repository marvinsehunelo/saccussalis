<?php
// tests/hold_signature_test.php
//
// Checks that backend/api/v1/hold.php only acts on requests signed by a member of the
// VouchMorph scheme. Command line only:
//
//     php tests/hold_signature_test.php
//
// Part 1 (always runs) needs no database: it makes a throwaway certificate authority, signs
// requests the way VouchMorph's CertificateManager::createSignedRequest() does, and checks
// verify_signed_caller() accepts those and refuses everything else.
//
// Part 2 runs only with HOLD_E2E=1 and a LOCAL throwaway PostgreSQL database in the PG*
// variables (it refuses any host other than localhost, and DROPS AND RECREATES the tables it
// uses). It starts hold.php on local ports and checks, for place, release and debit, that an
// unsigned or wrongly signed request changes nothing and a signed one works; that odd bodies, bad
// amounts, a place without a reference and replays change nothing; that HOLD_ALLOWED_SIGNERS,
// on and off, decides who may call hold.php and notify_debit.php; that no PIN, signature or
// certificate from either endpoint reaches the log; and
// that requests at the same moment cannot over-reserve or act twice. For those, the test holds the
// row lock itself and lets the requests go only once PostgreSQL shows all of them waiting for it,
// so the result does not depend on timing. docs/SECURITY_NOTES.md ("Holds") shows how to start
// a throwaway PostgreSQL (it will not run as root). Then:
//
//     HOLD_E2E=1 PGHOST=127.0.0.1 PGPORT=5432 PGDATABASE=hold_test PGUSER=postgres \
//         PGPASSWORD=made-up php tests/hold_signature_test.php
//
// Every key, certificate, account and amount here is made up for the test.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/helpers/signed_request.php';

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    $failed += $ok ? 0 : 1;
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $name, ($ok || $detail === '') ? '' : "  [$detail]");
}

// ---------------------------------------------------------------------------------------------
// Throwaway PKI
// ---------------------------------------------------------------------------------------------
$tmpConf = tempnam(sys_get_temp_dir(), 'holdtest_cnf_');
file_put_contents($tmpConf, implode("\n", [
    '[req]', 'distinguished_name = dn', 'prompt = no', '[dn]', 'CN = placeholder',
    '[v3_ca]', 'basicConstraints = critical,CA:TRUE', 'keyUsage = critical,keyCertSign,cRLSign',
    '[v3_leaf]', 'basicConstraints = CA:FALSE', 'keyUsage = critical,digitalSignature', '',
]));
register_shutdown_function(static function () use ($tmpConf) { @unlink($tmpConf); });

function new_key()
{
    return openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
}

/** @return array{0: string, 1: OpenSSLAsymmetricKey} certificate PEM and its private key */
function make_cert(string $cn, ?string $caCertPem, $caKey, bool $isCa): array
{
    global $tmpConf;
    $key  = new_key();
    $opts = ['config' => $tmpConf, 'digest_alg' => 'sha256', 'x509_extensions' => $isCa ? 'v3_ca' : 'v3_leaf'];
    $csr  = openssl_csr_new(['commonName' => $cn], $key, $opts);
    $cert = openssl_csr_sign($csr, $caCertPem, $caKey ?? $key, 30, $opts, random_int(1000, PHP_INT_MAX));
    openssl_x509_export($cert, $pem);
    return [$pem, $key];
}

/** Signs a payload exactly as VouchMorph does (timestamp added, requester left out, keys sorted). */
function vm_sign(array $payload, $privateKey, string $certPem, string $requester = 'VOUCHMORPH'): array
{
    $toSign = array_merge($payload, ['timestamp' => time()]);
    unset($toSign['requester']);
    ksort($toSign);
    openssl_sign(json_encode($toSign, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $sig, $privateKey, OPENSSL_ALGO_SHA256);
    return array_merge($toSign, ['signature' => base64_encode($sig), 'requester' => $requester, 'certificate' => $certPem]);
}

/** What the bank sees: the signed request after a trip through JSON, as it arrives over HTTP. */
function over_the_wire(array $request): array
{
    return json_decode(json_encode($request, JSON_UNESCAPED_SLASHES), true);
}

[$caPem, $caKey]         = make_cert('Test Scheme CA (made up)', null, null, true);
[$vmPem, $vmKey]         = make_cert('VOUCHMORPH-TEST', $caPem, $caKey, false);
[$otherCaPem, $otherCaKey] = make_cert('Some Other CA (made up)', null, null, true);
[$strangerPem, $strangerKey] = make_cert('STRANGER', $otherCaPem, $otherCaKey, false);
[$memberPem, $memberKey] = make_cert('OTHER-BANK-TEST', $caPem, $caKey, false);   // same scheme CA, not VouchMorph
[$selfPem, $selfKey]     = make_cert('SELF-SIGNED', null, null, false);

putenv('VOUCHMORPH_CA_CERT_CONTENT=' . $caPem);

$place = [
    'action' => 'PLACE_HOLD', 'reference' => 'HOLD_TEST_0001', 'asset_type' => 'ACCOUNT', 'amount' => 150.0,
    'currency' => 'BWP', 'source_identifier' => '9000000001', 'account_number' => '9000000001',
    'hold_reason' => 'PENDING_SWAP', 'expiry' => '2030-01-01 00:00:00', 'source_institution' => 'SACCUSSALIS',
    'nested' => ['note' => 'café / übung', 'list' => [1, 2.5, 'x']],
];
$release = ['action' => 'RELEASE_HOLD', 'hold_reference' => 'HOLD_TEST_0001', 'reference' => 'HOLD_TEST_0001'];
$debit   = ['action' => 'DEBIT_FUNDS', 'hold_reference' => 'HOLD_TEST_0001', 'amount' => 150.0];

echo "== Part 1: verify_signed_caller ==\n";

$accept = [
    'signed PLACE_HOLD'   => vm_sign($place, $vmKey, $vmPem),
    'signed RELEASE_HOLD' => vm_sign($release, $vmKey, $vmPem),
    'signed DEBIT_FUNDS'  => vm_sign($debit, $vmKey, $vmPem),
];
foreach ($accept as $name => $req) {
    $r = verify_signed_caller(over_the_wire($req));
    check("accepted: $name", $r['verified'] === true && $r['requester'] === 'VOUCHMORPH', $r['message']);
    check("accepted: $name names the certificate that signed", $r['signer'] === 'VOUCHMORPH-TEST', $r['signer']);
}

// The top-level order of the fields does not matter (they are sorted before checking), and a
// change of type with the same meaning on paper ("150" for 150.0) is a different body.
$reordered = over_the_wire(vm_sign($place, $vmKey, $vmPem));
check('accepted: same signed body with its fields in reverse order', verify_signed_caller(array_reverse($reordered, true))['verified'] === true);
check('refused: amount sent as a string after signing', verify_signed_caller(array_merge($reordered, ['amount' => '150']))['verified'] === false);
$nestedSwapped = $reordered;
$nestedSwapped['nested'] = array_reverse($nestedSwapped['nested'], true);
check('refused: nested fields reordered after signing', verify_signed_caller($nestedSwapped)['verified'] === false);

// `requester` is not covered by the signature: it can be changed, so it must never be trusted
// on its own (the real signer is reported separately), and it is cut down before it is logged.
$r = verify_signed_caller(array_merge($reordered, ['requester' => "SOMEONE\nELSE"]));
check('requester is not signed: changing it still verifies', $r['verified'] === true);
check('requester is not signed: signer still reports the real certificate', $r['signer'] === 'VOUCHMORPH-TEST');
check('requester with a line break is made log-safe', strpos($r['requester'], "\n") === false, json_encode($r['requester']));

// A body that cannot be encoded again (a number too large for a float) would otherwise be
// checked as an empty string, so a signature over "" must not open it.
openssl_sign('', $emptySig, $vmKey, OPENSSL_ALGO_SHA256);
$inf = json_decode('{"action":"PLACE_HOLD","amount":1e400}', true);
$inf += ['signature' => base64_encode($emptySig), 'certificate' => $vmPem, 'requester' => 'VOUCHMORPH'];
check('refused: unencodable body with a signature over the empty string', verify_signed_caller($inf)['verified'] === false);

$signedPlace = vm_sign($place, $vmKey, $vmPem);
$wrongKeySig = vm_sign($place, $strangerKey, $vmPem);           // VouchMorph's certificate, someone else's key
$refuse = [
    'no certificate, no signature'      => $place,
    'signature but no certificate'      => array_diff_key($signedPlace, ['certificate' => 1]),
    'certificate but no signature'      => array_diff_key($signedPlace, ['signature' => 1]),
    'empty certificate'                 => array_merge($signedPlace, ['certificate' => '']),
    'empty signature'                   => array_merge($signedPlace, ['signature' => '']),
    'certificate is not a string'       => array_merge($signedPlace, ['certificate' => ['x']]),
    'signature is not a string'         => array_merge($signedPlace, ['signature' => ['x']]),
    'amount changed after signing'      => array_merge($signedPlace, ['amount' => 9999.0]),
    'account changed after signing'     => array_merge($signedPlace, ['source_identifier' => '9000000002']),
    'field added after signing'         => array_merge($signedPlace, ['extra' => 'x']),
    'release: reference changed'        => array_merge(vm_sign($release, $vmKey, $vmPem), ['hold_reference' => 'HOLD_TEST_0002']),
    'action changed after signing'      => array_merge(vm_sign($release, $vmKey, $vmPem), ['action' => 'DEBIT']),
    'signed with a different key'       => $wrongKeySig,
    'certificate from another CA'       => vm_sign($place, $strangerKey, $strangerPem),
    'self-signed certificate'           => vm_sign($place, $selfKey, $selfPem),
    'CA certificate used as the signer' => vm_sign($place, $otherCaKey, $otherCaPem),
    'garbage certificate'               => array_merge($signedPlace, ['certificate' => 'not a certificate']),
    'garbage signature'                 => array_merge($signedPlace, ['signature' => 'AAAA']),
    'only the certificate, copied'      => array_merge($place, ['certificate' => $vmPem, 'signature' => base64_encode('copied')]),
];
foreach ($refuse as $name => $req) {
    $r = verify_signed_caller(over_the_wire($req));
    check("refused: $name", $r['verified'] === false && $r['message'] !== '', 'was accepted');
}
foreach (['null body' => null, 'string body' => 'PLACE_HOLD', 'empty array' => []] as $name => $body) {
    $r = verify_signed_caller($body);
    check("refused: $name", $r['verified'] === false);
}

// A check that breaks or answers in an unexpected shape must refuse, not pass.
$throws = new class('SACCUSSALIS') extends CertificateManager {
    public function verifySignedRequest(array $request): array { throw new RuntimeException('boom'); }
};
$oddShape = new class('SACCUSSALIS') extends CertificateManager {
    public function verifySignedRequest(array $request): array { return ['valid' => true, 'requester' => 'X']; }
};
$truthy = new class('SACCUSSALIS') extends CertificateManager {
    public function verifySignedRequest(array $request): array { return ['verified' => 1, 'requester' => 'X']; }
};
check('refused: verification throws', verify_signed_caller(over_the_wire($signedPlace), $throws)['verified'] === false);
check('refused: result without "verified"', verify_signed_caller(over_the_wire($signedPlace), $oddShape)['verified'] === false);
check('refused: "verified" is not exactly true', verify_signed_caller(over_the_wire($signedPlace), $truthy)['verified'] === false);

// With no CA configured on the host nothing can be trusted.
putenv('VOUCHMORPH_CA_CERT_CONTENT');
check('refused: valid request but no CA configured', verify_signed_caller(over_the_wire($signedPlace))['verified'] === false);
putenv('VOUCHMORPH_CA_CERT_CONTENT=' . $otherCaPem);
check('refused: valid request but a different CA configured', verify_signed_caller(over_the_wire($signedPlace))['verified'] === false);
putenv('VOUCHMORPH_CA_CERT_CONTENT=' . $caPem);

// hold.php itself: the check comes before the database is used and cannot be skipped.
$src = file_get_contents(__DIR__ . '/../backend/api/v1/hold.php');
$gate = strpos($src, 'verify_signed_caller($input)');
$firstDb = strpos($src, '$pdo->');
check('hold.php calls verify_signed_caller', $gate !== false);
check('hold.php verifies before first database use', $gate !== false && $firstDb !== false && $gate < $firstDb);
$dbLoad = strpos($src, "require_once __DIR__ . '/../../db.php'");
check('hold.php opens the database only after the check', $gate !== false && $dbLoad !== false && $gate < $dbLoad);
check('hold.php loads db.php exactly once', substr_count($src, 'db.php\'') === 1);
$cmSrc = file_get_contents(__DIR__ . '/../backend/helpers/CertificateManager.php');
check('CertificateManager does not log the signed body', strpos($cmSrc, 'VERIFYING JSON') === false);

// WHO MAY CALL (HOLD_ALLOWED_SIGNERS), decided 13 November 2026: off unless the host sets it.
putenv('HOLD_ALLOWED_SIGNERS');
check('allow-list: not set means off', allowed_signers() === null);
foreach (['empty' => '', 'only spaces and commas' => ' , ,'] as $name => $v) {
    putenv("HOLD_ALLOWED_SIGNERS=$v");
    check("allow-list: $name means off", allowed_signers() === null);
}
putenv('HOLD_ALLOWED_SIGNERS= OTHER-BANK-TEST , VOUCHMORPH-TEST ,');
check('allow-list: names are trimmed, blanks dropped', allowed_signers() === ['OTHER-BANK-TEST', 'VOUCHMORPH-TEST']);
check('certificate_common_names reads the CN', certificate_common_names($vmPem) === ['VOUCHMORPH-TEST']);
check('certificate_common_names of garbage is empty', certificate_common_names('not a certificate') === [] && certificate_common_names(['x']) === []);

$signedByMember = over_the_wire(vm_sign($place, $memberKey, $memberPem, 'OTHER-BANK'));
putenv('HOLD_ALLOWED_SIGNERS');
$r = verify_signed_caller($signedByMember);
check('allow-list off: another scheme member is accepted (as before)', $r['verified'] === true && $r['signer'] === 'OTHER-BANK-TEST');
check('allow-list off: signer_is_allowed allows anyone', signer_is_allowed($memberPem) && signer_is_allowed('garbage'));

putenv('HOLD_ALLOWED_SIGNERS=VOUCHMORPH-TEST');
$r = verify_signed_caller($reordered);
check('allow-list on: VouchMorph on the list is accepted', $r['verified'] === true && $r['not_allowed'] === false);
$r = verify_signed_caller($signedByMember);
check('allow-list on: a member not on the list is refused as not allowed',
    $r['verified'] === false && $r['not_allowed'] === true && $r['message'] === 'Caller not allowed' && $r['signer'] === 'OTHER-BANK-TEST');
$r = verify_signed_caller(over_the_wire(vm_sign($place, $strangerKey, $strangerPem)));
check('allow-list on: a certificate from another CA is still refused as unsigned (not "not allowed")', $r['verified'] === false && $r['not_allowed'] === false);
$r = verify_signed_caller(over_the_wire(vm_sign($place, $strangerKey, $strangerPem, 'VOUCHMORPH')));
check('allow-list on: claiming requester VOUCHMORPH does not help', $r['verified'] === false);
check('allow-list on: unreadable certificate is not allowed', signer_is_allowed('garbage') === false && signer_is_allowed(null) === false);
putenv('HOLD_ALLOWED_SIGNERS=vouchmorph-test');
check('allow-list on: names are compared exactly (case matters)', verify_signed_caller($reordered)['not_allowed'] === true);
putenv('HOLD_ALLOWED_SIGNERS');

// What is logged about a body: names of fields, never values.
$shape = request_shape_for_log(array_merge($signedPlace, ['pin' => '7319-MADE-UP-PIN', "bad\nkey" => 1]));
check('request_shape_for_log: no values', strpos($shape, '7319-MADE-UP-PIN') === false && strpos($shape, $signedPlace['signature']) === false
    && strpos($shape, 'BEGIN CERTIFICATE') === false && strpos($shape, '9000000001') === false, $shape);
check('request_shape_for_log: field names and action, one line', strpos($shape, 'action=PLACE_HOLD') === 0 && strpos($shape, 'pin') !== false && strpos($shape, "\n") === false, $shape);
check('request_shape_for_log: non-object body', request_shape_for_log('x') === 'body=(not a JSON object)');

// Both endpoints use the same check, before their first database query.
check('hold.php requires a reference on place', strpos($src, "\$required = ['action', 'amount', 'asset_type', 'reference'];") !== false);
check('hold.php no longer invents a reference', strpos($src, 'uniqid') === false);
$ndSrc = file_get_contents(__DIR__ . '/../backend/api/v1/notify_debit.php');
$ndAllow = strpos($ndSrc, 'signer_is_allowed($input');
$ndVerify = strpos($ndSrc, 'verifySignedRequest($input)');
$ndFirstDb = strpos($ndSrc, '$pdo->');
check('notify_debit.php uses the shared allow-list check after its signature check, before the database',
    $ndAllow !== false && $ndVerify !== false && $ndFirstDb !== false && $ndVerify < $ndAllow && $ndAllow < $ndFirstDb);
check('notify_debit.php no longer logs the body', strpos($ndSrc, 'json_encode($input') === false);
check('the allow-list is read in one place only (signed_request.php)', strpos($src . $ndSrc, "getenv('HOLD_ALLOWED_SIGNERS')") === false
    && substr_count(file_get_contents(__DIR__ . '/../backend/helpers/signed_request.php'), "getenv('HOLD_ALLOWED_SIGNERS')") === 1);
check('hold.php has no class_exists() guard around the check', strpos($src, 'class_exists') === false);
check('CertificateManager class is found', class_exists('CertificateManager'));

// ---------------------------------------------------------------------------------------------
// Part 2: the real endpoint against a local throwaway database
// ---------------------------------------------------------------------------------------------
if (getenv('HOLD_E2E') === '1') {
    echo "== Part 2: hold.php end to end ==\n";
    $host = (string)getenv('PGHOST');
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) && ($host === '' || $host[0] !== '/')) {
        fwrite(STDERR, "HOLD_E2E refuses PGHOST '$host': local throwaway databases only.\n");
        exit(2);
    }
    $pdo = new PDO(
        'pgsql:host=' . $host . ';port=' . (getenv('PGPORT') ?: '5432') . ';dbname=' . getenv('PGDATABASE'),
        (string)getenv('PGUSER'), (string)getenv('PGPASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    // Only the columns hold.php uses.
    $pdo->exec("
        DROP TABLE IF EXISTS financial_holds, cards, wallets, accounts, users CASCADE;
        CREATE TABLE users (user_id BIGSERIAL PRIMARY KEY, full_name TEXT, email TEXT, kyc_status TEXT);
        CREATE TABLE accounts (account_id BIGSERIAL PRIMARY KEY, user_id BIGINT, account_number TEXT UNIQUE,
            account_type TEXT, currency TEXT, balance NUMERIC(20,4) DEFAULT 0, held_balance NUMERIC(20,4) DEFAULT 0,
            is_frozen BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT NOW());
        CREATE TABLE wallets (wallet_id BIGSERIAL PRIMARY KEY, user_id BIGINT, phone TEXT, wallet_type TEXT, currency TEXT,
            balance NUMERIC(20,4) DEFAULT 0, held_balance NUMERIC(20,4) DEFAULT 0, is_frozen BOOLEAN DEFAULT FALSE,
            status TEXT DEFAULT 'active');
        CREATE TABLE cards (card_id BIGSERIAL PRIMARY KEY, account_id BIGINT, card_number TEXT, status TEXT, is_frozen BOOLEAN);
        CREATE TABLE financial_holds (id BIGSERIAL PRIMARY KEY, account_id BIGINT, wallet_id BIGINT, amount NUMERIC(20,4),
            hold_reference TEXT UNIQUE NOT NULL, session_id TEXT, status TEXT, expires_at TIMESTAMP,
            created_at TIMESTAMP DEFAULT NOW(), asset_type TEXT, requester TEXT, foreign_bank TEXT,
            cashout_confirmed BOOLEAN DEFAULT FALSE, debited_by TEXT, signature_verified SMALLINT);
        -- what notify_debit.php writes to
        DROP TABLE IF EXISTS settlements, ledger_entries CASCADE;
        CREATE TABLE settlements (settlement_id BIGSERIAL PRIMARY KEY, settlement_ref TEXT, type TEXT, amount NUMERIC(20,4),
            issuer_bank TEXT, status TEXT, created_at TIMESTAMP DEFAULT NOW());
        CREATE TABLE ledger_entries (id BIGSERIAL PRIMARY KEY, reference TEXT, debit_account TEXT, credit_account TEXT,
            amount NUMERIC(20,4), currency TEXT, notes TEXT, created_at TIMESTAMP DEFAULT NOW());
        INSERT INTO users (full_name, email, kyc_status) VALUES ('Test Person (made up)', 'person@example.test', 'approved');
        INSERT INTO accounts (user_id, account_number, account_type, currency, balance) VALUES
            (1, '9000000001', 'savings', 'BWP', 1000), (1, '9000000002', 'savings', 'BWP', 100),
            (1, '9000000003', 'savings', 'BWP', 500), (1, '9000000004', 'savings', 'BWP', 500),
            (1, '9000000005', 'savings', 'BWP', 500), (1, '111111111', 'settlement', 'BWP', 0);
    ");

    [$bankPem, $bankKey] = make_cert('SACCUSSALIS-TEST', $caPem, $caKey, false);
    openssl_pkey_export($bankKey, $bankKeyPem);

    $env = array_merge(getenv(), [
        'VOUCHMORPH_CA_CERT_CONTENT' => $caPem, 'SACCUSSALIS_PRIVATE_KEY_CONTENT' => $bankKeyPem,
        'SACCUSSALIS_CERT_CONTENT' => $bankPem,
    ]);
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $log = tempnam(sys_get_temp_dir(), 'holdtest_log_');
    register_shutdown_function(static function () use ($log) { @unlink($log); });
    // One single-process server per address. The built-in server can take two connections and
    // serve them one after the other, so requests meant to run at the same moment each get a
    // server of their own (index 0 is the one used for everything else).
    // Two more run with the allow-list on: one where VouchMorph is not on it, one where it is.
    $basePort = 18000 + random_int(0, 900);
    $servers = [];
    $serverEnvs = array_merge(array_fill(0, 9, $env), [
        9  => array_merge($env, ['HOLD_ALLOWED_SIGNERS' => 'OTHER-BANK-TEST']),
        10 => array_merge($env, ['HOLD_ALLOWED_SIGNERS' => ' SOMEONE-ELSE , VOUCHMORPH-TEST ']),
    ]);
    foreach ($serverEnvs as $n => $serverEnv) {
        $p = $basePort + $n;
        $proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$p", '-t', dirname(__DIR__), dirname(__DIR__) . '/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes, dirname(__DIR__), $serverEnv
        );
        register_shutdown_function(static function () use ($proc) { proc_terminate($proc); });
        $servers[$n] = "http://127.0.0.1:$p";
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $p); $i++) usleep(100000);
    }
    $urls = array_map(static fn($base) => "$base/backend/api/v1/hold.php", array_slice($servers, 0, 9));
    $url = $urls[0];
    $listVmOff = $servers[9];   // HOLD_ALLOWED_SIGNERS=OTHER-BANK-TEST
    $listVmOn  = $servers[10];  // HOLD_ALLOWED_SIGNERS=" SOMEONE-ELSE , VOUCHMORPH-TEST "

    // POST a body to an endpoint on a given server.
    $postTo = static function (string $base, string $endpoint, array $body): array {
        $ch = curl_init("$base/backend/api/v1/$endpoint");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES)]);
        $raw = curl_exec($ch);
        return [curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string)$raw, true) ?? ['_raw' => $raw]];
    };

    $post = static function (array $body) use ($url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES)]);
        $raw = curl_exec($ch);
        return [curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string)$raw, true) ?? ['_raw' => $raw]];
    };
    $postRaw = static function (string $body, string $contentType) use ($url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType], CURLOPT_POSTFIELDS => $body]);
        $raw = curl_exec($ch);
        return [curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string)$raw, true) ?? ['_raw' => $raw]];
    };
    $state = static function () use ($pdo): string {
        return json_encode([
            $pdo->query("SELECT account_number, balance, held_balance FROM accounts ORDER BY account_id")->fetchAll(),
            $pdo->query("SELECT hold_reference, status, amount FROM financial_holds ORDER BY id")->fetchAll(),
            $pdo->query("SELECT count(*) AS settlements FROM settlements")->fetchAll(),
            $pdo->query("SELECT count(*) AS ledger FROM ledger_entries")->fetchAll(),
        ]);
    };
    $acct = static function (string $n) use ($pdo): array {
        $s = $pdo->prepare("SELECT balance::float AS balance, held_balance::float AS held FROM accounts WHERE account_number = ?");
        $s->execute([$n]);
        return $s->fetch();
    };
    $holdStatus = static function (string $ref) use ($pdo) {
        $s = $pdo->prepare("SELECT status FROM financial_holds WHERE hold_reference = ?");
        $s->execute([$ref]);
        return $s->fetchColumn();
    };
    $placeFor = static fn(string $ref, float $amt, string $acc = '9000000001') => [
        'action' => 'PLACE_HOLD', 'reference' => $ref, 'asset_type' => 'ACCOUNT', 'amount' => $amt, 'currency' => 'BWP',
        'source_identifier' => $acc, 'account_number' => $acc, 'expiry' => '2030-01-01 00:00:00',
        'source_institution' => 'SACCUSSALIS',
    ];
    // Every way of asking without a valid signature must be refused and leave the data as it was.
    $refusedUnchanged = static function (string $label, array $payload) use ($post, $state, $vmKey, $vmPem, $strangerKey, $strangerPem): void {
        $signed = vm_sign($payload, $vmKey, $vmPem);
        $attempts = [
            'unsigned'                 => $payload,
            'signed by a stranger'     => vm_sign($payload, $strangerKey, $strangerPem),
            'right cert, wrong key'    => vm_sign($payload, $strangerKey, $vmPem),
            'signature for other body' => array_merge($signed, ['tampered' => 'x']),
            'certificate only'         => array_merge($payload, ['certificate' => $vmPem]),
        ];
        foreach ($attempts as $how => $body) {
            $before = $state();
            [$code, $res] = $post($body);
            check("$label, $how: refused with 401 and JSON error",
                $code === 401 && ($res['status'] ?? '') === 'ERROR' && ($res['error'] ?? '') === 'UNAUTHORIZED' && !empty($res['message']),
                "HTTP $code " . json_encode($res));
            check("$label, $how: nothing changed", $state() === $before);
        }
    };

    // PLACE
    $refusedUnchanged('place', $placeFor('HOLD_E2E_A', 150.0));
    [$code, $res] = $post(vm_sign($placeFor('HOLD_E2E_A', 150.0), $vmKey, $vmPem));
    check('place, signed: hold placed', $code === 200 && ($res['hold_placed'] ?? false) === true && ($res['hold_reference'] ?? '') === 'HOLD_E2E_A', json_encode($res));
    check('place, signed: response is signed by the bank', !empty($res['signature']) && !empty($res['certificate']));
    check('place, signed: 150 reserved', $acct('9000000001') == ['balance' => 1000.0, 'held' => 150.0] && $holdStatus('HOLD_E2E_A') === 'ACTIVE');
    [$code, $res] = $post(vm_sign($placeFor('HOLD_E2E_A', 150.0), $vmKey, $vmPem));
    check('place, signed duplicate reference: refused, no second reservation', ($res['hold_placed'] ?? null) === false && $acct('9000000001')['held'] == 150.0);
    [$code, $res] = $post(vm_sign($placeFor('HOLD_E2E_TOO_MUCH', 900.0), $vmKey, $vmPem));
    check('place, signed but over available balance: refused', ($res['hold_placed'] ?? null) === false && $acct('9000000001')['held'] == 150.0, json_encode($res));

    // RELEASE
    $rel = ['action' => 'RELEASE_HOLD', 'hold_reference' => 'HOLD_E2E_A', 'reference' => 'HOLD_E2E_A', 'source_identifier' => '9000000001'];
    $refusedUnchanged('release', $rel);
    [$code, $res] = $post(vm_sign($rel, $vmKey, $vmPem));
    check('release, signed: released', $code === 200 && ($res['released'] ?? false) === true, json_encode($res));
    check('release, signed: reservation returned', $acct('9000000001') == ['balance' => 1000.0, 'held' => 0.0] && $holdStatus('HOLD_E2E_A') === 'RELEASED');

    // DEBIT
    $post(vm_sign($placeFor('HOLD_E2E_B', 200.0), $vmKey, $vmPem));
    $deb = ['action' => 'DEBIT_FUNDS', 'hold_reference' => 'HOLD_E2E_B', 'amount' => 200.0];
    $refusedUnchanged('debit', $deb);
    [$code, $res] = $post(vm_sign($deb, $vmKey, $vmPem));
    check('debit, signed: debited', $code === 200 && ($res['debited'] ?? false) === true, json_encode($res));
    check('debit, signed: balance reduced, reservation cleared', $acct('9000000001') == ['balance' => 800.0, 'held' => 0.0] && $holdStatus('HOLD_E2E_B') === 'DEBITED');

    // ODD INPUTS: none of these may change anything, and none without a signature may get past
    // the check (an empty body is turned away even earlier, as before).
    $before = $state();
    $odd = [
        'empty body'                  => ['', 'application/json'],
        'not JSON'                    => ['this is not json', 'application/json'],
        'JSON string'                 => ['"PLACE_HOLD"', 'application/json'],
        'JSON number'                 => ['42', 'application/json'],
        'JSON list'                   => ['[1,2,3]', 'application/json'],
        'unknown action, unsigned'    => [json_encode(['action' => 'STEAL', 'hold_reference' => 'HOLD_E2E_A']), 'application/json'],
        'form-encoded release'        => [http_build_query(['action' => 'RELEASE_HOLD', 'hold_reference' => 'HOLD_E2E_B']), 'application/x-www-form-urlencoded'],
        'form-encoded, fake signature' => [http_build_query(['action' => 'DEBIT', 'hold_reference' => 'HOLD_E2E_B', 'certificate' => $vmPem, 'signature' => 'AAAA']), 'application/x-www-form-urlencoded'],
    ];
    foreach ($odd as $name => [$body, $type]) {
        [$code, $res] = $postRaw($body, $type);
        // A body that is not a JSON object (and not a form) is turned away as empty, before the
        // check and before the database; everything else must meet the check.
        $turnedAwayAsEmpty = ($res['status'] ?? '') === 'ERROR' && ($res['message'] ?? '') === 'No input data received';
        $okShape = in_array($name, ['empty body', 'not JSON', 'JSON string', 'JSON number'], true)
            ? $turnedAwayAsEmpty
            : ($code === 401 && ($res['error'] ?? '') === 'UNAUTHORIZED');
        check("odd input, $name: refused", $okShape, "HTTP $code " . json_encode($res));
    }
    check('odd inputs: nothing changed', $state() === $before);
    [$code, $res] = $post(vm_sign(['action' => 'STEAL', 'hold_reference' => 'HOLD_E2E_B'], $vmKey, $vmPem));
    check('unknown action, signed: refused as unsupported, nothing changed',
        ($res['status'] ?? '') === 'ERROR' && strpos($res['message'] ?? '', 'Unsupported action') === 0 && $state() === $before, json_encode($res));

    // BAD AMOUNTS on place, correctly signed: refused, nothing reserved or freed.
    foreach (['negative' => -50.0, 'zero' => 0, 'not a number' => 'abc', 'thousands separator' => '1,000'] as $name => $amt) {
        $before = $state();
        $body = $placeFor("HOLD_E2E_AMT_" . md5($name), 1.0, '9000000001');
        $body['amount'] = $amt;
        [$code, $res] = $post(vm_sign($body, $vmKey, $vmPem));
        check("place, signed, $name amount: refused", ($res['hold_placed'] ?? null) === false, json_encode($res));
        check("place, signed, $name amount: nothing changed", $state() === $before);
    }

    // REPLAY: the very same signed body sent again does nothing the second time.
    $replayPlace = vm_sign($placeFor('HOLD_E2E_REPLAY', 40.0, '9000000001'), $vmKey, $vmPem);
    [, $first] = $post($replayPlace);
    $before = $state();
    [, $again] = $post($replayPlace);
    check('replay of a signed place: first placed, second refused, nothing changed',
        ($first['hold_placed'] ?? false) === true && ($again['hold_placed'] ?? null) === false && $state() === $before, json_encode($again));
    $replayRelease = vm_sign(['action' => 'RELEASE_HOLD', 'hold_reference' => 'HOLD_E2E_REPLAY'], $vmKey, $vmPem);
    [, $first] = $post($replayRelease);
    $before = $state();
    [, $again] = $post($replayRelease);
    check('replay of a signed release: first released, second refused, nothing changed',
        ($first['released'] ?? false) === true && ($again['released'] ?? null) === false && $state() === $before, json_encode($again));
    $before = $state();
    [, $again] = $post($replayPlace);
    check('replay of the place after its release: refused, nothing changed', ($again['hold_placed'] ?? null) === false && $state() === $before);
    $post(vm_sign($placeFor('HOLD_E2E_REPLAY_D', 60.0, '9000000001'), $vmKey, $vmPem));
    $replayDebit = vm_sign(['action' => 'DEBIT_FUNDS', 'hold_reference' => 'HOLD_E2E_REPLAY_D'], $vmKey, $vmPem);
    [, $first] = $post($replayDebit);
    $before = $state();
    [, $again] = $post($replayDebit);
    check('replay of a signed debit: first debited, second refused, nothing changed',
        ($first['debited'] ?? false) === true && ($again['debited'] ?? null) === false && $state() === $before, json_encode($again));
    check('after the replays: account 9000000001 is 740 with nothing held', $acct('9000000001') == ['balance' => 740.0, 'held' => 0.0], json_encode($acct('9000000001')));

    // AT THE SAME MOMENT, for real: the test itself locks the row the requests need, sends them
    // all, waits until every one of them is inside PostgreSQL waiting for that lock (so all have
    // already read the old balance or hold state), and only then lets them go. Whether they
    // arrive together is therefore not left to timing.
    $gated = static function (array $bodies, string $lockSql, array $lockParams) use ($urls, $host): array {
        $connect = static fn() => new PDO('pgsql:host=' . $host . ';port=' . (getenv('PGPORT') ?: '5432') . ';dbname=' . getenv('PGDATABASE'),
            (string)getenv('PGUSER'), (string)getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $locker = $connect();
        $locker->beginTransaction();
        $locker->prepare($lockSql)->execute($lockParams);
        // Watched from its own connection: inside a transaction pg_stat_activity does not change.
        $watcher = $connect();
        $waiting = $watcher->prepare("SELECT count(*) FROM pg_stat_activity
            WHERE datname = current_database() AND wait_event_type = 'Lock'");
        $mh = curl_multi_init();
        $handles = [];
        foreach (array_values($bodies) as $n => $body) {
            $ch = curl_init($urls[$n + 1]);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES)]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }
        $allWaiting = false;
        $seen = 0;
        $deadline = microtime(true) + 30;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.05);
            $waiting->execute();
            $now = (int)$waiting->fetchColumn();
            $seen = max($seen, $now);
            $allWaiting = $now === count($bodies);
        } while (!$allWaiting && $running > 0 && microtime(true) < $deadline);
        if (!$allWaiting) {
            fwrite(STDERR, "gate: saw at most $seen of " . count($bodies) . " requests waiting\n");
        }
        $locker->commit();
        do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.2); } while ($running > 0);
        $out = [];
        foreach ($handles as $ch) {
            $out[] = json_decode((string)curl_multi_getcontent($ch), true) ?? [];
        }
        return [$allWaiting, $out];
    };
    $count = static fn(array $responses, string $flag) => count(array_filter($responses, fn($r) => ($r[$flag] ?? false) === true));

    // Eight holds of 30 on an account holding 100, all past the balance read before any reserves.
    $bodies = [];
    for ($i = 0; $i < 8; $i++) {
        $bodies[] = vm_sign($placeFor("HOLD_E2E_RACE_$i", 30.0, '9000000002'), $vmKey, $vmPem);
    }
    [$together, $res] = $gated($bodies, "SELECT 1 FROM accounts WHERE account_number = ? FOR UPDATE", ['9000000002']);
    $placed = $count($res, 'hold_placed');
    $race = $acct('9000000002');
    check('simultaneous holds: all 8 were waiting at the same moment', $together);
    check("simultaneous holds: 3 of 8 placed (got $placed)", $placed === 3);
    check("simultaneous holds: reserved 90 of 100 (got {$race['held']})", $race['held'] == 90.0);
    $rows = (int)$pdo->query("SELECT count(*) FROM financial_holds WHERE hold_reference LIKE 'HOLD_E2E_RACE_%'")->fetchColumn();
    check("simultaneous holds: 3 hold rows written (got $rows)", $rows === 3);

    // Two holds with the same reference at the same moment: one hold, one reservation.
    $dup = vm_sign($placeFor('HOLD_E2E_SAME_REF', 50.0, '9000000003'), $vmKey, $vmPem);
    [$together, $res] = $gated([$dup, $dup], "SELECT 1 FROM accounts WHERE account_number = ? FOR UPDATE", ['9000000003']);
    $rows = (int)$pdo->query("SELECT count(*) FROM financial_holds WHERE hold_reference = 'HOLD_E2E_SAME_REF'")->fetchColumn();
    check('same reference twice at once: both were waiting at the same moment', $together);
    check('same reference twice at once: exactly one placed', $count($res, 'hold_placed') === 1, json_encode($res));
    check('same reference twice at once: one row, 50 reserved', $rows === 1 && $acct('9000000003')['held'] == 50.0, json_encode($acct('9000000003')));

    // Release and debit of the same hold at the same moment: exactly one of them happens.
    $post(vm_sign($placeFor('HOLD_E2E_REL_VS_DEB', 70.0, '9000000004'), $vmKey, $vmPem));
    [$together, $res] = $gated([
        vm_sign(['action' => 'RELEASE_HOLD', 'hold_reference' => 'HOLD_E2E_REL_VS_DEB'], $vmKey, $vmPem),
        vm_sign(['action' => 'DEBIT_FUNDS', 'hold_reference' => 'HOLD_E2E_REL_VS_DEB'], $vmKey, $vmPem),
    ], "SELECT 1 FROM financial_holds WHERE hold_reference = ? FOR UPDATE", ['HOLD_E2E_REL_VS_DEB']);
    $released = $count($res, 'released');
    $debited = $count($res, 'debited');
    $a4 = $acct('9000000004');
    $status = $holdStatus('HOLD_E2E_REL_VS_DEB');
    check('release and debit at once: both were waiting at the same moment', $together);
    check("release and debit at once: exactly one happened (released $released, debited $debited)", $released + $debited === 1);
    check('release and debit at once: balance matches what happened',
        ($status === 'RELEASED' && $a4 == ['balance' => 500.0, 'held' => 0.0]) || ($status === 'DEBITED' && $a4 == ['balance' => 430.0, 'held' => 0.0]),
        "$status " . json_encode($a4));

    // The same signed debit sent twice at the same moment (a replay racing the original).
    $post(vm_sign($placeFor('HOLD_E2E_DEB_TWICE', 80.0, '9000000004'), $vmKey, $vmPem));
    $balanceBefore = $acct('9000000004')['balance'];
    $twice = vm_sign(['action' => 'DEBIT_FUNDS', 'hold_reference' => 'HOLD_E2E_DEB_TWICE'], $vmKey, $vmPem);
    [$together, $res] = $gated([$twice, $twice], "SELECT 1 FROM financial_holds WHERE hold_reference = ? FOR UPDATE", ['HOLD_E2E_DEB_TWICE']);
    check('same debit twice at once: both were waiting at the same moment', $together);
    check('same debit twice at once: debited once', $count($res, 'debited') === 1 && $acct('9000000004') == ['balance' => $balanceBefore - 80.0, 'held' => 0.0], json_encode($acct('9000000004')));

    // PLACE WITHOUT A REFERENCE (decided 13 November 2026): refused, nothing changes, so a
    // replayed place can no longer reserve the money again.
    foreach (['missing' => null, 'empty' => '', 'not text' => ['x']] as $name => $ref) {
        $body = $placeFor('unused', 20.0, '9000000005');
        if ($ref === null) { unset($body['reference']); } else { $body['reference'] = $ref; }
        $signed = vm_sign($body, $vmKey, $vmPem);
        $before = $state();
        [$code, $res] = $post($signed);
        check("place, signed, reference $name: refused", ($res['hold_placed'] ?? null) === false && ($res['status'] ?? '') === 'ERROR', json_encode($res));
        [, $again] = $post($signed);
        check("place, signed, reference $name: nothing changed, also when sent again", $state() === $before && ($again['hold_placed'] ?? null) === false);
    }

    // WHO MAY CALL hold.php (HOLD_ALLOWED_SIGNERS, decided 13 November 2026).
    $notAllowed = static fn(array $r, int $code) => $code === 403 && ($r['status'] ?? '') === 'ERROR'
        && ($r['error'] ?? '') === 'FORBIDDEN' && ($r['message'] ?? '') === 'Caller not allowed';
    // Off (not set): another scheme member may place and release, as before.
    [$code, $res] = $postTo($servers[0], 'hold.php', vm_sign($placeFor('HOLD_E2E_MEMBER_OFF', 10.0, '9000000005'), $memberKey, $memberPem, 'OTHER-BANK'));
    check('allow-list off, hold.php: another scheme member places a hold (as before)', ($res['hold_placed'] ?? false) === true, json_encode($res));
    [$code, $res] = $postTo($servers[0], 'hold.php', vm_sign(['action' => 'RELEASE_HOLD', 'hold_reference' => 'HOLD_E2E_MEMBER_OFF'], $memberKey, $memberPem, 'OTHER-BANK'));
    check('allow-list off, hold.php: and releases it', ($res['released'] ?? false) === true, json_encode($res));
    // On, VouchMorph not on it: every action from VouchMorph is refused and changes nothing.
    $post(vm_sign($placeFor('HOLD_E2E_LISTED', 25.0, '9000000005'), $vmKey, $vmPem));
    foreach ([
        'place'   => $placeFor('HOLD_E2E_NOT_LISTED', 25.0, '9000000005'),
        'release' => ['action' => 'RELEASE_HOLD', 'hold_reference' => 'HOLD_E2E_LISTED'],
        'debit'   => ['action' => 'DEBIT_FUNDS', 'hold_reference' => 'HOLD_E2E_LISTED'],
    ] as $what => $body) {
        $before = $state();
        [$code, $res] = $postTo($listVmOff, 'hold.php', vm_sign($body, $vmKey, $vmPem));
        check("allow-list on without VouchMorph, hold.php $what: 403 not allowed", $notAllowed($res, $code), "HTTP $code " . json_encode($res));
        check("allow-list on without VouchMorph, hold.php $what: says only that it is not allowed",
            !isset($res['hold_reference']) && !isset($res['amount']) && strpos(json_encode($res), 'OTHER-BANK-TEST') === false);
        check("allow-list on without VouchMorph, hold.php $what: nothing changed", $state() === $before);
    }
    [$code, $res] = $postTo($listVmOff, 'hold.php', $placeFor('HOLD_E2E_NOT_LISTED', 25.0, '9000000005'));
    check('allow-list on, hold.php unsigned: still 401, not 403', $code === 401 && ($res['error'] ?? '') === 'UNAUTHORIZED');
    [$code, $res] = $postTo($listVmOff, 'hold.php', vm_sign($placeFor('HOLD_E2E_MEMBER_ON', 5.0, '9000000005'), $memberKey, $memberPem, 'OTHER-BANK'));
    check('allow-list on, hold.php: the listed member is accepted', ($res['hold_placed'] ?? false) === true, json_encode($res));
    // On, VouchMorph on it (names trimmed): VouchMorph works, the other member is refused.
    [$code, $res] = $postTo($listVmOn, 'hold.php', vm_sign(['action' => 'RELEASE_HOLD', 'hold_reference' => 'HOLD_E2E_LISTED'], $vmKey, $vmPem));
    check('allow-list on with VouchMorph, hold.php: VouchMorph releases', $code === 200 && ($res['released'] ?? false) === true, json_encode($res));
    $before = $state();
    [$code, $res] = $postTo($listVmOn, 'hold.php', vm_sign(['action' => 'RELEASE_HOLD', 'hold_reference' => 'HOLD_E2E_MEMBER_ON'], $memberKey, $memberPem, 'OTHER-BANK'));
    check('allow-list on with VouchMorph, hold.php: the other member is 403 and nothing changed', $notAllowed($res, $code) && $state() === $before, json_encode($res));

    // notify_debit.php: the same check; for a correctly signed, allowed request nothing else changed.
    $nd = static fn(string $ref, float $amt) => ['hold_reference' => $ref, 'amount' => $amt,
        'transaction_reference' => "TX_$ref", 'source_institution' => 'SACCUSSALIS'];
    $post(vm_sign($placeFor('HOLD_E2E_ND_1', 30.0, '9000000005'), $vmKey, $vmPem));
    $post(vm_sign($placeFor('HOLD_E2E_ND_2', 40.0, '9000000005'), $vmKey, $vmPem));
    $before = $state();
    [$code, $res] = $postTo($servers[0], 'notify_debit.php', $nd('HOLD_E2E_ND_1', 30.0));
    check('notify_debit.php unsigned: refused, nothing changed', ($res['debited'] ?? null) === false && $state() === $before, json_encode($res));
    [$code, $res] = $postTo($listVmOff, 'notify_debit.php', vm_sign($nd('HOLD_E2E_ND_1', 30.0), $vmKey, $vmPem));
    check('allow-list on without VouchMorph, notify_debit.php: 403 not allowed',
        $code === 403 && ($res['debited'] ?? null) === false && ($res['message'] ?? '') === 'Caller not allowed', "HTTP $code " . json_encode($res));
    check('allow-list on without VouchMorph, notify_debit.php: nothing changed', $state() === $before);
    $a5 = $acct('9000000005');
    [$code, $res] = $postTo($servers[0], 'notify_debit.php', vm_sign($nd('HOLD_E2E_ND_1', 30.0), $vmKey, $vmPem));
    check('allow-list off, notify_debit.php: debited (as before)', $code === 200 && ($res['debited'] ?? false) === true
        && !empty($res['signature']) && ($res['hold_reference'] ?? '') === 'HOLD_E2E_ND_1', json_encode($res));
    check('allow-list off, notify_debit.php: 30 left the account, settlement credited',
        $acct('9000000005') == ['balance' => $a5['balance'] - 30.0, 'held' => $a5['held'] - 30.0] && $acct('111111111')['balance'] == 30.0
        && $holdStatus('HOLD_E2E_ND_1') === 'DEBITED');
    $before = $state();
    [$code, $res] = $postTo($listVmOn, 'notify_debit.php', vm_sign($nd('HOLD_E2E_ND_2', 40.0), $memberKey, $memberPem, 'OTHER-BANK'));
    check('allow-list on with VouchMorph, notify_debit.php: the other member is 403, nothing changed',
        $code === 403 && ($res['message'] ?? '') === 'Caller not allowed' && $state() === $before, json_encode($res));
    $ndPin = $nd('HOLD_E2E_ND_2', 40.0) + ['pin' => '5824-MADE-UP-PIN'];
    $ndSigned = vm_sign($ndPin, $vmKey, $vmPem);
    [$code, $res] = $postTo($listVmOn, 'notify_debit.php', $ndSigned);
    check('allow-list on with VouchMorph, notify_debit.php: debited', $code === 200 && ($res['debited'] ?? false) === true
        && $holdStatus('HOLD_E2E_ND_2') === 'DEBITED' && $acct('111111111')['balance'] == 70.0, json_encode($res));
    // A failing call, so the error path's logging is exercised too.
    $postTo($servers[0], 'notify_debit.php', vm_sign($nd('HOLD_E2E_ND_2', 40.0) + ['pin' => '5824-MADE-UP-PIN'], $vmKey, $vmPem));

    // NOTHING SECRET IN THE LOG: a made-up PIN, the request signature and certificates.
    $pinBody = $placeFor('HOLD_E2E_LOG', 5.0, '9000000003');
    $pinBody['pin'] = '7319-MADE-UP-PIN';
    $pinSigned = vm_sign($pinBody, $vmKey, $vmPem);
    $post($pinSigned);
    $post(array_merge($pinSigned, ['signature' => base64_encode('forged-for-the-log-test')]));
    usleep(300000);
    $logText = (string)file_get_contents($log);
    check('log: has entries from hold.php (the scan below is meaningful)', strpos($logText, 'HOLD: request received') !== false);
    check('log: has entries from notify_debit.php (the scan below covers it too)', strpos($logText, 'NOTIFY_DEBIT.PHP RECEIVED') !== false
        && strpos($logText, 'notify_debit.php ERROR') !== false);
    check('log: no PIN', strpos($logText, '7319-MADE-UP-PIN') === false && strpos($logText, '5824-MADE-UP-PIN') === false);
    check('log: no request signature', strpos($logText, $pinSigned['signature']) === false && strpos($logText, $ndSigned['signature']) === false
        && strpos($logText, base64_encode('forged-for-the-log-test')) === false);
    check('log: no certificate or key', strpos($logText, 'BEGIN CERTIFICATE') === false && strpos($logText, 'PRIVATE KEY') === false);
} else {
    echo "(Part 2 skipped: set HOLD_E2E=1 with a local throwaway database to run it)\n";
}

echo $failed === 0 ? "ALL PASSED\n" : "$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
