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
     * Authenticate user with LDAP credentials
     * @param string $username
     * @param string $password
     * @return array|false Returns user data on success, false on failure
     */
    public function authenticate($username, $password) {
        // Try multiple authentication methods for Active Directory
        $auth_methods = [];
        
        // Extract domain from the dn_user configuration
        $dn_user = $this->config['ldap']['dn_user'];
        
        // Method 1: UPN format (username@domain.com)
        if (preg_match_all('/dc=([^,]+)/i', $dn_user, $matches)) {
            $domain_parts = $matches[1];
            $domain = implode('.', $domain_parts);
            $auth_methods[] = ['method' => 'UPN', 'bind_dn' => $username . '@' . $domain];
        }
        
        // Method 2: Standard AD DN format (CN=username,CN=Users,DC=domain,DC=com)
        $ad_dn = 'CN=' . LDAP::escape($username) . ',' . str_replace('cn=', 'CN=', str_replace('dc=', 'DC=', $dn_user));
        $auth_methods[] = ['method' => 'DN', 'bind_dn' => $ad_dn];
        
        // Method 3: Original format from config (sAMAccountName=username,cn=Users,dc=domain,dc=com)
        $original_dn = $this->config['ldap']['user_id'] . '=' . LDAP::escape($username) . ',' . $dn_user;
        $auth_methods[] = ['method' => 'CONFIG', 'bind_dn' => $original_dn];
        
        error_log("Authentication attempt for user: " . substr($username, 0, 3) . "***");
        
        foreach ($auth_methods as $method) {
            try {
                error_log("Trying {$method['method']} format for user: " . substr($username, 0, 3) . "***");
                
                // Create a temporary LDAP connection for user authentication
                $temp_ldap = new LDAP(
                    $this->config['ldap']['host'],
                    $this->config['ldap']['starttls'],
                    $method['bind_dn'],
                    $password,
                    array(LDAP_OPT_PROTOCOL_VERSION => 3, LDAP_OPT_REFERRALS => !empty($this->config['ldap']['follow_referrals']))
                );
                
                // Try to connect and bind - this will fail if credentials are wrong
                $temp_ldap->connect();
                error_log("LDAP bind successful for user: " . substr($username, 0, 3) . "*** using {$method['method']} format");
                
                // If we get here, authentication was successful
                // Now get the user from our database
                try {
                    $user = $this->user_dir->get_user_by_uid($username);
                    error_log("User found in database: " . substr($user->uid, 0, 3) . "***");
                    
                    // Update user details from LDAP
                    $user->get_details_from_ldap();
                    
                    // Check if user is disabled by local override (force_disable flag)
                    if ($user->force_disable) {
                        error_log("User " . substr($username, 0, 3) . "*** is disabled by local override (force_disable flag)");
                        return false;
                    }
                    
                    // Check if user is active (from LDAP or local setting)
                    if (!$user->active) {
                        error_log("User " . substr($username, 0, 3) . "*** is inactive in database");
                        return false;
                    }
                    
                    $user->update();
                    
                    // Regenerate session ID to prevent session fixation
                    session_regenerate_id(true);
                    
                    // Set session data
                    $_SESSION['user_id'] = $user->id;
                    $_SESSION['username'] = $user->uid;
                    $_SESSION['authenticated'] = true;
                    $_SESSION['last_activity'] = time();
                    
                    error_log("Authentication successful for user: " . substr($username, 0, 3) . "***");
                    return $user;
                    
                } catch (Exception $e) {
                    // User not found in database, but LDAP auth succeeded
                    // This could happen if the user exists in LDAP but not in our system yet
                    error_log("User " . substr($username, 0, 3) . "*** authenticated in LDAP but not found in database: " . $e->getMessage());
                    return false;
                }
                
            } catch (Exception $e) {
                // This authentication method failed, try the next one
                error_log("Authentication failed for user " . substr($username, 0, 3) . "*** using {$method['method']} format: " . $e->getMessage());
                continue;
            }
        }
        
        // All authentication methods failed
        error_log("All authentication methods failed for user: " . substr($username, 0, 3) . "***");
        return false;
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
            $_SESSION['redirect_after_login'] = $this->sanitize_redirect_path($_SERVER['REQUEST_URI'] ?? '/');
            redirect('/login');
        }
    }

    private function sanitize_redirect_path($candidate) {
        if(!is_string($candidate) || $candidate === '') {
            return '/';
        }
        $parts = parse_url($candidate);
        if($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return '/';
        }
        $path = $parts['path'] ?? '/';
        if($path === '' || substr($path, 0, 1) !== '/' || substr($path, 0, 2) === '//') {
            return '/';
        }
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';
        return $path.$query.$fragment;
    }
}
