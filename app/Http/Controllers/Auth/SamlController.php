<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Saml;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Group;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

/**
 * This controller provides the endpoint for SAML communication and metadata.
 *
 * @author Johnson Yi <jyi.dev@outlook.com>
 *
 * @since 5.0.0
 */
class SamlController extends Controller
{
    /**
     * @var Saml
     */
    protected $saml;

    /**
     * Create a new authentication controller instance.
     *
     * @return void
     */
    public function __construct(Saml $saml)
    {
        $this->saml = $saml;

        $this->middleware('guest', ['except' => ['metadata', 'sls']]);
    }

    /**
     * Return SAML SP metadata for Snipe-IT
     *
     * /saml/metadata
     *
     * @author Johnson Yi <jyi.dev@outlook.com>
     *
     * @since 5.0.0
     *
     * @param Request $request
     *
     * @return Response
     */
    public function metadata(Request $request)
    {
        $metadata = $this->saml->getSPMetadata();

        if (empty($metadata)) {
            Log::debug('SAML metadata is empty - return a 403');
            return response()->view('errors.403', [], 403);
        }

        return response()->streamDownload(function () use ($metadata) {
            echo $metadata;
        }, 'snipe-it-metadata.xml', ['Content-Type' => 'text/xml']);
    }

    /**
     * Begin the SP-Initiated SSO by sending AuthN to the IdP.
     *
     * /login/saml
     *
     * @author Johnson Yi <jyi.dev@outlook.com>
     *
     * @since 5.0.0
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function login(Request $request)
    {
        $auth = $this->saml->getAuth();
        $ssoUrl = $auth->login(null, [], false, false, false, false);

        return redirect()->away($ssoUrl);
    }

    /**
     * Receives, parses the assertion from IdP and flashes SAML data
     * back to the LoginController for authentication.
     *
     * /saml/acs
     *
     * @author Johnson Yi <jyi.dev@outlook.com>
     *
     * @since 5.0.0
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function acs(Request $request)
    {
        $saml = $this->saml;
        $auth = $saml->getAuth();
        $saml_exception = false;
        try {
            $auth->processResponse();
        } catch (\Exception $e) {
            Log::warning("Exception caught in SAML login: " . $e->getMessage());
            $saml_exception = true;
        }
        $errors = $auth->getErrors();

        if (!empty($errors) || $saml_exception) {
            Log::warning('There was an error with SAML ACS: ' . implode(', ', $errors));
            Log::warning('Reason: ' . $auth->getLastErrorReason());

            return redirect()->route('login')->with('error', trans('auth/message.signin.error'));
        }

        $samlData = $saml->extractData();

                // -----------------------------
        // UTU JIT provisioning (create user on first SSO login)
        // -----------------------------
        try {
            // The extractData() format can vary. We support both common shapes:
            // 1) ['attributes' => ['uid' => ['foo'], ...]]
            // 2) ['uid' => ['foo'], ...]
            $attrs = $samlData['attributes'] ?? $samlData;

            $uid       = $attrs['uid'][0] ?? null; // short username
            $givenName = $attrs['givenName'][0] ?? null;
            $sn        = $attrs['sn'][0] ?? null;
            $mail      = $attrs['mail'][0] ?? null;
            $eppn      = $attrs['eduPersonPrincipalName'][0] ?? null;

            // Require the attributes you said you want
            if (!$uid || !$givenName || !$sn || (!$mail && !$eppn)) {
                return redirect()->route('login')
                    ->with('error', 'SSO login succeeded but required user attributes are missing. Please contact the admin.');
            }

            // Use ePPN for stable identification when available (mail can change)
            $stableId = $eppn ?: $mail;

            // Allow only UTU accounts (adjust if needed)
            if (!Str::endsWith(Str::lower($stableId), '@utu.fi')) {
                return redirect()->route('login')
                    ->with('error', 'SSO login is only allowed for utu.fi accounts.');
            }

            // Look up existing user (prefer stable id in employee_num)
            $user = null;
            if ($eppn) {
                $user = User::where('employee_num', $eppn)->first();
            }

            if (!$user) {
                // fallback by username or email
                $user = User::where('username', $uid)->first();
            }
            if (!$user && $mail) {
                $user = User::where('email', $mail)->first();
            }

            // Create if not exists
            if (!$user) {
                $newUsersGroup = Group::where('name', 'New Users')->first();

                if (!$newUsersGroup) {
                    return redirect()->route('login')
                        ->with('error', 'Account creation failed: required group "New Users" is missing. Please contact the admin.');
                }

                $user = new User();
                $user->first_name   = $givenName;
                $user->last_name    = $sn;
                $user->username     = $uid;
                $user->email        = $mail ?: $eppn;     // store mail if provided
                $user->employee_num = $eppn ?: null;      // stable UTU identifier
                $user->activated    = 1;

                // Random password (SSO users won't use it, but keeps the record valid)
                $user->password = Hash::make(Str::random(64));

                $user->save();

                // Attach the "New Users" group (no rights)
                $user->groups()->syncWithoutDetaching([$newUsersGroup->id]);
            } else {
                // Optional: keep data fresh on each SSO login
                $dirty = false;

                if ($eppn && $user->employee_num !== $eppn) { $user->employee_num = $eppn; $dirty = true; }
                if ($mail && $user->email !== $mail) { $user->email = $mail; $dirty = true; }
                if ($givenName && $user->first_name !== $givenName) { $user->first_name = $givenName; $dirty = true; }
                if ($sn && $user->last_name !== $sn) { $user->last_name = $sn; $dirty = true; }

                if ($dirty) { $user->save(); }
            }

        } catch (\Throwable $e) {
            Log::warning('SSO JIT provisioning failed: '.$e->getMessage());
            return redirect()->route('login')
                ->with('error', 'Account creation failed. Please contact the admin.');
        }

        return redirect()->route('login')->with('saml_login', $samlData);
    }

    /**
     * Receives LogoutRequest/LogoutResponse from IdP and flashes
     * back to the LoginController for logging out.
     *
     * /saml/sls
     *
     * @author Johnson Yi <jyi.dev@outlook.com>
     *
     * @since 5.0.0
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function sls(Request $request)
    {
        $auth = $this->saml->getAuth();
        $retrieveParametersFromServer = $this->saml->getSetting('retrieveParametersFromServer', false);
        $saml_exception = false;
        try {
            $sloUrl = $auth->processSLO(true, null, $retrieveParametersFromServer, null, true);
        } catch (\Exception $e) {
            Log::warning("Exception caught in SAML single-logout: " . $e->getMessage());
            $saml_exception = true;
        }
        $errors = $auth->getErrors();

        if (!empty($errors) || $saml_exception) {
            Log::warning('There was an error with SAML SLS: ' . implode(', ', $errors));
            Log::warning('Reason: ' . $auth->getLastErrorReason());

            return view('errors.403');
        }

        return redirect()->route('logout.get')->with(['saml_logout' => true,'saml_slo_redirect_url' => $sloUrl]);
    }
}
