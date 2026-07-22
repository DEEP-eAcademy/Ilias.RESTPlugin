<?php
/**
 * ILIAS REST Plugin for the ILIAS LMS
 *
 * Authors: D.Schaefer, T.Hufschmidt <(schaefer|hufschmidt)@hrz.uni-marburg.de>
 * Since 2014
 */
namespace RESTController\libs;

// This allows us to use shortcuts instead of full quantifier
// Requires <$ilDB>
use ILIAS\DI\Container;
use ILIAS\Data\Version;
use \RESTController\core\oauth2_v2 as Auth;
use ilLoggerFactory;

/**
 * Class: RESTilias
 *  This class provides some common utility functions in regards to
 *  fetching information from ILIAS but do not directly fit into any model.
 */
class RESTilias
{
    // Allow to re-use status messages and codes
    const MSG_NO_OBJECT_BY_REF = 'Could not find any ILIAS-Object with Reference-Id \'{{ref_id}}\' in database.';
    const ID_NO_OBJECT_BY_REF = 'RESTController\\libs\\RESTilias::ID_NO_OBJECT_BY_REF';
    const MSG_NO_OBJECT_BY_OBJ = 'Could not find any ILIAS-Object with Object-Id \'{{obj_id}}\' in database.';
    const ID_NO_OBJECT_BY_OBJ = 'RESTController\\libs\\RESTilias::ID_NO_OBJECT_BY_OBJ';

    const MSG_NO_USER_BY_ID = 'Could not find any user with id \'{{id}}\' in database.';
    const ID_NO_USER_BY_ID = 'RESTController\\libs\\RESTilias::ID_NO_USER_BY_ID';
    const MSG_NO_USER_BY_NAME = 'Could not find any user with name \'{{name}}\' in database.';
    const ID_NO_USER_BY_NAME = 'RESTController\\libs\\RESTilias::ID_NO_USER_BY_NAME';

    const MSG_RBAC_WRITE_DENIED = 'Permission to create or modify {{object}} denied by RBAC-System.';
    const ID_RBAC_WRITE_DENIED = 'RESTController\\libs\\RESTilias::ID_RBAC_WRITE_DENIED';
    const MSG_RBAC_READ_DENIED = 'Permission to read {{object}} denied by RBAC-System.';
    const ID_RBAC_READ_DENIED = 'RESTController\\libs\\RESTilias::ID_RBAC_READ_DENIED';
    const MSG_RBAC_DELETE_DENIED = 'Permission to delete {{object}} denied by RBAC-System.';
    const ID_RBAC_DELETE_DENIED = 'RESTController\\libs\\RESTilias::ID_RBAC_DELETE_DENIED';


    // ILIAS-Admin must have this role (id) assigned to them
    const RBCA_ADMIN_ID = 2;


    // Some ILIAS 10 components were renamed (not just moved) while restructuring
    // Services/<Name> and Modules/<Name> into components/ILIAS/<Name>.
    const ILIAS10_COMPONENT_RENAMES = array(
        'Object' => 'ILIASObject',
    );

    // Cached, absolute path to the root of the ILIAS installation (the
    // directory that directly contains 'ilias.ini.php'), lazily resolved
    // by getIliasRoot().
    protected static $iliasRoot = null;


