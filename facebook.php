<?php
namespace ay\facebook;

class Facebook {
	private
		$app_id,
		$app_secret,
		// Refers to the page where the app is being used. It can be either Facebook Page, Facebook App Page or user website.
		$app_url,
		$access_token,
		$signed_request,
		$user_locale;
	
	
	public function __construct (array $config) {
		$this->app_id = $config['app_id'];
		$this->app_secret = $config['app_secret'];
		
		if (!empty($_POST['signed_request'])) {
			$this->parseSignedRequest($_POST['signed_request']);
		}
		
		if (!empty($this->signed_request['user']['locale'])) {
			$_SESSION['ay']['facebook'][$this->app_id]['user']['locale'] = $this->signed_request['user']['locale'];
		}
		
		if (empty($_SESSION['ay']['facebook'][$this->app_id]['locale'])) {
			$_SESSION['ay']['facebook'][$this->app_id]['user']['locale'] = 'en_US';
		}
		
		$this->user_locale = $_SESSION['ay']['facebook'][$this->app_id]['user']['locale'];
		
		if (!empty($config['app_url'])) {
			$this->app_url = $config['app_url'];
		}
	}
	
	public function api ($path, array $parameters = [], $post = null) {	
		if (!empty($this->access_token)) {
			$parameters['access_token']	= $this->access_token;
		}
		
		try {
			$url = $this->getUrl('graph', $path, $parameters);
		
			return $this->makeRequest($url, $post);
		} catch (Facebook_Exception $e) {
			// [OAuthException] Error validating access token: The session has been invalidated because the user has changed the password.
			
			if ($e->getCode() == 190 && !empty($this->access_token) && !empty($this->signed_request['oauth_token']) && $this->access_token != $this->signed_request['oauth_token']) {
				$this->access_token	= $this->signed_request['oauth_token'];
			
				return $this->api($path, $parameters, $post);
			} else {
				throw $e;
			}
		}
	}
	
	public function extendAccessToken ($access_token = null) {
		if ($access_token === null) {
			$access_token = $this->access_token;
		}
		
		if (empty($access_token)) {
			throw new Facebook_Exception('Missing present access token.');
		}
	
		$url = $this->getUrl('graph', 'oauth/access_token', [
			'client_id' => $this->getAppId(),
			'client_secret' => $this->getAppSecret(),
			'grant_type' => 'fb_exchange_token',
			'fb_exchange_token' => $access_token
		]);
		
		$response = $this->makeRequest($url);
		
		parse_str($response, $access_token);
		
		$this->setAccessToken($access_token['access_token']);
		
		$access_token['expires'] += time();
		
		return $access_token;
	}
	
	public function getAccessToken () {
		return $this->access_token;
	}
	
	public function getAccessTokenFromCode ($code) {
		$url = $this->getUrl('graph', 'oauth/access_token', [
			'client_id' => $this->getAppId(),
			'redirect_uri' => '',
			'client_secret' => $this->getAppSecret(),
			'code' => $code
		]);
		
		$response = $this->makeRequest($url);
		
		parse_str($response, $access_token);
		
		return $access_token;
	}
	
	public function getAppId () {
		return $this->app_id;
	}
	
	/**
	 * This is used to prevent CSRF access_token reuse as described in
	 * https://developers.facebook.com/docs/reference/api/securing-graph-api/
	 */
	private function getAppSecretProof () {
		$access_token = $this->getAccessToken();
		
		if (!$access_token) {
			return;
		}
	
		return hash_hmac('sha256', $access_token, $this->getAppSecret());
	}
	
	public function getAppUrl () {
		return $this->app_url;
	}
	
	public function getUserLocale () {
		return $this->user_locale;
	}
	
