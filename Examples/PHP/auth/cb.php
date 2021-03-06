<?php
/*
 * Copyright 2020 ZenKey, LLC.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../utilities.php';
require __DIR__.'/../ZenKeyOIDCService.php';
require __DIR__.'/../SessionService.php';
require __DIR__.'/../AuthorizationFlowHandler.php';

$dotenv = Dotenv\Dotenv::create(__DIR__.'/..');
$dotenv->load();

// constants from environment variables
$BASE_URL = filter_input_fix(INPUT_ENV, 'BASE_URL', FILTER_SANITIZE_URL);
$CLIENT_ID = filter_input_fix(INPUT_ENV, 'CLIENT_ID', FILTER_SANITIZE_STRING);
$CLIENT_SECRET = filter_input_fix(INPUT_ENV, 'CLIENT_SECRET', FILTER_SANITIZE_STRING);
$CARRIER_DISCOVERY_URL = filter_input_fix(INPUT_ENV, 'CARRIER_DISCOVERY_URL', FILTER_SANITIZE_URL);
$OIDC_PROVIDER_CONFIG_URL = filter_input_fix(INPUT_ENV, 'OIDC_PROVIDER_CONFIG_URL', FILTER_SANITIZE_URL);

$REDIRECT_URI = "{$BASE_URL}/auth/cb.php";

$SCOPE = 'openid name email phone postal_code';

$sessionService = new SessionService();
$zenkeyOIDCService = new ZenKeyOIDCService($CLIENT_ID, $CLIENT_SECRET, $REDIRECT_URI, $OIDC_PROVIDER_CONFIG_URL, $CARRIER_DISCOVERY_URL, $sessionService);
$authFlowHandler = new AuthorizationFlowHandler($sessionService);

// protect against iFraming
header('X-Frame-Options: DENY');
// force HTTPS
header("Strict-Transport-Security:max-age=5184000");

// use a cached MCCMNC if needed
$mccmnc = filter_input_fix(INPUT_GET, 'mccmnc', FILTER_SANITIZE_NUMBER_INT) ?? $sessionService->getMCCMNC();
$error = filter_input_fix(INPUT_GET, 'error', FILTER_SANITIZE_STRING);
$state = filter_input_fix(INPUT_GET, 'state', FILTER_SANITIZE_STRING);
$code = filter_input_fix(INPUT_GET, 'code', FILTER_SANITIZE_STRING);
$loginHintToken = filter_input_fix(INPUT_GET, 'login_hint_token', FILTER_SANITIZE_STRING);
try {
    // handle errors returned from ZenKey
    if (isset($error)) {
        $sessionService->clear();
        throw new Exception($error);
    }

    // check if the user is already logged in
    if ($sessionService->getCurrentUser() && !$authFlowHandler->authorizationInProgress()) {
        header('Location: /');

        return;
    }

    //  If we have no mccmnc, begin the carrier discovery process
    if (!$mccmnc) {
        header('Location: /auth.php');

        return;
    }

    if (!$state) {
        // if an error happens, delete the auth information saved in the session
        $sessionService->clear();
        throw new Exception('missing state');
    }

    // build our OpenID client
    $oidcProvider = $zenkeyOIDCService->discoverOIDCProvider($mccmnc);

    // Request an auth code
    // The carrier discovery endpoint has redirected back to our app with the mccmnc.
    // Now we can start the authorize flow by requesting an auth code.
    // Send the user to the ZenKey authorization endpoint. After authorization, this endpoint will redirect back to our app with an auth code.
    if (!isset($code)) {
        if ($authFlowHandler->authorizationInProgress()) {
            // authorization is in progress: use a context and different ACR values
            $authUrlOptions = [
                'scope' => 'openid',
                'context' => $authFlowHandler->getAuthorizationDetails()['context'],
                'acr_values' => 'a3',
            ];
        } else {
            $authUrlOptions = [
                'scope' => $SCOPE,
            ];
        }

        // send user to the ZenKey authorization endpoint to request an auth code
        $authorizationUrl = $zenkeyOIDCService->getAuthCodeRedirectUrl($oidcProvider, $loginHintToken, $state, $mccmnc, $authUrlOptions);
        header("Location: {$authorizationUrl}");

        return;
    }

    // Request a token
    // The authentication endpoint has redirected back to our app with an auth code. Now we can exchange the auth code for a token.
    // Once we have a token, we can make a userinfo request to get user profile information.
    if (isset($code)) {
        $tokenVerificationValues = [];
        if ($authFlowHandler->authorizationInProgress()) {
            // validate the token with all the auth request details
            // TODO: currently not all carriers return a context, or return it in different formats (string vs base64 encoded)
            // re-enable context validation once carriers are consistent
            // $tokenVerificationValues['context'] = $authFlowHandler->getAuthorizationDetails()['context'];
            $tokenVerificationValues['acrValues'] = 'a3';
            $tokenVerificationValues['sub'] = $sessionService->getCurrentUser()['sub'];
        }

        $tokens = $zenkeyOIDCService->requestToken($oidcProvider, $code, $state, $tokenVerificationValues);

        // if an authorization is in progress, return the authflowhandler success router
        if ($authFlowHandler->authorizationInProgress()) {
            return $authFlowHandler->successRouter();
        }

        // make a userinfo request to get user information
        $userInfo = $zenkeyOIDCService->getUserinfo($oidcProvider, $tokens);

        // this is where a real app might look up the user in the database using the "sub" value
        // we could also create a new user or show a registration form
        // the $userInfo object contains values like sub, name, and email (depending on which scopes were requested)
        // these values can be saved for the user or used to auto-populate a registration form

        // save the user info in the session
        $sessionService->setCurrentUser($userInfo->toArray());

        // now that we are logged in, return to the homepage
        header('Location: /');

        return;
    }

    // If we have no mccmnc, begin the carrier discovery process
    header('Location: /auth.php');
} catch (Exception $e) {
    $sessionService->clearState();
    error_log($e);
    // for the sake of this example we log the error message
    // a real app might show a user-friendly error message instead
    echo filter_var($e->getMessage(), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
}
