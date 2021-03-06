<?php
namespace Hook\Model;

use Hook\Application\Context;
use Hook\Application\Config;
use Hook\Exceptions\ForbiddenException;

use Carbon\Carbon;

/**
 * Auth
 *
 * @uses Collection
 */
class Auth extends Collection
{
    const FORGOT_PASSWORD_FIELD = 'forgot_password_token';
    const FORGOT_PASSWORD_EXPIRATION_FIELD = 'forgot_password_expiration';
    const FORGOT_PASSWORD_EXPIRATION_TIME = 14400; // (60 * 60 * 4) = 4 hours

    protected $table = 'auths';
    protected $dates = array(self::FORGOT_PASSWORD_EXPIRATION_FIELD);

    // protect from mass-assignment.
    protected $guarded = array('password_salt', 'forgot_password_token', 'forgot_password_expiration', 'deleted_at'); // 'email', 'password',
    protected $hidden = array('role', 'password', 'password_salt', 'forgot_password_token', 'forgot_password_expiration', 'deleted_at');

    // force a trusted action?
    // - currently only used on resetPassword method
    protected $isTrustedAction = false;

    static $_current = null;

    public static function boot()
    {
        static::$lastTableName = 'auths';
        parent::boot();
    }

    /**
     * current - get current active Auth instance
     * @static
     * @return Auth|null
     */
    public static function current()
    {
        if (static::$_current === null) {
            if ($token = AuthToken::current()) {
                //
                // TODO: (known bug)
                // When accessing $token->auth inside an observer other than `auth`,
                // the table name of the query is being equals to the observer itself.
                //
                $token->auth(); // this resets the target querying table name
                static::$_current = $token->auth;
            }
        }

        return static::$_current;
    }

    public function tokens()
    {
        return $this->hasMany('Hook\Model\AuthToken', 'auth_id');
    }

    public function identities()
    {
        return $this->hasMany('Hook\Model\AuthIdentity', 'auth_id');
    }

    public function setTrustedAction($bool)
    {
        $this->isTrustedAction = $bool;
    }

    /**
     * generateToken
     * @return AuthToken
     */
    public function generateToken()
    {
        return $this->tokens()->create(array());
    }

    public function generateForgotPasswordToken()
    {
        $this->setAttribute(self::FORGOT_PASSWORD_FIELD, sha1(uniqid(rand(), true)));
        $this->setAttribute(self::FORGOT_PASSWORD_EXPIRATION_FIELD, time() + self::FORGOT_PASSWORD_EXPIRATION_TIME);
        $this->isTrustedAction = true;
        $this->save();

        return $this;
    }

    /**
     * resetPassword
     * @param  mixed $newPassword newPassword
     * @return void
     */
    public function resetPassword($newPassword)
    {
        $success = false;
        if (!$this->isForgotPasswordTokenExpired()) {
            $this->password = $newPassword;
            $this->setAttribute(self::FORGOT_PASSWORD_EXPIRATION_FIELD, time()); // expire token
            $this->isTrustedAction = true;
            $success = $this->save();
        }

        return $success;
    }

    protected function isForgotPasswordTokenExpired()
    {
        return Carbon::now()->gte($this->getAttribute(self::FORGOT_PASSWORD_EXPIRATION_FIELD));
    }

    public function toArray()
    {
        $arr = parent::toArray();

        // only display email for the logged user
        $auth_token = AuthToken::current();
        if (!$auth_token || $auth_token->auth_id != $this->_id) {
            unset($arr['email']);
        }

        return $arr;
    }

    /**
     * dataWithToken
     * @return array
     */
    public function dataWithToken()
    {
        $auth_token = $this->generateToken();
        AuthToken::setCurrent($auth_token);

        $current_auth_token = AuthToken::current();
        $data = $this->toArray();

        $data['token'] = $auth_token->toArray();

        return $data;
    }

    //
    // Hooks
    //

    public function beforeSave()
    {
        // don't allow to change 'role' when is not a trusted action
        if ($this->_id && $this->isDirty('role') && (!$this->isTrustedAction || !$this->isUpdateAllowed())) {
            $this->role = $this->original['role'];
        }

        // $this->_id &&
        if (!$this->isTrustedAction && !$this->isUpdateAllowed()) {
            throw new ForbiddenException("not_allowed");
        }

        // Update password
        if ($this->isDirty('password')) {
            $this->password_salt = sha1(uniqid(rand(), true));
            $this->password = static::password_hash($this->password, $this->password_salt);
        }

        parent::beforeSave();
    }

    protected function isUpdateAllowed() {

        //
        // Allow updates only when:
        // - Is using 'server' context.
        // - Is using 'commandline' context.
        // - Authenticated user is updating it's own data
        //
        return Context::getKey()->isServer() ||
            Context::getKey()->isCommandline() ||
            $this->isAuthenticated();
    }

    protected function isAuthenticated()  {
        $auth_token = AuthToken::current();
        return $auth_token && $auth_token->auth_id == $this->_id;
    }

    /**
     * Generate sha1 hash of a password, using 'salt' and 'pepper' (Config)
     *
     * @static
     *
     * @param string $password
     * @param string $salt
     *
     * @return string
     */
    public static function password_hash($password, $salt)
    {
        $app_auth_pepper = Config::get('security.auth_pepper', '');
        return sha1($password . $salt . $app_auth_pepper);
    }

}
