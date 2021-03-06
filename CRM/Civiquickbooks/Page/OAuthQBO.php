<?php

require getComposerAutoLoadPath();

class CRM_Civiquickbooks_Page_OAuthQBO extends CRM_Core_Page {

  private $consumer_key;

  private $shared_secret;

  private $output;

  /**
   * Get Login Helper to get Redirection URL and Access/Refresh Tokens.
   *
   * @return \QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2LoginHelper
   * @throws CiviCRM_API3_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  private function getLoginHelper() {
    $dataService = CRM_Quickbooks_APIHelper::getLoginDataServiceObject();
    $OAuth2LoginHelper = $dataService->getOAuth2LoginHelper();
    return $OAuth2LoginHelper;
  }

  /**
   * Redirect from CiviCRM to QuickBooks for App authorization.
   *
   * @throws CiviCRM_API3_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  private function redirectForAuth() {
    $OAuth2LoginHelper = $this->getLoginHelper();
    $authorizationCodeUrl = $OAuth2LoginHelper->getAuthorizationCodeURL();
    CRM_Utils_System::redirect($authorizationCodeUrl);
  }

  public function run() {
    //get current value in the database
    $this->consumer_key = civicrm_api3('Setting', 'getvalue', array('name' => "quickbooks_consumer_key"));
    $this->shared_secret = civicrm_api3('Setting', 'getvalue', array('name' => "quickbooks_shared_secret"));

    //the initial value of Client ID and Client Secret is empty string, need to check if they have been set
    if ($this->consumer_key === 0 || $this->consumer_key == '' || !isset($this->consumer_key)) {
      throw new Exception("Initial Client ID is NOT set!", 1);
    }
    if ($this->shared_secret === 0 || $this->shared_secret == '' || !isset($this->shared_secret)) {
      throw new Exception("Initial Client Secret is NOT set!", 1);
    }

    $doRedirectForAuth = TRUE;

    // Check if its a request from QuickBooks after redirection.
    if (isset($_GET['state']) && isset($_GET['code']) && isset($_GET['realmId'])) {
      $stateToken = civicrm_api3('Setting', 'getvalue', array('name' => "quickbooks_state_token"));;
      $state = $_GET['state'];
      $state = json_decode($state, TRUE);

      // Check if provided state token is received back in request.
      // If verified no need to redirect.

      if (isset($state['state_token']) && $state['state_token'] == $stateToken) {
        $doRedirectForAuth = FALSE;

        $code = $_GET['code'];
        $realmId = $_GET['realmId'];

        // Get same login helper used in generating Redirection URL.
        $OAuth2LoginHelper = $this->getLoginHelper();

        try {
          // Get Access token object using received code and RealMID.
          $accessTokenObject = $OAuth2LoginHelper->exchangeAuthorizationCodeForToken($code, $realmId);

          $refreshToken = $accessTokenObject->getRefreshToken();
          $refreshTokenExpiresIn = $accessTokenObject->getRefreshTokenExpiresAt();
          $tokenExpiresIn = $accessTokenObject->getAccessTokenExpiresAt();
          $accessToken = $accessTokenObject->getAccessToken();

          // Expiry date received in Y/m/d H:i:s format from QuickBooks
          $refreshTokenExpiresIn = DateTime::createFromFormat('Y/m/d H:i:s', $refreshTokenExpiresIn);
          $tokenExpiresIn = DateTime::createFromFormat('Y/m/d H:i:s', $tokenExpiresIn);

          // Save all the required settings.
          civicrm_api3('Setting', 'create', array(
            'quickbooks_access_token' => $accessToken,
            'quickbooks_refresh_token' => $refreshToken,
            'quickbooks_realmId' => $realmId,
            'quickbooks_access_token_expiryDate' => $tokenExpiresIn->format("Y-m-d H:i:s"),
            'quickbooks_refresh_token_expiryDate' => $refreshTokenExpiresIn->format("Y-m-d H:i:s"),
          ));

          // Get Data service object for accounting ( Including Access & Refresh token plus realmId)
          $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();

          // Fetch the company info from QuickBooks
          $companyInfo = $dataService->getCompanyInfo();

          // Now We have got all information we need to connect to this Quickbooks company.
          // Let's get the company country.
          $_company_country = $companyInfo->Country;

          //if getting no response, set country settings as AU company.
          $_company_country = (isset($_company_country['CompanyInfo'])) ? $_company_country['CompanyInfo']['Country'] : 'AU';

          try {
            civicrm_api3('Setting', 'create', array('quickbooks_company_country' => $_company_country));
          } catch (CiviCRM_API3_Exception $e) {
            // Handle error here.
            $errorMessage = $e->getMessage();
            $errorCode = $e->getErrorCode();
            $errorData = $e->getExtraParams();
            return array(
              'error' => $errorMessage,
              'error_code' => $errorCode,
              'error_data' => $errorData,
            );
          }

          // Successfully tokens and Company details stored in database.
          $this->output = array(
            'message' => "Access token info retrieved and stored successfully!",
            'redirect_url' => '<a href="' . str_replace("&amp;", "&", CRM_Utils_System::url("civicrm/quickbooks/settings", NULL, TRUE, NULL)) . '">Click here to go back to CiviQuickbooks settings page to see the new expiry date of your new access token and key</a>',
          );

        } catch (\QuickBooksOnline\API\Exception\IdsException $e) {
          // Exception while interacting with QuickBooks
          // Output an error with try again message.

          $this->output = array(
            'message' => $e->getMessage(),
            'redirect_url' => '<a href="' . str_replace("&amp;", "&", CRM_Utils_System::url("civicrm/quickbooks/settings", NULL, TRUE, NULL)) . '">Click here to go back to CiviQuickbooks settings page and try again.</a>',
          );
        }
      }
    }

    // Check if error is returned from QuickBooks redirection (e.g. User cancelled the Auth step)
    if (isset($_GET['error'])) {
      $doRedirectForAuth = FALSE;
      $error = $_GET['error'];
      if ($error == "access_denied") {
        // Output error if User denied the access.
        $this->output = array(
          'message' => 'You\'ve not authorize the request. Please authorize it to sync CiviCRM with QuickBooks',
          'redirect_url' => '<a href="' . str_replace("&amp;", "&", CRM_Utils_System::url("civicrm/quickbooks/settings", NULL, TRUE, NULL)) . '">Click here to go back to CiviQuickbooks settings page to authorise the Quickbooks CiviCRM App</a>',
        );
      }
    }

    // If first request without error/code, redirect user for Auth.
    if ($doRedirectForAuth) {
      $this->output = array('message' => 'Authorizing...');
      $this->redirectForAuth();
    }

    $this->assign('output', $this->output);

    parent::run();
  }

}
