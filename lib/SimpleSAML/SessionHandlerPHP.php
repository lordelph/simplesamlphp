<?php


/**
 * This file is part of SimpleSAMLphp. See the file COPYING in the root of the distribution for licence information.
 *
 * This file defines a session handler which uses the default php session handler for storage.
 *
 * @author Olav Morken, UNINETT AS. <andreas.solberg@uninett.no>
 * @package SimpleSAMLphp
 */
class SimpleSAML_SessionHandlerPHP extends SimpleSAML_SessionHandler
{

    /**
     * This variable contains the session cookie name.
     *
     * @var string
     */
    protected $cookie_name;

    /**
     * An associative array containing the details of a session existing previously to creating or loading one with this
     * session handler. The keys of the array will be:
     *
     *   - id: the ID of the session, as returned by session_id().
     *   - name: the name of the session, as returned by session_name().
     *   - cookie_params: the parameters of the session cookie, as returned by session_get_cookie_params().
     *
     * @var array
     */
    private $previous_session = array();


    /**
     * Initialize the PHP session handling. This constructor is protected because it should only be called from
     * SimpleSAML_SessionHandler::createSessionHandler(...).
     */
    protected function __construct()
    {
        // call the parent constructor in case it should become necessary in the future
        parent::__construct();

        $config = SimpleSAML_Configuration::getInstance();
        $this->cookie_name = $config->getString('session.phpsession.cookiename', null);

        if (function_exists('session_status') && defined('PHP_SESSION_ACTIVE')) { // PHP >= 5.4
            $previous_session = session_status() === PHP_SESSION_ACTIVE;
        } else {
            $previous_session = (session_id() !== '') && (session_name() !== $this->cookie_name);
        }

        if ($previous_session) {
            if (session_name() === $this->cookie_name || $this->cookie_name === null) {
                SimpleSAML_Logger::warning(
                    'There is already a PHP session with the same name as SimpleSAMLphp\'s session, or the '.
                    "'session.phpsession.cookiename' configuration option is not set. Make sure to set ".
                    "SimpleSAMLphp's cookie name with a value not used by any other applications."
                );
            }

            /*
             * We shouldn't have a session at this point, so it might be an application session. Save the details to
             * retrieve it later and commit.
             */
            $this->previous_session['cookie_params'] = session_get_cookie_params();
            $this->previous_session['id'] = session_id();
            $this->previous_session['name'] = session_name();
            session_write_close();
        }

        if (!empty($this->cookie_name)) {
            session_name($this->cookie_name);
        } else {
            $this->cookie_name = session_name();
        }

        $params = $this->getCookieParams();

        if (!headers_sent()) {
            if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
                session_set_cookie_params([
                    'lifetime' => $params['lifetime'],
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'],
                ]);
            } else {
                session_set_cookie_params(
                    $params['lifetime'],
                    $params['path'],
                    $params['domain'] ?? '',
                    $params['secure'],
                    $params['httponly']
                );
            }
        }

