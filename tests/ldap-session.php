<?php
/** Standalone LDAP session regression tests using local protocol doubles and real session files. */
declare(strict_types=1);

namespace RRZE\RSVP {
    /** Supplies only the LDAP configuration needed by the production adapter. */
    final class Settings
    {
        /** @param string $file Plugin entry point, unused by this isolated fixture. */
        public function __construct(string $file) {}

        /** @param string $section Settings group. @param string $key Option name. @return mixed Synthetic LDAP configuration. */
        public function getOption(string $section, string $key): mixed
        {
            return [
                'server' => 'ldap://directory.example.invalid',
                'port' => 389,
                'distinguished_name' => '',
                'bind_base_dn' => 'example.invalid',
                'search_base_dn' => 'DC=example,DC=invalid',
            ][$key] ?? '';
        }
    }

    /** Minimal plugin path fixture. */
    final class SessionTestPlugin
    {
        /** @return string Plugin entry point; no WordPress bootstrap is performed. */
        public function getFile(): string { return dirname(__DIR__) . '/rrze-rsvp.php'; }
    }

    /** @return SessionTestPlugin Isolated plugin fixture. */
    function plugin(): SessionTestPlugin { return new SessionTestPlugin(); }
}

namespace RRZE\RSVP\Auth {
    /** Records the mocked LDAP protocol without opening a socket. */
    final class SessionTestDirectory
    {
        public static bool $allowBind = true;
        public static array $binds = [];
        public static int $connections = 0;
        public static int $searches = 0;
    }

    /** @param string $server Synthetic host. @param int $port Synthetic port. @return object Local connection marker. */
    function ldap_connect(string $server, int $port): object
    {
        SessionTestDirectory::$connections++;
        return (object) ['server' => $server, 'port' => $port];
    }

    /** @param object $connection Local marker. @param int $option Protocol option. @param mixed $value Option value. @return bool Accepted. */
    function ldap_set_option(object $connection, int $option, mixed $value): bool { return true; }

    /** @param object $connection Local marker. @param string $user Submitted directory identity. @param string $password Synthetic secret. @return bool Configured bind result. */
    function ldap_bind(object $connection, string $user, string $password): bool
    {
        SessionTestDirectory::$binds[] = [$user, $password];
        return SessionTestDirectory::$allowBind;
    }

    /** @param object $connection Local marker. @param string $base Search base. @param string $filter User query. @return object Local result marker. */
    function ldap_search(object $connection, string $base, string $filter): object
    {
        SessionTestDirectory::$searches++;
        return (object) ['base' => $base, 'filter' => $filter];
    }

    /** @param object $connection Local marker. @param object $result Local result marker. @return array Synthetic directory entry. */
    function ldap_get_entries(object $connection, object $result): array
    {
        return ['count' => 1, 0 => ['cn' => ['Synthetic Participant'], 'mail' => ['participant@example.invalid']]];
    }

    /** @param object $connection Local marker. @return bool Closed without any network operation. */
    function ldap_close(object $connection): bool { return true; }

    /** @param object $connection Local marker. @return int LDAP invalid-credentials error code. */
    function ldap_errno(object $connection): int { return 49; }

    /** @param object $connection Local marker. @return string Synthetic diagnostic. */
    function ldap_error(object $connection): string { return 'Synthetic bind denied'; }
}

namespace {
    use RRZE\RSVP\Auth\LDAP;
    use RRZE\RSVP\Auth\SessionTestDirectory;

    define('ABSPATH', dirname(__DIR__) . '/');
    define('MINUTE_IN_SECONDS', 60);
    if (!defined('LDAP_OPT_PROTOCOL_VERSION')) define('LDAP_OPT_PROTOCOL_VERSION', 17);
    if (!defined('LDAP_OPT_REFERRALS')) define('LDAP_OPT_REFERRALS', 8);

    /** @param string $text Source text. @param string $domain Text domain. @return string Untranslated fixture text. */
    function __(string $text, string $domain = ''): string { return $text; }

    /** @param mixed $text Submitted fixture value. @return string Plain text matching the adapter's boundary. */
    function sanitize_text_field(mixed $text): string { return trim(strip_tags((string) $text)); }

    /** @param string $hook Event name. @param mixed ...$args Event payload. @return void Record diagnostics without external logging. */
    function do_action(string $hook, mixed ...$args): void { $GLOBALS['session_test_events'][] = [$hook, $args]; }

    /** @param bool $condition Expected invariant. @param string $message Failure description. @return void */
    function sessionTestAssert(bool $condition, string $message): void
    {
        $GLOBALS['session_test_assertions']++;
        if (!$condition) throw new RuntimeException($message);
    }

