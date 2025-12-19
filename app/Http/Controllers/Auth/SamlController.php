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
            	$attrs = $samlData['attributes'] ?? $samlData;
		// Helper: read attribute by either friendly name OR OID URN
		$getAttr = function(array $attrs, string $friendly, string $oid) {
    		if (!empty($attrs[$friendly][0])) return $attrs[$friendly][0];
    		if (!empty($attrs[$oid][0])) return $attrs[$oid][0];
    		return null;
		};

		$eppn      = $getAttr($attrs, 'eduPersonPrincipalName', 'urn:oid:1.3.6.1.4.1.5923.1.1.1.6');
		$givenName = $getAttr($attrs, 'givenName', 'urn:oid:2.5.4.42');
		$sn        = $getAttr($attrs, 'sn', 'urn:oid:2.5.4.4');

		// Require only what you said you want
		if (!$eppn || !$givenName || !$sn) {
    		return redirect()->route('login')
        		->with('error', 'SSO login succeeded but required attributes (eduPersonPrincipalName, givenName, sn) are missing. Please contact the admin.');
		}

		// Use ePPN as the Snipe-IT username
		$username = $eppn;

		// Optional: basic domain sanity check (if you want it)
		if (!Str::endsWith(Str::lower($username), '@utu.fi')) {
    		return redirect()->route('login')
        	->with('error', 'SSO login is only allowed for utu.fi accounts.');
		}

		// Look up existing user by username (eppn)
		$user = User::where('username', $username)->first();

		if (!$user) {
    			$newUsersGroup = Group::where('name', 'New Users')->first();

    			if (!$newUsersGroup) {
        			return redirect()->route('login')
            			->with('error', 'Account creation failed: required group "New Users" is missing. Please contact the admin.');
    			}

    			$user = new User();
    			$user->first_name = $givenName;
    			$user->last_name  = $sn;
    			$user->username   = $username;

    			// Optional fields: leave blank unless you decide to request mail later
    			$user->email = $eppn;

    			$user->activated = 1;
    			$user->password  = Hash::make(Str::random(64));
    			$user->save();

    			$user->groups()->syncWithoutDetaching([$newUsersGroup->id]);
		} else {
    			// Keep names updated
    			$dirty = false;
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