        $savepath = $config->getString('session.phpsession.savepath', null);
        if (!empty($savepath)) {
            session_save_path($savepath);
        }
    }


    /**
     * This method starts a session, making sure no warnings are generated due to headers being already sent.
     */
    private function sessionStart()
    {
        $cacheLimiter = session_cache_limiter();
        if (headers_sent()) {
            /*
             * session_start() tries to send HTTP headers depending on the configuration, according to the
             * documentation:
             *
             *      http://php.net/manual/en/function.session-start.php
             *
             * If headers have been already sent, it will then trigger an error since no more headers can be sent.
             * Being unable to send headers does not mean we cannot recover the session by calling session_start(),
             * so we still want to call it. In this case, though, we want to avoid session_start() to send any
             * headers at all so that no error is generated, so we clear the cache limiter temporarily (no headers
             * sent then) and restore it after successfully starting the session.
             */
            session_cache_limiter('');
        }
        @session_start();
        session_cache_limiter($cacheLimiter);
    }


    /**
     * Restore a previously-existing session.
     *
     * Use this method to restore a previous PHP session existing before SimpleSAMLphp initialized its own session.
     *
     * WARNING: do not use this method directly, unless you know what you are doing. Calling this method directly,
     * outside of SimpleSAML_Session, could cause SimpleSAMLphp's session to be lost or mess the application's one. The
     * session must always be saved properly before calling this method. If you don't understand what this is about,
     * don't use this method.
     */
    public function restorePrevious()
    {
        if (empty($this->previous_session)) {
            return; // nothing to do here
        }

        // close our own session
        session_write_close();

        session_name($this->previous_session['name']);
        session_set_cookie_params(
            $this->previous_session['cookie_params']['lifetime'],
            $this->previous_session['cookie_params']['path'],
            $this->previous_session['cookie_params']['domain'],
            $this->previous_session['cookie_params']['secure'],
            $this->previous_session['cookie_params']['httponly']
        );
        session_id($this->previous_session['id']);
        $this->previous_session = array();
        $this->sessionStart();

        /*
         * At this point, we have restored a previously-existing session, so we can't continue to use our session here.
         * Therefore, we need to load our session again in case we need it. We remove this handler from the parent
         * class so that the handler is initialized again if we ever need to do something with the session.
         */
        parent::$sessionHandler = null;
    }


    /**
     * Create a new session id.
     *
     * @return string The new session id.
     */
    public function newSessionId()
    {
        // generate new (secure) session id
        $sessionId = bin2hex(openssl_random_pseudo_bytes(16));
        SimpleSAML_Session::createSession($sessionId);

        return $sessionId;
    }


    /**
     * Retrieve the session ID saved in the session cookie, if there's one.
     *
     * @return string|null The session id saved in the cookie or null if no session cookie was set.
     *
     * @throws SimpleSAML_Error_Exception If the cookie is marked as secure but we are not using HTTPS.
     */
    public function getCookieSessionId()
    {
        if (!self::hasSessionCookie()) {
            return null; // there's no session cookie, can't return ID
        }

        // do not rely on session_id() as it can return the ID of a previous session. Get it from the cookie instead.
        session_id($_COOKIE[$this->cookie_name]);

        $session_cookie_params = session_get_cookie_params();

        if ($session_cookie_params['secure'] && !\SimpleSAML\Utils\HTTP::isHTTPS()) {
            throw new SimpleSAML_Error_Exception('Session start with secure cookie not allowed on http.');
        }

        $this->sessionStart();
        return session_id();
    }


    /**
     * Retrieve the session cookie name.
     *
     * @return string The session cookie name.
     */
    public function getSessionCookieName()
    {
        return $this->cookie_name;
    }


    /**
     * Save the current session to the PHP session array.
     *
     * @param SimpleSAML_Session $session The session object we should save.
     */
    public function saveSession(SimpleSAML_Session $session)
    {
        $_SESSION['SimpleSAMLphp_SESSION'] = serialize($session);
    }


    /**
     * Load the session from the PHP session array.
     *
     * @param string|null $sessionId The ID of the session we should load, or null to use the default.
     *
     * @return SimpleSAML_Session|null The session object, or null if it doesn't exist.
     *
     * @throws SimpleSAML_Error_Exception If it wasn't possible to disable session cookies or we are trying to load a
     * PHP session with a specific identifier and it doesn't match with the current session identifier.
     */
    public function loadSession($sessionId = null)
    {
        assert('is_string($sessionId) || is_null($sessionId)');

        if ($sessionId !== null) {
            if (session_id() === '') {
                // session not initiated with getCookieSessionId(), start session without setting cookie
                $ret = ini_set('session.use_cookies', '0');
                if ($ret === false) {
                    throw new SimpleSAML_Error_Exception('Disabling PHP option session.use_cookies failed.');
                }

                session_id($sessionId);
                $this->sessionStart();
            } elseif ($sessionId !== session_id()) {
                throw new SimpleSAML_Error_Exception('Cannot load PHP session with a specific ID.');
            }
        } elseif (session_id() === '') {
            self::getCookieSessionId();
        }

        if (!isset($_SESSION['SimpleSAMLphp_SESSION'])) {
            return null;
        }

        $session = $_SESSION['SimpleSAMLphp_SESSION'];
        assert('is_string($session)');

        $session = unserialize($session);

        return ($session !== false) ? $session : null;
    }


    /**
     * Check whether the session cookie is set.
     *
     * This function will only return false if is is certain that the cookie isn't set.
     *
     * @return boolean True if it was set, false otherwise.
     */
    public function hasSessionCookie()
    {
        return array_key_exists($this->cookie_name, $_COOKIE);
    }


    /**
     * Get the cookie parameters that should be used for session cookies.
     *
     * This function contains some adjustments from the default to provide backwards-compatibility.
     *
     * @return array The cookie parameters for our sessions.
     * @link http://www.php.net/manual/en/function.session-get-cookie-params.php
     *
     * @throws SimpleSAML_Error_Exception If both 'session.phpsession.limitedpath' and 'session.cookie.path' options
     * are set at the same time in the configuration.
     */
    public function getCookieParams()
    {
        $config = SimpleSAML_Configuration::getInstance();

        $ret = parent::getCookieParams();

        if ($config->hasValue('session.phpsession.limitedpath') && $config->hasValue('session.cookie.path')) {
            throw new SimpleSAML_Error_Exception(
                'You cannot set both the session.phpsession.limitedpath and session.cookie.path options.'
            );
        } elseif ($config->hasValue('session.phpsession.limitedpath')) {
            $ret['path'] = $config->getBoolean(
                'session.phpsession.limitedpath',
                false
            ) ? '/'.$config->getBaseURL() : '/';
        }

        $ret['httponly'] = $config->getBoolean('session.phpsession.httponly', true);

        if (version_compare(PHP_VERSION, '7.3.0', '<')) {
            // in older versions of PHP we need a nasty hack to set RFC6265bis SameSite attribute
            if ($ret['samesite'] !== null and !preg_match('/;\s+samesite/i', $ret['path'])) {
                $ret['path'] .= '; SameSite=' . $ret['samesite'];
            }
        }

        return $ret;
    }


    /**
     * Set a session cookie.
     *
     * @param string      $sessionName The name of the session.
     * @param string|null $sessionID The session ID to use. Set to null to delete the cookie.
     * @param array|null  $cookieParams Additional parameters to use for the session cookie.
     *
     * @throws \SimpleSAML\Error\CannotSetCookie If we can't set the cookie.
     */
    public function setCookie($sessionName, $sessionID, array $cookieParams = null)
    {
        if ($cookieParams === null) {
            $cookieParams = session_get_cookie_params();
        }

        if ($cookieParams['secure'] && !\SimpleSAML\Utils\HTTP::isHTTPS()) {
            throw new \SimpleSAML\Error\CannotSetCookie(
                'Setting secure cookie on plain HTTP is not allowed.',
                \SimpleSAML\Error\CannotSetCookie::SECURE_COOKIE
            );
        }

        if (headers_sent()) {
            throw new \SimpleSAML\Error\CannotSetCookie(
                'Headers already sent.',
                \SimpleSAML\Error\CannotSetCookie::HEADERS_SENT
            );
        }

        if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
            /** @psalm-suppress InvalidArgument */
            session_set_cookie_params($cookieParams);
        } else {
            session_set_cookie_params(
                $cookieParams['lifetime'],
                $cookieParams['path'],
                $cookieParams['domain'] ?? '',
                $cookieParams['secure'],
                $cookieParams['httponly']
            );
        }

        if (session_id() !== '') {
            // session already started, close it
            session_write_close();
        }

        session_id($sessionID);
        $this->sessionStart();
    }
}