    /**
     * Simulate a subsequent HTTP request by discarding PHP's in-memory session.
     * Reading the same real file is what detects writes made after write_close().
     *
     * @param string $id Synthetic session ID.
     * @return array Persisted values, with the session lock released again.
     */
    function reopenSession(string $id): array
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        unset($_SESSION);
        session_name('rrze_rsvp');
        session_id($id);
        session_start();
        $data = $_SESSION;
        session_write_close();
        return $data;
    }

    require_once dirname(__DIR__) . '/includes/Auth/Auth.php';
    require_once dirname(__DIR__) . '/includes/Auth/LDAP.php';

    $GLOBALS['session_test_assertions'] = 0;
    $GLOBALS['session_test_events'] = [];
    $directory = sys_get_temp_dir() . '/rrze-rsvp-ldap-session-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Could not create isolated session directory.');
    $oldPath = session_save_path($directory);
    $oldCookies = ini_set('session.use_cookies', '0');
    $oldCacheLimiter = session_cache_limiter('');
    $id = 'rsvp-test-' . bin2hex(random_bytes(8));
    $failure = null;

    try {
        session_id($id);
        unset($_SESSION);
        $_GET = [];
        $_POST = ['username' => 'participant', 'password' => 'synthetic-password'];
        $_COOKIE = [];
        $_SERVER['REQUEST_TIME'] = time();

        $ldap = new LDAP();
        sessionTestAssert(!$ldap->isAuthenticated(), 'A fresh session must be anonymous.');
        sessionTestAssert(isset($_SESSION), 'The constructor must leave the in-memory session array available.');
        sessionTestAssert(session_status() === PHP_SESSION_NONE, 'The constructor must release its session lock.');

        $ldap->login();
        sessionTestAssert($ldap->isAuthenticated(), 'Successful LDAP bind must authenticate in this request.');
        sessionTestAssert(session_status() === PHP_SESSION_NONE, 'Login must close the session it opened.');
        sessionTestAssert(SessionTestDirectory::$binds === [['participant@example.invalid', 'synthetic-password']], 'The fixture must exercise the production bind path.');
        $persisted = reopenSession($id);
        sessionTestAssert(($persisted['ldap_uid'] ?? '') === 'participant', 'The login UID must survive the redirect boundary.');
        sessionTestAssert(($persisted['ldap_mail'] ?? '') === 'participant@example.invalid', 'The login email must survive the redirect boundary.');

        $returned = new LDAP();
        sessionTestAssert($returned->isAuthenticated(), 'A new adapter must read the persisted identity.');
        sessionTestAssert($returned->getCustomerData() === ['customer_email' => 'participant@example.invalid'], 'Customer data must use the directory email.');
        $returned->getCustomerData();
        sessionTestAssert($returned->isAuthenticated(), 'Reading customer data repeatedly must not log out.');
        sessionTestAssert(reopenSession($id) === $persisted, 'Reading customer data must not consume persisted identity.');

        session_start();
        $_SESSION['unrelated_fixture'] = 'preserved';
        session_write_close();
        $_COOKIE = ['rrze_rsvp' => $id, 'rze_rsvp' => $id, 'Srrze_rsvp' => $id];
        $returned->logout();
        sessionTestAssert(!$returned->isAuthenticated(), 'Logout must clear identity in the current adapter.');
        sessionTestAssert($_COOKIE === [], 'Logout must clear the supported session cookie variants.');
        sessionTestAssert(reopenSession($id) === ['unrelated_fixture' => 'preserved'], 'Logout must persist identity removal while retaining unrelated session data.');

        session_start();
        $active = new LDAP();
        $active->login();
        sessionTestAssert(session_status() === PHP_SESSION_ACTIVE, 'Login must preserve a session owned by the caller.');
        sessionTestAssert((reopenSession($id)['ldap_uid'] ?? '') === 'participant', 'Caller-owned sessions must persist when the caller closes them.');
        session_start();
        $active->logout();
        sessionTestAssert(session_status() === PHP_SESSION_ACTIVE, 'Logout must preserve a session owned by the caller.');
        sessionTestAssert(reopenSession($id) === ['unrelated_fixture' => 'preserved'], 'Logout in an active session must persist identity removal.');

        SessionTestDirectory::$allowBind = false;
        $denied = new LDAP();
        $denied->login();
        sessionTestAssert(!$denied->isAuthenticated(), 'A denied bind must remain anonymous.');
        sessionTestAssert(reopenSession($id) === ['unrelated_fixture' => 'preserved'], 'A failed login must not persist an identity.');
        sessionTestAssert(count($GLOBALS['session_test_events']) === 1, 'A denied bind must report one local diagnostic.');
        sessionTestAssert(SessionTestDirectory::$connections === 3 && SessionTestDirectory::$searches === 2, 'All login attempts must use the local LDAP doubles.');
    } catch (Throwable $error) {
        $failure = $error;
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        unset($_SESSION);
        foreach (glob($directory . '/sess_*') ?: [] as $file) unlink($file);
        rmdir($directory);
        session_save_path($oldPath);
        ini_set('session.use_cookies', $oldCookies);
        session_cache_limiter($oldCacheLimiter);
    }

    if ($failure) {
        fwrite(STDERR, 'LDAP session regression failed: ' . $failure->getMessage() . PHP_EOL);
        exit(1);
    }
    echo 'LDAP session regressions passed (' . $GLOBALS['session_test_assertions'] . ' assertions; no network).' . PHP_EOL;
}