	/**
	 * This will redirect user to Facebook authentication page as described in https://developers.facebook.com/docs/appsonfacebook/tutorial/#auth.
	 * 
	 * @param string $scope Refer to https://developers.facebook.com/docs/reference/dialogs/oauth/
	 * @param array $app_data Refer to https://developers.facebook.com/docs/reference/login/signed-request/
	 * @param string $redirect_uri Refer to https://developers.facebook.com/docs/reference/login/signed-request/
	 */
	public function initiateAuthorisation ($scope = '', $app_data = [], $redirect_url = null) {
		if (!$redirect_url && $this->app_url) {
			$redirect_url = $this->app_url;
		} else if (!$redirect_url) {
			throw new \ErrorException('$redirect_url parameter not provided and $url_app parameter is undefined.');
		}
		
		if ($app_data) {
			$url = parse_url($redirect_url);
			
			if (empty($url['query'])) {
				$url['query'] = [];
			} else {
				parse_str($url['query'], $url['query']);
			}
			
			$url['query'] = http_build_str(['app_data' => $app_data] + $url['query']);
			
			$redirect_url = http_build_url($url);
		}
		
		$parameters	= [
			'client_id' => $this->app_id,
			'redirect_uri' => $redirect_url,
			// @todo Seems like I forgot to validate the state token upon receiving a request.
			'state' => $_SESSION['ay']['facebook'][$this->app_id]['state'],
			'scope' => $scope
		];
		
		
		$_SESSION['ay']['facebook'][$this->app_id]['state'] = bin2hex(openssl_random_pseudo_bytes(10));
	
		$login_url = $this->getUrl('www', 'dialog/oauth', $parameters);
		
		echo '<noscript>JavaScript must be enabled.</noscript><script>top.location.href = ' . json_encode($login_url) . ';</script>';
		
		exit;
	}
	
	private function makeRequest ($url, $post = null) {	
		$ch = curl_init();
		
		$options = [
			CURLOPT_URL => $url,
			CURLOPT_HTTPHEADER => ['Expect:'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
		    CURLOPT_TIMEOUT => 60,
		    CURLOPT_USERAGENT => 'anuary-1.0',
		];
		
		if ($post === true) {
			$options[CURLOPT_POST] = true;
		} else if($post !== null) {
			foreach ($post as $k => $v) {
				if (is_array($v)) {
					$post[$k] = json_encode($p);
				}
			}
			
			$options[CURLOPT_POSTFIELDS] = $post;
		}
		
		curl_setopt_array($ch, $options);
		
		$result	= curl_exec($ch);
		
		if ($result === false) {
			throw new Facebook_Exception('[' . curl_errno($ch) . '] ' . curl_error($ch));
		}
		
		curl_close($ch);
		
		$json = json_decode($result, true);
		
		if ($json !== null) {
			$result = $json;
		
			if (!empty($result['error'])) {
				throw new Facebook_Exception('[' . $result['error']['type'] . '] ' . $result['error']['message'], empty($result['error']['code']) ? null : $result['error']['code']);
			}
		}
		
		return $result;
	}
	
	/**
	 * Retrieve API specific URL with custom path and GET parameters.
	 *
	 * @param string $endpoint_name ['api', 'video-api', 'api-read', 'graph', 'graph-video', 'www']
	 * @param string $path
	 * @param array $parameters
	 */
	private function makeRequestUrl ($endpoint_name, $path = '', array $parameters = []) {	
		$url = 'https://' . $endpoint_name . '.facebook.com/' . trim($path, '/');
		
		if ($app_secret_proof = $this->getAppSecretProof()) {
			$parameters['appsecret_proof'] = $app_secret_proof;
		}
		
		if ($parameters) {
			$url .= '?' . http_build_query($parameters);
		}
		
		return $url;
	}
	
	/**
	 * Parse sign request and validate the signature.
	 *
	 * @param string $raw_signed_request
	 */
	public function parseSignedRequest ($raw_signed_request) {
		$signed_request = [];
	
		list($signed_request['encoded_sig'], $signed_request['payload']) = array_map(function ($input) {
			return base64_decode(strtr($input, '-_', '+/'));
		}, explode('.', $raw_signed_request, 2));
		
		$expected_signature = hash_hmac('sha256', $signed_request['payload'], $this->app_secret, true);
		
		if ($signed_request['encoded_sig'] !== $expected_signature) {
			throw new Facebook_Exception('Invalid signature.');
		}
		
		$signed_request['payload'] = json_decode($signed_request['payload'], true);
		
		if ($signed_request['payload']['algorithm'] !== 'HMAC-SHA256') {
			throw new Facebook_Exception('Unrecognised algorithm. Expected HMAC-SHA256.');
		}
				
		$this->signed_request = $data;
		
		return $data;
	}
	
	/**
	 * Set the access token for the api calls.
	 *
	 * @param string $access_token
	 */
	public function setAccessToken ($access_token) {
		$this->access_token	= $access_token;
	}
}