    /**
     * Function: getIliasRoot()
     *  Locate the absolute path of the ILIAS installation root, independent
     *  of the current working directory. This is required because ILIAS 10
     *  introduced a 'public/' webroot: the directory that contains
     *  'Customizing/' (and thus this plugin) is no longer necessarily the
     *  same directory that contains 'ilias.ini.php', 'vendor/' and
     *  'components/' (ILIAS 9 and earlier: they are the same directory).
     *
     *  Resolved by walking up the directory tree starting at this file's
     *  own location until 'ilias.ini.php' is found.
     *
     * Return:
     *  <String> - Absolute filesystem path to the ILIAS root (no trailing slash)
     */
    public static function getIliasRoot()
    {
        if (self::$iliasRoot !== null) {
            return self::$iliasRoot;
        }

        $dir = __DIR__;
        for ($i = 0; $i < 20; $i++) {
            if (is_file($dir . '/ilias.ini.php')) {
                self::$iliasRoot = $dir;

                // ILIAS 10's own core classes (eg. ilInitialisation.php itself
                // requires ILIAS\DI\Container to be defined at top-level) are
                // only autoloadable once ILIAS's own Composer autoloader has
                // run. ILIAS 9 does not ship/need this.
                $autoloader = $dir . '/vendor/composer/vendor/autoload.php';
                if (is_file($autoloader)) {
                    require_once($autoloader);
                }

                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException(
            'Could not locate the ILIAS installation root (ilias.ini.php) above ' . __DIR__
        );
    }


    /**
     * Function: includeIlias($legacyPath)
     *  Require an ILIAS core class-file given its legacy (ILIAS <= 9) relative
     *  path, eg. 'Services/Init/classes/class.ilInitialisation.php' or
     *  'Modules/File/classes/class.ilObjFile.php'.
     *
     *  ILIAS 10 dropped the 'Services'/'Modules' type-segment and moved
     *  (some also renamed) everything into 'components/ILIAS/<Name>/...'.
     *  This resolves and requires whichever layout is actually present,
     *  so the same plugin code-base keeps working on both ILIAS 9 and 10.
     *
     * Parameters:
     *  $legacyPath <String> - ILIAS 9-style relative path (leading './' optional)
     */
    public static function includeIlias($legacyPath)
    {
        $legacyPath = preg_replace('#^\./#', '', $legacyPath, 1);
        $root = self::getIliasRoot();

        // ILIAS 9 and earlier: Services/Modules live directly at the ILIAS root
        $candidate = $root . '/' . $legacyPath;
        if (is_file($candidate)) {
            require_once($candidate);
            return;
        }

        // ILIAS 10+: Services/Modules type-segment dropped, some components
        // renamed, everything moved under components/ILIAS/
        if (preg_match('#^(?:Services|Modules)/([^/]+)/(.+)$#', $legacyPath, $matches)) {
            $component = isset(self::ILIAS10_COMPONENT_RENAMES[$matches[1]])
                ? self::ILIAS10_COMPONENT_RENAMES[$matches[1]]
                : $matches[1];
            $candidate = $root . '/components/ILIAS/' . $component . '/' . $matches[2];
            if (is_file($candidate)) {
                require_once($candidate);
                return;
            }
        }

        throw new \RuntimeException(
            "Could not locate required ILIAS class file for '$legacyPath' (checked ILIAS 9 and ILIAS 10 layouts under $root)."
        );
    }


    /**
     * Function: includeCustomizingPlugin($relativePath)
     *  Require a file belonging to some *other* plugin under
     *  'Customizing/global/plugins/...', given its path relative to that
     *  directory (eg. 'Services/Repository/RepositoryObject/Learnplaces/classes/bootstrap.php').
     *
     *  Like includeIlias(), this exists because ILIAS 10 introduced a
     *  'public/' webroot: 'Customizing/' now lives under 'public/Customizing/'
     *  relative to the ILIAS root, whereas on ILIAS 9 it lives directly at
     *  the ILIAS root.
     *
     * Parameters:
     *  $relativePath <String> - Path relative to 'Customizing/global/plugins/' (leading './' optional)
     */
    public static function includeCustomizingPlugin($relativePath)
    {
        $relativePath = preg_replace('#^\./#', '', $relativePath, 1);
        $root = self::getIliasRoot();

        foreach (array('Customizing/global/plugins/', 'public/Customizing/global/plugins/') as $prefix) {
            $candidate = $root . '/' . $prefix . $relativePath;
            if (is_file($candidate)) {
                require_once($candidate);
                return;
            }
        }

        throw new \RuntimeException(
            "Could not locate required plugin file for '$relativePath' (checked ILIAS 9 and ILIAS 10 layouts under $root)."
        );
    }


    /**
     * Function: getPluginRelativeDirectory($plugin)
     *  Returns the plugin's own directory as a path relative to the ILIAS
     *  webroot, suitable for building URLs. ILIAS 10's ilPlugin::getDirectory()
     *  now returns an absolute filesystem path (it used to return a path
     *  relative to the ILIAS root), while the new getRelativeDirectory()
     *  provides the webroot-relative path we actually need.
     *
     * Parameters:
     *  $plugin <ilPlugin> - Plugin object, as returned by self::getPlugin()
     *
     * Return:
     *  <String> - Path of the plugin's directory, relative to the webroot
     */
    public static function getPluginRelativeDirectory($plugin)
    {
        if (method_exists($plugin, 'getRelativeDirectory')) {
            return $plugin->getRelativeDirectory();
        }
        return str_replace('./', '', $plugin->getDirectory());
    }


    /**
     * Function: applyOAuth2Fix()
     *  The term 'client_id' is used twice within this context:
     *   (1) ilias client_id                 [Will be ilias_client_id and client_id]
     *   (2) oauth2 client_id (RFC 6749)     [Will be api_key]
     *  In order to solve the conflict for the variable 'client_id'
     *  some counter measures are necessary.
     *
     * Solution:
     *  It is required to provide the variable ilias_client_id
     *  if a specific ilias client needs to be adressed.
     */
    protected static function applyOAuth2Fix($client = null)
    {
        // *_client_id was set via GET
        if (isset($_GET['client_id']) || isset($_GET['ilias_client_id'])) {
            // oAuth2: Set api_key to client_id
            $_GET['api_key'] = $_GET['client_id'];

            // ILIAS: Set client_id to ilias_client_id
            if (isset($_GET['ilias_client_id'])) {
                $_GET['client_id'] = $_GET['ilias_client_id'];
            }
        }
        // *_client_id was set via GET
        elseif (isset($_POST['client_id']) || isset($_POST['ilias_client_id'])) {
            // oAuth2: Set api_key to client_id
            $_POST['api_key'] = $_POST['client_id'];

            // ILIAS: Set client_id to ilias_client_id
            // Note: ILIAS only cares about GET
            if (isset($_POST['ilias_client_id'])) {
                $_GET['client_id'] = $_POST['ilias_client_id'];
            }
        }

        // Given client parameter always overwrites given POST, GET values!
        if (is_string($client)) {
            $_GET['client_id'] = $client;
        }

        if (!isset($_GET['client_id'])) {
            // Read default client (ContextRest does not do this since 5.2)
            $_GET['client_id'] = self::getIniDefaultClientId();
        }
    }


    /**
     * Function: getIniHost()
     *  Return the [server] -> 'http_path' variable from 'ilias.init.php'.
     */
    protected static function getIniHost()
    {
        // Create ini-handler (onces)
        $ilIliasIniFile = new \ilIniFile(self::getIliasRoot() . '/ilias.ini.php');
        $ilIliasIniFile->read();

        // Return [server] -> 'http_path' variable from 'ilias.init.php'
        $http_path = $ilIliasIniFile->readVariable('server', 'http_path');

        // Strip http:// & https://
        if (strpos($http_path, 'https://') !== false) {
            $http_path = substr($http_path, 8);
        }
        if (strpos($http_path, 'http://') !== false) {
            $http_path = substr($http_path, 7);
        }

        // Return clean host
        return $http_path;
    }

    /**
     * Read and return the default client-ID from the ilias.ini file
     * @return string
     */
    protected static function getIniDefaultClientId()
    {
        self::includeIlias('Services/Init/classes/class.ilIniFile.php');
        $ini = new \ilIniFile(self::getIliasRoot() . '/ilias.ini.php');
        $ini->read();
        return $ini->readVariable("clients", "default");
    }

    /**
     * Function: initILIAS()
     *  This class will initialize ILIAS just like when calling ilias/index.php.
     *  It does some extra-work to make sure ILIAS does not get any wrong idea
     *  when having 'unpredicted' values in $_SERVER array.
     */
    public static function initILIAS($client = null)
    {
        // Apply oAuth2 fix for client_id GET/POST value
        self::applyOAuth2Fix($client);

        // ILIAS 5.2 needs a client-id in $_GET before initialization.
        // Use the default client ID defined in the INI file, if client-ID was not determined at this point.
        if (!isset($_GET['client_id'])) {
            $_GET['client_id'] = self::getIniDefaultClientId();
        }

        // Required included to initialize ILIAS
        self::includeIlias('Services/Context/classes/class.ilContext.php');
        self::includeIlias('Services/Init/classes/class.ilInitialisation.php');

        // Set ILIAS Context. This should tell ILIAS what to load and what not
        \ilContext::init(\ilContext::CONTEXT_REST);

        // Remember original values
        $_ORG_SERVER = array(
      'HTTP_HOST' => $_SERVER['HTTP_HOST'],
      'REQUEST_URI' => $_SERVER['REQUEST_URI'],
      'PHP_SELF' => $_SERVER['PHP_SELF'],
    );

        // Overwrite $_SERVER entries which would confuse ILIAS during initialisation
        $_SERVER['REQUEST_URI'] = '';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['HTTP_HOST'] = self::getIniHost();

        // Initialise ILIAS
        \ilInitialisation::initILIAS();
        header_remove('Set-Cookie');

        // Restore original, since this could lead to bad side-effects otherwise
        $_SERVER['HTTP_HOST'] = $_ORG_SERVER['HTTP_HOST'];
        $_SERVER['REQUEST_URI'] = $_ORG_SERVER['REQUEST_URI'];
        $_SERVER['PHP_SELF'] = $_ORG_SERVER['PHP_SELF'];
    }


    /**
     * Function: FetchILIASClient()
     *  Returns the current ILIAS Client-ID. This cannot be changed
     *  and can only be controlled by setting $_GET['ilias_client_id']
     *  (see RESTController.php) or via $_COOKIE['client_id'] (See ilInitialize)
     *
     * Return:
     *  <String> - ILIAS Client-ID (fixed)
     */
    public static function FetchILIASClient()
    {
        return CLIENT_ID;
    }


    /**
     * Function: getPlugin()
     *  Returns RESTPlugin plugin object.
     *
     * Return:
     *  <ilComponent> - ILIAS Plugin-Object representing the RESTPlugin
     */
    public static function getPlugin()
    {
        // ILIAS 10 replaced ilPluginAdmin::getPluginObject(...) (and the
        // IL_COMP_SERVICE constant it required) with ilComponentFactory,
        // available via the DIC. Fall back to the legacy API on ILIAS 9.
        global $DIC, $ilPluginAdmin;
        if (isset($DIC['component.factory'])) {
            return $DIC['component.factory']->getPlugin('rest');
        }
        return $ilPluginAdmin->getPluginObject(IL_COMP_SERVICE, 'UIComponent', 'uihk', 'REST');
    }


    /**
     * Function: initAccessHandling()
     *  Load ILIAS user-management. Normally this would be handled by initILIAS(),
     *  but CONTEXT:REST (intentionally) returns hasUser()->false. (Which causes
     *  initAccessHandling() [and authentification] to be skipped)
     *
     * @see ilInitialisation::initAccessHandling()
     */
    public static function initAccessHandling()
    {
        return ilInitialisation::initAccessHandling();
    }
    public static function initGlobal($a, $b, $c)
    {
        ilInitialisation::initGlobal($a, $b, $c);
    }


    /**
     * Function: loadIlUser($userId)
     *  Load ilObjUser for given user and attach to global $ilUser and $ilias->account.
     *  If no user-id is given, fetch the it from access-token.
     *
     * Parameters:
     *  $userId <Integer> - [Optional] Provider the user (id) who should be loader into ilUser.
     *                      Leave blank to load user from available access-token.
     *                      Important: Without $userId, requires the access-token to have been loaded.
     *
     * Returns:
     *  <ilObjUser> - Global ilUser object use by ILIAS
     */
    public static function loadIlUser($userId = null)
    {
        global $DIC; 
        if (isset($DIC["ilUser"])) {
            return $DIC["ilUser"];
        }

        // Fetch user-id from access-token if non is given
        if (!isset($userId)) {
            $app = \RESTController\RESTController::getInstance();
            $accessToken = $app->request()->getToken();
            $userId = $accessToken->getUserId();
            //$app->getLog()->debug('userId = '.$userId);
        }

        // Create user-object if id is given
        $user = new \ilObjUser($userId);
        $DIC["ilUser"] = $user;

        // Initialize access-handling and attach account
        self::initAccessHandling();
        global $ilias;
        $ilias->account = $user;

        // Return $ilUser (reference)
        return $user;
    }


    /**
     * Function: authenticate($username, $password)
     *  Authentication via the ILIAS Auth mechanisms and is used as backend for OAuth2.
     *
     * Note: This code was extracted and refactored from ilSoapUserAdministration->login(...)
     *       because ILIAS does not distinguish between authentication-logic and further login code-flow...
     *
     * Parameters:
     *  $username - ILIAS username to check
     *  $password - ILIS password of user to check
     *
     * Return:
     *  <Boolean> - True if authentication was successfull, false otherwise
     */
    public static function authenticate($username, $password)
    {
        return self::authenticate_52($username, $password);
    }

    public static function authenticate_52($username, $password)
    {
        // Required for LDAP authentication (and others?), because ILIAS forces
        // updates to a users role EACH TIME credentials are validated successfully...
        global $DIC;
        $user = new \ilObjUser(self::getUserId($username));
        $DIC["ilUser"] = $user;

        // Initialize access-handling and attach account
        self::initAccessHandling();

        // Create new authentication credentials object
        $credentials = new \ilAuthFrontendCredentials();
        $credentials->setUsername($username);
        $credentials->setPassword($password);

        // Create new authentication provider factory and fetch all
        $provider_factory = new \ilAuthProviderFactory();
        $providers = $provider_factory->getProviders($credentials);

        // Fetch single authentication status object to me
        $status = \ilAuthStatus::getInstance();

        // Check credentials with all authentication providers
        foreach ($providers as $provider) {
            // Reset status every time, just to be save
            $status->setStatus(\ilAuthStatus::STATUS_UNDEFINED);
            $status->setReason('');
            $status->setAuthenticatedUserId(0);

            // Forward authentication to provider which returns true/false and also modifies the status-parameter
            $provider->doAuthentication($status);

            // Check if authentication was successfull
            if ($status->getStatus() === \ilAuthStatus::STATUS_AUTHENTICATED) {
                return true;
            }
        }

        // Seems like no provider could authenticate given username/password
        return false;
    }


    /**
     * Function: checkSession($userId, $token, $sessionId)
     *  Checks if the given parameters correspond to a valid (and active) ILIAS session.
     *
     * Parameters:
     *  $userId <Integer> - ILIAS user-id attached to the session
     *  $token <String> - ILIAS request-token attached to the session
     *  $sessionId <String> - ILIAS php-session id attached to the session
     *
     * Return:
     *  <Boolean> - True if there is an valid (and active) ILIAS session for the given parameters, false otherwise
     */
    public static function checkSession($userId, $token, $sessionId)
    {
        /**
         * @var Container $container
         */
        $container = $GLOBALS["DIC"];
        $database = $container->database();
        $userId = intval($userId);

        // Check if token and session-id are correct (il_request_token)
        $sql = RESTDatabase::safeSQL(
            '
      SELECT * FROM il_request_token WHERE user_id = %d AND token = %s AND session_id = %s',
            $userId,
            $token,
            $sessionId
    );
        $query = $database->query($sql);
        if ($database->numRows($query) == 0) {
            return false;
        }

        // Check if session-id is correct (usr_session)
        $sql = RESTDatabase::safeSQL(
            '
      SELECT * FROM usr_session WHERE user_id = %d AND session_id = %s',
            $userId,
            $sessionId
    );
        $query = $database->query($sql);
        $row = $database->fetchAssoc($query);
        if (!isset($row) || $row['expires'] <= time()) {
            return false;
        }

        // Not failed yet, must be valid then...
        return true;
    }


    /**
     * Function: deleteSession($userId, $token, $sessionId)
     *  Deletes all (important) session-data from the database (does not effect user-agent cookies)
     *  this invalidating the given session.
     *
     * Parameters:
     *  $userId <Integer> - ILIAS user-id attached to the session
     *  $token <String> - [Optiona] ILIAS request-token attached to the session
     *  $sessionId <String> - [Optiona] ILIAS php-session id attached to the session
     */
    public static function deleteSession($userId, $token = null, $sessionId = null)
    {
        /**
         * @var Container $container
         */
        $container = $GLOBALS["DIC"];
        $database = $container->database();

        $userId = intval($userId);

        // Delete from il_request_token
        $equals = array();
        $equals[] = sprintf('user_id    = %d', RESTDatabase::quote($userId, 'Integer'));
        if (isset($token)) {
            $equals[] = sprintf('token      = %s', RESTDatabase::quote($token, 'Text'));
        }
        if (isset($sessionId)) {
            $equals[] = sprintf('session_id = %s', RESTDatabase::quote($sessionId, 'Text'));
        }
        $where = implode(' AND ', $equals);
        $sql = sprintf('DELETE FROM il_request_token WHERE %s', $where);
        $database->manipulate($sql);

        // Delete from usr_session
        $equals = array();
        $equals[] = sprintf('user_id    = %d', RESTDatabase::quote($userId, 'Integer'));
        if (isset($sessionId)) {
            $equals[] = sprintf('session_id = %s', RESTDatabase::quote($sessionId, 'Text'));
        }
        $where = implode(' AND ', $equals);
        $sql = sprintf('DELETE FROM usr_session WHERE %s', $where);
        $database->manipulate($sql);
    }


    /**
     * Function: createSession($userName)
     *  Creates a new session on the ILIAS side and returns the required cookie-data
     *  to attach the user-agent to this session.
     *
     * Parameters:
     *  $userName <String> - ILIAS username for whom to create a new session
     *
     * Return:
     *  <Array[
     *   key <String>     - Name of session-cookie
     *   value <String>   - Value of session-cookie
     *   expires <Number> - Expirition-date of session-cookie
     *   path <String>    - Path of session-cookie
     *  ]> - Information about session (-cookie)
     */
    public static function createSession($userId)
    {
        // fetch username (makes also sure user exists)
        $userName = self::getUserName($userId);

        // Delete old sessions
        self::deleteSession($userId);

        // Iitialize Authorization in ILIAS
        self::includeIlias('Services/Authentication/classes/class.ilAuthUtils.php');
        \ilAuthUtils::_initAuth();

        // Authenticate user (this will generate a session in usr_session and send PHPSESSID cookie)
        global $ilAuth;
        $ilAuth->setAuth($userName);
        $ilAuth->start();

        // Used to return session-cookies
        $result = array();

        // Fetch headers (waiting to be) send by php
        $headers = headers_list();
        foreach ($headers as $header) {
            // We are only looking for cookies
            if (substr($header, 0, 12) === 'Set-Cookie: ') {
                // Extract cookie-settings
                $cookie = array();
                $settings = explode(';', substr($header, 12));
                foreach ($settings as $key => $value) {
                    $value = trim($value);
                    $pairs = explode('=', $value);
                    $cookie[$pairs[0]] = $pairs[1];
                }

                // Got session-cookie?
                if (array_key_exists('PHPSESSID', $cookie)) {
                    $result[] = array(
            'key' => 'PHPSESSID',
            'value' => $cookie['PHPSESSID'],
            'expires' => 0,
            'path' => $cookie['path']
          );
                } elseif (array_key_exists('authchallenge', $cookie)) {
                    $result[] = array(
            'key' => 'authchallenge',
            'value' => $cookie['authchallenge'],
            'expires' => 0,
            'path' => $cookie['path']
          );
                }
            }
        }

        // Return session cookie data
        return $result;
    }


    /**
     * Function: isAdmin($userId)
     *  Checks if given user owns the administration role in ILIAS.
     *  If no user-id is given, fetch the it from access-token.
     *
     * Parameters:
     *  $userId <Integer> - [Optional] User (id) that should be checked for admin-role.
     *                      Leave blank to load user from available access-token.
     *                      Important: Without $userId, requires the access-token to have been loaded.
     *
     * Return:
     *  <Boolean> - True if the given user havs the admin-role in ILIAS, false otherwise
     */
    public static function isAdmin($userId = null)
    {
        // Load role-based access-control review-functions
        self::includeIlias('Services/AccessControl/classes/class.ilRbacReview.php');

        // Fetch user-id from access-token if non is given
        if (!isset($userId)) {
            $app = \RESTController\RESTController::getInstance();
            $accessToken = $app->request()->getToken();
            $userId = $accessToken->getUserId();
        }

        // Check wether a given user has the admin-role
        if ($userId) {
            $rbacreview = new \ilRbacReview();
            $admin = $rbacreview->isAssigned($userId, self::RBCA_ADMIN_ID);
            return $admin;
        }

        // No username given -> can't obviously be admin
        return false;
    }


    /**
     * Function: getObjId($refId)
     *  Fetches the Object-Id (obj_id) of any ILIAS-Object given one of its Reference-Ids (ref_id).
     *
     * Parameters:
     *  $refId <Integer> - Reference-Id of ILIAS-Object for which the Object-Id should be queried
     *
     * Return:
     *  <Integer> - Object-Id represented by given Reference-Id
     */
    public static function getObjId($refId)
    {
        // Make global ilDB locally accessable
        /**
         * @var Container $container
         */
        $container = $GLOBALS["DIC"];
        $database = $container->database();

        // Query object by its refence-id
        $sql = RESTDatabase::safeSQL('SELECT obj_id FROM object_reference WHERE ref_id = ' . $database->quote($refId, 'integer'));
        $query = $database->query($sql);
        $row = $database->fetchAssoc($query);

        // Found match?
        if ($row) {
            return $row['obj_id'];
        }

        // Otherwise throw exception
        throw new Exceptions\ilObject(
            self::MSG_NO_OBJECT_BY_REF,
            self::ID_NO_OBJECT_BY_REF,
            array(
        'ref_id' => $refId
      )
    );
    }


    /**
     * Function: getRefId($objId)
     *  Fetches the Reference-Id (ref_id) of any ILIAS-Object given its Object-Id (obj_id).
     *
     * Parameters:
     *  $objId <Integer> - Object-Id of ILIAS-Object for which the Reference-Id should be queried
     *
     * Return:
     *  <Integer> - Reference-Id representing a given Object-Id
     */
    public static function getRefId($objId)
    {
        // Make global ilDB locally accessable
        /**
         * @var Container $container
         */
        $container = $GLOBALS["DIC"];
        $database = $container->database();

        // Query object by its refence-id
        $sql = RESTDatabase::safeSQL('SELECT ref_id FROM object_reference WHERE obj_id = %d', intval($objId));
        $query = $database->query($sql);
        $row = $database->fetchAssoc($query);

        // Found match?
        if ($row) {
            return $row['ref_id'];
        }

        // Otherwise throw exception
        throw new Exceptions\ilObject(
            self::MSG_NO_OBJECT_BY_OBJ,
            self::ID_NO_OBJECT_BY_OBJ,
            array(
        'obj_id' => $objId
      )
    );
    }


    /**
     * Function: getRefIds($objId)
     *  Fetches all Reference-Ids (ref_id) of any ILIAS-Object given its Object-Id (obj_id).
     *
     * Parameters:
     *  $objId <Integer> - Object-Id of ILIAS-Object for which the Reference-Id should be queried
     *
     * Return:
     *  <Array[Integer]> - Array of Reference-Ids representing a given Object-Id
     */
    public static function getRefIds($objId)
    {
        // Make global ilDB locally accessable
        /**
         * @var Container $container
         */
        $container = $GLOBALS["DIC"];
        $database = $container->database();

        // Query object by its refence-id
        $sql = RESTDatabase::safeSQL('SELECT ref_id FROM object_reference WHERE obj_id = %d', $objId);
        $query = $database->query($sql);

        // Fetch all rows with matching obj_id
        $rows = array();
        while ($row = $database->fetchAssoc($query)) {
            $rows[] = $row['ref_id'];
        }

        // return collected rows (each containing a ref_id)
        return $rows;
    }


    /**
     * Function: getUserName($userId)
     *  Given a users id, this function returns the ilias login name of a user.
     *
     * Parameters:
     *  $userId <Integer> - User-id for user who's name should be fetched (login-name, not real-name)
     *
     * Return:
     *  <String> - Fetched user-name for given user-id
     */
    public static function getUserName($userId)
    {
        // Make global ilDB locally accessable
        /**
         * @var Container $container
         */
        $container = $GLOBALS["DIC"];
        $database = $container->database();

        // Query user by his user-id
        $sql = RESTDatabase::safeSQL('SELECT login FROM usr_data WHERE usr_id = %d', $userId);
        $query = $database->query($sql);
        $row = $database->fetchAssoc($query);

        // Found match?
        if ($row) {
            return $row['login'];
        }

        // Otherwise throw exception
        throw new Exceptions\ilUser(
            self::MSG_NO_USER_BY_ID,
            self::ID_NO_USER_BY_ID,
            array(
        'id' => $userId
      )
    );
    }


    /**
     * Function: getUserId($userName)
     *  Given a users name, this function returns his user-id.
     *
     * Parameters:
     *  $userName <String> - User-name for user who's id should be fetched
     *
     * Return:
     *  <Integer> - Fetched user-id for given user-name (login-name, not real-name)
     */
    public static function getUserId($userName)
    {
        // Make global ilDB locally accessable
        /**
         * @var Container $container
         */
        $container = $GLOBALS["DIC"];
        $database = $container->database();

        // Query user by his user-name
        $sql = RESTDatabase::safeSQL('SELECT usr_id FROM usr_data WHERE login = %s', addslashes($userName));
        $query = $database->query($sql);
        $row = $database->fetchAssoc($query);

        // Found match?
        if ($row) {
            return $row['usr_id'];
        }

        // Otherwise throw exception
        throw new Exceptions\ilUser(
            self::MSG_NO_USER_BY_NAME,
            self::ID_NO_USER_BY_NAME,
            array(
        'name' => $userName
      )
    );
    }
}


/**
 * Class: ilInitialisation_Public
 *  Helper class that derives from ilInitialisation in order
 *  to 'publish' some of its methods that are (currently)
 *  required by RESTilias (some routes/models).
 *
 *  We aren't extending RESTilias directly for two reasons:
 *   - Keep the RESTilias as clean as possible of any ILIAS code/method
 *     (Reduce dependencies as much as possible)
 *   - PHP does not allow multiple inheritance (IFF we ever really
 *     needed to access another classes protected methods)
 *
 * !!! DO NOT USE THIS CLASS OUTSIDE OF RESTLIB !!!
 */
RESTilias::includeIlias('Services/Init/classes/class.ilInitialisation.php');
class ilInitialisation extends \ilInitialisation
{
    /**
     * Function; initGlobal($a_name, $a_class, $a_source_file)
     *  Derive from protected to public...
     *
     * @see \ilInitialisation::initGlobal($a_name, $a_class, $a_source_file, $destroy_existing)
     */
    public static function initGlobal($a_name, $a_class, $a_source_file = null, ?bool $destroy_existing = false): void
    {
        parent::initGlobal($a_name, $a_class, $a_source_file);
    }


    /**
     * Function: initAccessHandling()
     *  Derive from protected to public...
     *
     * @see \ilInitialisation::initAccessHandling()
     */
    public static function initAccessHandling(): void
    {
        parent::initAccessHandling();
    }

    /**
     * Function: initIliasIniFile()
     *  Derive from protected to public...
     *
     * @see \ilInitialisation::initIliasIniFile()
     */
    public static function initIliasIniFile(): void
    {
        if (!isset($GLOBALS['ilIliasIniFile'])) {
            parent::initIliasIniFile();
        }
    }
}
