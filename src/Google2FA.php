<?php

namespace PragmaRX\Google2FALaravel;

use Carbon\Carbon;
use Illuminate\Http\Request as IlluminateRequest;
use PragmaRX\Google2FALaravel\Events\LoggedOut;
use PragmaRX\Google2FALaravel\Events\OneTimePasswordExpired;
use PragmaRX\Google2FALaravel\Exceptions\InvalidSecretKey;
use PragmaRX\Google2FALaravel\Support\Auth;
use PragmaRX\Google2FALaravel\Support\Config;
use PragmaRX\Google2FALaravel\Support\Constants;
use PragmaRX\Google2FALaravel\Support\Request;
use PragmaRX\Google2FALaravel\Support\Session;
use PragmaRX\Google2FAQRCode\Google2FA as Google2FAService;

class Google2FA extends Google2FAService
{
    use Auth;
    use Config;
    use Request;
    use Session;
    protected $qrCodeBackend;

    /**
     * Construct the correct backend.
     */
    protected function constructBackend()
    {
        switch ($this->getQRCodeBackend()) {
            case Constants::QRCODE_IMAGE_BACKEND_SVG:
                parent::__construct(new \BaconQrCode\Renderer\Image\SvgImageBackEnd());
                break;

            case Constants::QRCODE_IMAGE_BACKEND_EPS:
                parent::__construct(new \BaconQrCode\Renderer\Image\EpsImageBackEnd());
                break;

            case Constants::QRCODE_IMAGE_BACKEND_IMAGEMAGICK:
            default:
                parent::__construct();
                break;
        }
    }

    /**
     * Set the QRCode Backend.
     *
     * @param string $qrCodeBackend
     *
     * @return self
     */
    public function setQrCodeBackend(string $qrCodeBackend)
    {
        $this->qrCodeBackend = $qrCodeBackend;

        return $this;
    }

    /**
     * Authenticator constructor.
     *
     * @param IlluminateRequest $request
     */
    public function __construct(IlluminateRequest $request)
    {
        $this->boot($request);

        $this->constructBackend();
    }

    /**
     * Authenticator boot.
     *
     * @param $request
     *
     * @return Google2FA
     */
    public function boot($request)
    {
        $this->setRequest($request);

        $this->setWindow($this->config('window'));

        return $this;
    }

    /**
     * The QRCode Backend.
     *
     * @return mixed
     */
    public function getQRCodeBackend()
    {
        return $this->qrCodeBackend
            ?: $this->config('qrcode_image_backend', Constants::QRCODE_IMAGE_BACKEND_IMAGEMAGICK);
    }

    /**
     * Get the user Google2FA secret.
     *
     * @throws InvalidSecretKey
     *
     * @return mixed
     */
    protected function getGoogle2FASecretKey()
    {
        return $this->getUser()->{$this->config('otp_secret_column')};
    }

    /**
     * Check if the 2FA is activated for the user.
     *
     * @return bool
     */
    public function isActivated()
    {
        $secret = $this->getGoogle2FASecretKey();

        return !is_null($secret) && !empty($secret);
    }

    /**
     * Store the old OTP timestamp.
     *
     * @param $key
     *
     * @return mixed
     */
    protected function storeOldTimestamp($key)
    {
        return $this->config('forbid_old_passwords') === true
            ? $this->sessionPut(Constants::SESSION_OTP_TIMESTAMP, $key)
            : $key;
    }

    /**
     * Get the previous OTP timestamp.
     *
     * @return null|mixed
     */
    protected function getOldTimestamp()
    {
        return $this->config('forbid_old_passwords') === true
            ? $this->sessionGet(Constants::SESSION_OTP_TIMESTAMP)
            : null;
    }

    /**
     * Keep this OTP session alive.
     */
    protected function keepAlive()
    {
        if ($this->config('keep_alive')) {
            $this->updateCurrentAuthTime();
        }
    }

    /**
     * Get minutes since last activity.
     *
     * @return int
     */
    protected function minutesSinceLastActivity()
    {
        return Carbon::now()->diffInMinutes(
            $this->sessionGet(Constants::SESSION_AUTH_TIME)
        );
    }

    /**
     * Check if no user is authenticated using OTP.
     *
     * @return bool
     */
    protected function noUserIsAuthenticated()
    {
        return is_null($this->getUser());
    }

    /**
     * Check if OTP has expired.
     *
     * @return bool
     */
    protected function passwordExpired()
    {
        if (($minutes = $this->config('lifetime')) !== 0 && $this->minutesSinceLastActivity() > $minutes) {
            event(new OneTimePasswordExpired($this->getUser()));

            $this->logout();

            return true;
        }

        $this->keepAlive();

        return false;
    }

    /**
     * Verifies, in the current session, if a 2fa check has already passed.
     *
     * @return bool
     */
    protected function twoFactorAuthStillValid()
    {
        return
            (bool) $this->sessionGet(Constants::SESSION_AUTH_PASSED, false) &&
            !$this->passwordExpired();
    }

    /**
     * Check if the module is enabled.
     *
     * @return mixed
     */
    protected function isEnabled()
    {
        return $this->config('enabled');
    }

    /**
     * Set current auth as valid.
     */
    public function login()
    {
        $this->sessionPut(Constants::SESSION_AUTH_PASSED, true);

        $this->updateCurrentAuthTime();
    }

    /**
     * OTP logout.
     */
    public function logout()
    {
        $user = $this->getUser();

        $this->sessionForget();

        event(new LoggedOut($user));
    }

    /**
     * Update the current auth time.
     */
    protected function updateCurrentAuthTime()
    {
        $this->sessionPut(Constants::SESSION_AUTH_TIME, Carbon::now()->toIso8601String());
    }

    /**
     * Verify the OTP.
     *
     * @param $secret
     * @param $one_time_password
     *
     * @return mixed
     */
    public function verifyGoogle2FA($secret, $one_time_password)
    {
        return $this->verifyKey(
            $secret,
            $one_time_password,
            $this->getWindow(),
            null, // $timestamp
                $this->getOldTimestamp() ?: null
        );
    }

    /**
     * Verify the OTP and store the timestamp.
     *
     * @param $one_time_password
     *
     * @return mixed
     */
    protected function verifyAndStoreOneTimePassword($one_time_password)
    {
        return $this->storeOldTimestamp(
            $this->verifyGoogle2FA(
                $this->getGoogle2FASecretKey(),
                $one_time_password
            )
        );
    }
}
