<?php
##
## Copyright 2013-2017 Opera Software AS
## Modifications Copyright 2021 Leitwerk AG
##
## Licensed under the Apache License, Version 2.0 (the "License");
## you may not use this file except in compliance with the License.
## You may obtain a copy of the License at
##
## http://www.apache.org/licenses/LICENSE-2.0
##
## Unless required by applicable law or agreed to in writing, software
## distributed under the License is distributed on an "AS IS" BASIS,
## WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
## See the License for the specific language governing permissions and
## limitations under the License.
##

class AuthService {
    private $ldap;
    private $user_dir;
    private $config;
    
    public function __construct($ldap, $user_dir, $config) {
        $this->ldap = $ldap;
        $this->user_dir = $user_dir;
        $this->config = $config;
        
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            // Configure secure session settings
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_strict_mode', 1);
            ini_set('session.cookie_samesite', 'Strict');
            
            // Set secure cookie if using HTTPS
            if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
                ini_set('session.cookie_secure', 1);
            }
            
            session_start();
        }
    }
    
    /**
     * What the person logging in is told, per failure reason. Reasons that take a parameter
     * (missing_attribute, internal_error) build their text in refuse*() below.
     */
    private const FAILURE_MESSAGES = [
        'invalid_credentials' => 'Invalid username or password.',
        'directory_unavailable' => 'The directory server could not be reached, so logins are currently unavailable. Please try again later or contact an administrator.',
        'directory_config' => 'SSH Key Authority cannot use the directory because of a configuration problem. Please contact an administrator.',
        'not_in_user_tree' => 'Your password is correct, but your account was not found in the part of the directory that SSH Key Authority is configured to use. Please contact an administrator.',
        'account_disabled_local' => 'Your account has been disabled in SSH Key Authority. Please contact an administrator.',
        'directory_account_disabled' => 'Your directory account is disabled. Please contact an administrator.',
        'directory_account_expired' => 'Your directory account has expired. Please contact an administrator.',
        'password_expired' => 'Your directory password has expired. Change your password, then log in again.',
        'password_must_change' => 'Your directory account requires a password change before it can be used. Change your password, then log in again.',
        'logon_hours' => 'Your directory account is not permitted to log in at this time of day.',
        'logon_workstation' => 'Your directory account is restricted to specific workstations and cannot be used to log in here. Please contact an administrator.',
    ];

    /**
     * Active Directory attaches a sub-code to a refused bind. The ones listed here are only ever sent
     * when the password was correct and the account itself is the problem, so we can tell the user what
     * to do. Every other sub-code is treated as plain wrong credentials. That includes "user not found"
     * (525) and "account locked out" (775): AD reports a lockout no matter what password was sent, so
     * showing it would confirm to anybody that the account exists.
     */
    private const AD_SUBCODE_REASONS = [
        '530' => 'logon_hours',
        '531' => 'logon_workstation',
        '532' => 'password_expired',
        '533' => 'directory_account_disabled',
        '701' => 'directory_account_expired',
        '773' => 'password_must_change',
    ];

    /**
     * LDAP result codes of a refused bind that describe the connection or the request, never the
     * account: 2 protocolError, 7 authMethodNotSupported, 8 strongerAuthRequired (e.g. the server
     * demands signing/TLS), 13 confidentialityRequired. Only these are reported as a configuration
     * problem. Any other refusal may depend on which name was tried, so telling the user about it
     * before the password is verified would reveal whether an account exists.
     */
    private const DIRECTORY_LEVEL_BIND_REFUSALS = [2, 7, 8, 13];

    /**
     * Authenticate user with LDAP credentials and start their session.
     *
     * Every way this can fail is reported as a LoginFailedException whose message may be shown to the
     * person logging in. Failures that happen before the password has been verified deliberately stay
     * vague ("Invalid username or password") so the login form cannot be used to find out which
     * accounts exist. Once the directory has accepted the password we say exactly what is wrong.
     *
     * @param string $username
     * @param string $password
     * @return User the authenticated user
     * @throws LoginFailedException if the user could not be logged in
     */
    public function authenticate($username, #[\SensitiveParameter] $password) {
        $masked = substr($username, 0, 3) . "***";
        error_log("Authentication attempt for user: $masked");

        try {
            $verified_dn = $this->verifyPassword($username, $password, $masked);
            $user = $this->loadVerifiedUser($username, $verified_dn, $masked);

            // Check if user is disabled by local override (force_disable flag)
            if ($user->force_disable) {
                throw $this->refuse('account_disabled_local');
            }
            // Check if user is active (from LDAP or local setting)
            if (!$user->active) {
                throw $this->refuse('directory_account_disabled');
            }
            $user->update();
        } catch (Throwable $e) {
            $failure = $this->toLoginFailure($e, $masked);
            $cause = $e === $failure ? '' : ' (' . get_class($e) . ': ' . $e->getMessage() . ')';
            error_log("Login refused for user $masked: {$failure->reason}$cause");
            throw $failure;
        }

        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        // Set session data
        $_SESSION['user_id'] = $user->id;
        $_SESSION['username'] = $user->uid;
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();

        error_log("Authentication successful for user: $masked");
        return $user;
    }

    /**
     * Check the password against the directory.
     *
     * The entry for the username is looked up first (LDAP::find_users_by_id) and the bind is then made
     * with that entry's DN. Binding with a name derived from the username instead (UPN,
     * "CN=<username>,...") would prove a password for whichever account the directory resolves that
     * name to, which is not necessarily the account with this user_id.
     *
     * A username that has no entry, or more than one, is refused exactly like a wrong password. No bind
     * is attempted for it, so the form cannot be used to test passwords of accounts outside dn_user.
     *
     * @return string DN of the entry the directory accepted the password for
     * @throws LoginFailedException
     */
    private function verifyPassword($username, #[\SensitiveParameter] $password, $masked) {
        // An empty password would be an "unauthenticated bind", which directories accept without
        // checking anything. A NUL byte cannot be part of a password and is rejected by the LDAP library.
        if ($password === '' || strpos($password, "\0") !== false) {
            throw $this->refuse('invalid_credentials');
        }

        $entries = $this->ldap->find_users_by_id($username, array('dn'));
        if (count($entries) !== 1) {
            // Logged for the administrator: this is why a correct password does not work for this user
            error_log("Found " . count($entries) . " directory entries for user $masked under dn_user (need exactly one); refusing login");
            throw $this->refuse('invalid_credentials');
        }
        $dn = $entries[0]['dn'];
        if (!$this->bindAs($dn, $password, $masked)) {
            throw $this->refuse('invalid_credentials');
        }
        return $dn;
    }

    /**
     * Try to bind to the directory with the given identity and password.
     *
     * @return bool true if the directory accepted the credentials, false if it did not (wrong password,
     *         unknown or unusable name - deliberately not distinguished)
     * @throws LoginFailedException if the password is right but the account cannot be used (expired
     *         password, disabled account, ...), or if the directory could not answer the question at all
     */
    private function bindAs($bind_dn, #[\SensitiveParameter] $password, $masked) {
        $ldap_config = $this->config['ldap'];
        $user_ldap = new LDAP(
            $ldap_config['host'],
            $ldap_config['starttls'],
            $bind_dn,
            $password,
            array(LDAP_OPT_PROTOCOL_VERSION => 3, LDAP_OPT_REFERRALS => !empty($ldap_config['follow_referrals']))
        );
        try {
            $user_ldap->connect();
        } catch (LDAPBindFailedException $e) {
            error_log("LDAP bind refused for user $masked: " . $e->getMessage());
            if ($e->ldap_errno === 49) {
                $reason = self::AD_SUBCODE_REASONS[$e->get_ad_subcode()] ?? null;
                if ($reason !== null) {
                    throw $this->refuse($reason);
                }
            } elseif (in_array($e->ldap_errno, self::DIRECTORY_LEVEL_BIND_REFUSALS, true)) {
                // The directory refuses to check passwords this way at all, whoever asks
                throw $this->refuse('directory_config');
            }
            // Wrong credentials, or a refusal that depends on the account we tried (for example a server
            // reporting a locked account with its own code). Nothing has been proven, so this must look
            // exactly like a wrong password.
            return false;
        }
        error_log("LDAP bind successful for user: $masked");
        return true;
    }

    /**
     * Load the user whose password has just been verified, with details fresh from LDAP, and make sure
     * those details come from the entry the password was verified for.
     *
     * @return User
     * @throws LoginFailedException
     */
    private function loadVerifiedUser($username, $verified_dn, $masked) {
        // Looks the user up in our database; a first-time user is fetched from LDAP and stored
        $user = $this->user_dir->get_user_by_uid($username);
        if ($user->get_ldap_dn() === null) {
            $user->get_details_from_ldap();
        }
        if (strcasecmp($user->get_ldap_dn(), $verified_dn) !== 0) {
            // The directory changed between the two lookups; do not log in as a different entry
            error_log("Details of user $masked were loaded from a different directory entry than the password was verified for; refusing login");
            throw $this->refuse('directory_config');
        }
        return $user;
    }

    /**
     * @param string $reason key of FAILURE_MESSAGES
     * @return LoginFailedException
     */
    private function refuse($reason) {
        // Only wrong credentials count towards the failed-attempt limit
        return new LoginFailedException($reason, self::FAILURE_MESSAGES[$reason], $reason === 'invalid_credentials');
    }

    /**
     * Turn anything that went wrong during authenticate() into the failure to report to the user.
     * The user's own bind is dealt with in bindAs(), so LDAP exceptions arriving here come from
     * SSH Key Authority's own connection to the directory.
     *
     * @return LoginFailedException
     */
    private function toLoginFailure(Throwable $e, $masked) {
        if ($e instanceof LoginFailedException) {
            return $e;
        }
        if ($e instanceof UserLDAPAttributeMissingException) {
            if (strtolower($e->attribute) === strtolower($this->config['ldap']['user_email'])) {
                $message = "Your password is correct, but your directory account has no email address (attribute \"{$e->attribute}\"). SSH Key Authority requires one. Ask your directory administrator to add it, then log in again.";
            } else {
                $message = "Your password is correct, but your directory account has no value for the \"{$e->attribute}\" attribute, which SSH Key Authority requires. Ask your directory administrator to add it, then log in again.";
            }
            return new LoginFailedException('missing_attribute', $message);
        }
        if ($e instanceof UserNotFoundException) {
            return $this->refuse('not_in_user_tree');
        }
        if ($e instanceof LDAPServerUnavailableException) {
            return $this->refuse('directory_unavailable');
        }
        if ($e instanceof LDAPConnectionFailureException || $e instanceof LDAPSearchFailedException) {
            return $this->refuse('directory_config');
        }
        // Database failure or a bug. Log everything under a reference the user can pass on.
        $reference = log_exception($e, "Login of user $masked failed");
        return new LoginFailedException('internal_error', "Login failed because of an internal error (reference $reference). Please contact an administrator.");
    }
    
    /**
     * Check if user is currently authenticated
     * @return bool
     */
    public function isAuthenticated() {
        if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
            return false;
        }
        
        // Get session timeout from config (default: 8 hours = 28800 seconds)
        $session_timeout = isset($this->config['security']['session_timeout']) 
            ? (int)$this->config['security']['session_timeout'] 
            : 28800;
        
        // Check session timeout (0 means no timeout)
        if ($session_timeout > 0 && isset($_SESSION['last_activity']) && 
            (time() - $_SESSION['last_activity']) > $session_timeout) {
            $this->logout();
            return false;
        }
        
        // Update last activity
        $_SESSION['last_activity'] = time();
        
        return true;
    }
    
    /**
     * Get the currently authenticated user
     * @return User|null
     */
    public function getCurrentUser() {
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        try {
            $user = $this->user_dir->get_user_by_id($_SESSION['user_id']);
            
            // Check if user is disabled by local override (force_disable flag)
            if ($user->force_disable) {
                error_log("User " . substr($user->uid, 0, 3) . "*** is disabled by local override (force_disable flag) - logging out");
                $this->logout();
                return null;
            }
            
            // Check if user is active
            if (!$user->active) {
                error_log("User " . substr($user->uid, 0, 3) . "*** is inactive - logging out");
                $this->logout();
                return null;
            }
            
            return $user;
        } catch (Exception $e) {
            $this->logout();
            return null;
        }
    }
    
    /**
     * Logout the current user
     */
    public function logout() {
        // Clear session data
        session_unset();
        session_destroy();
        
        // Start a new session for any new requests
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Require authentication - redirect to login if not authenticated
     */
    public function requireAuth() {
        if (!$this->isAuthenticated()) {
            // Store the current URL to redirect back after login
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            redirect('/login');
        }
    }
}

/**
 * A login attempt that was refused. The message is written for the person trying to log in and may
 * be shown to them as is.
 */
class LoginFailedException extends Exception {
    /**
     * Short machine-readable identifier of the cause, used in the log
     */
    public $reason;
    /**
     * True if this was a wrong username/password and should count towards the failed-attempt limit.
     * False for failures the user cannot fix by typing something else (outage, incomplete account, ...).
     */
    public $counts_as_attempt;

    public function __construct($reason, $message, $counts_as_attempt = false) {
        parent::__construct($message);
        $this->reason = $reason;
        $this->counts_as_attempt = $counts_as_attempt;
    }
}
