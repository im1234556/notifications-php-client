<?php
namespace Alphagov\Notifications;

use GuzzleHttp\Psr7\Uri;                            // Concrete PSR-7 URL representation.
use GuzzleHttp\Psr7\Request;                        // Concrete PSR-7 HTTP Request
use Psr\Http\Message\ResponseInterface;             // PSR-7 HTTP Response Interface
use Http\Client\HttpClient as HttpClientInterface;  // Interface for a PSR-7 compatible HTTP Client.

use Alphagov\Notifications\Authentication\JWTAuthenticationInterface;

/**
 * Client for accessing GOV.UK Notify.
 *
 * Before using this client you must have:
 *  - created an account with GOV.UK Notify
 *  - found your Service ID and generated an API Key.
 *  - created at least one template and know its ID.
 *
 * Class Client
 * @package Alphagov\Notifications
 */
class Client {

    /**
     * @const string Current version of this client.
     * This follows Semantic Versioning (http://semver.org/)
     */
    const VERSION = '0.6.0';

    /**
     * @const string The API endpoint for Notify production.
     */
    const BASE_URL_PRODUCTION = 'https://api.notifications.service.gov.uk';

    /**
     * Paths for API endpoints.
     */
    const PATH_NOTIFICATION_LIST        = '/notifications';
    const PATH_NOTIFICATION_LOOKUP      = '/notifications/%s';
    const PATH_NOTIFICATION_SEND_SMS    = '/notifications/sms';
    const PATH_NOTIFICATION_SEND_EMAIL  = '/notifications/email';


    /**
     * @var string base scheme and hostname
     */
    protected $baseUrl;

    /**
     * @var HttpClientInterface PSR-7 compatible HTTP Client
     */
    private $httpClient;

    /**
     * @var JWTAuthenticationInterface
     */
    private $authenticator;


    /**
     * Instantiates a new GOV.UK Notify Client
     *
     * The client constructor accepts the following options:
     *  - httpClient: (HttpClientInterface)
     *      Required.
     *  - authenticator: (JWTAuthenticationInterface)
     *      Required if 'serviceId' and 'apiKey' are not set.
     *  - serviceId: (string)
     *      Required if 'authenticator' not set.
     *  - apiKey: (string)
     *      Required if 'authenticator' not set.
     *  - baseUrl: (string)
     *      Optional. The Notify base URL to make API calls to.
     *      If not set, this defaults to the production API.
     *
     * @param array $config
     */
    public function __construct( array $config ){

        $config = array_merge([
            'httpClient'    => null,
            'authenticator' => null,
            'serviceId'     => null,
            'apiKey'        => null,
            'baseUrl'       => null,
        ], $config);

        //--------------------------
        // Set base URL

        if( !isset( $config['baseUrl'] ) ){

            // If not set, we default to production
            $this->baseUrl = self::BASE_URL_PRODUCTION;

        } elseif ( filter_var($config['baseUrl'], FILTER_VALIDATE_URL) !== false ) {

            // Else we allow an arbitrary URL to be set.
            $this->baseUrl = $config['baseUrl'];

        } else {

            throw new Exception\InvalidArgumentException(
                "Invalid 'baseUrl' set. This must be either a valid URL, or null."
            );

        }

        //--------------------------
        // Set HTTP Client

        if( $config['httpClient'] instanceof HttpClientInterface ){

            $this->setHttpClient( $config['httpClient'] );

        } else {

            throw new Exception\InvalidArgumentException(
                "An instance of HttpClientInterface must be set under 'httpClient'"
            );

        }

        //--------------------------
        // Set/create authenticator

        if( $config['authenticator'] instanceof JWTAuthenticationInterface ){

            $this->setAuthenticator( $config['authenticator'] );

        } elseif( isset($config['apiKey']) ){

            // If we're missing the serviceId, assume it's contained within the apiKey string.
            if( !isset($config['serviceId']) ) {
              $config['serviceId'] = substr($config['apiKey'], -73, 36);
            }

            $this->setAuthenticator(new Authentication\JsonWebToken(
                $config['serviceId'],
                substr($config['apiKey'], -36, 36)
            ));

        } else {

            throw new Exception\InvalidArgumentException(
                "Either an instance of JWTAuthenticationInterface must be set under 'authenticator', ".
                "or 'serviceId' and 'apiKey' must be set."
            );

        }

    }

    //------------------------------------------------------------------------------------
    // Public API access methods

    /**
     * Send an SMS message.
     *
     * @param string    $to
     * @param string    $template
     * @param array     $personalisation
     *
     * @return array
     */
    public function sendSms( $to, $template, array $personalisation = array() ){

        return $this->httpPost(
            self::PATH_NOTIFICATION_SEND_SMS,
            $this->buildPayload( $to, $template, $personalisation )
        );

    }

    /**
     * Send an Email message.
     *
     * @param string    $to
     * @param string    $template
     * @param array     $personalisation
     *
     * @return array
     */
    public function sendEmail( $to, $template, array $personalisation = array() ){

        return $this->httpPost(
            self::PATH_NOTIFICATION_SEND_EMAIL,
            $this->buildPayload( $to, $template, $personalisation )
        );

    }

    /**
     * Returns details about the passed notification ID.
     *
     * NULL is returned if no notification is found for the ID.
     *
     * @param string $notificationId
     *
     * @return array|null
     */
    public function getNotification( $notificationId ){

        $path = sprintf( self::PATH_NOTIFICATION_LOOKUP, $notificationId );

        return $this->httpGet( $path );

    }

    /**
     * Returns a list of all notifications for the current Service ID.
     *
     * Filter supports:
     *  - status
     *  - template_type
     *  - page
     *
     * @param array $filters
     *
     * @return mixed|null
     */
    public function listNotifications( array $filters = array() ){

        // Only allow the following filter keys.
        $filters = array_intersect_key( $filters, array_flip([
            'page',
            'status',
            'template_type'
        ]));

        return $this->httpGet( self::PATH_NOTIFICATION_LIST, $filters );

    }


    //------------------------------------------------------------------------------------
    // Internal API access methods


    //-------------------------------------------
    // Build request

    /**
     * Generates the payload expected by the API.
     *
     * @param string    $to
     * @param string    $template
     * @param array     $personalisation
     *
     * @return array
     */
    private function buildPayload( $to, $template, array $personalisation ){

        $payload = [
            'to' => $to,
            'template'=> $template
        ];

        if( count($personalisation) > 0 ){
            $payload['personalisation'] = $personalisation;
        }

        return $payload;

    }

    /**
     * Generates the standard set of HTTP headers expected by the API.
     *
     * @return array
     */
    private function buildHeaders(){

        return [
            'Authorization' => 'Bearer '.$this->getAuthenticator()->createToken(),
            'Accept'        => 'application/json',
            'Content-type'  => 'application/json',
            'User-agent'    => 'NOTIFY-API-PHP-CLIENT/'.self::VERSION
        ];

    }

    //-------------------------------------------
    // GET & POST requests

    /**
     * Performs a GET against the Notify API.
     *
     * @param string $path
     * @param array  $query
     *
     * @return array|null
     * @throw Exception\NotifyException | Exception\ApiException | Exception\UnexpectedValueException
     */
    private function httpGet( $path, array $query = array() ){

        $url = new Uri( $this->baseUrl . $path );

        foreach( $query as $name => $value ){
            $url = URI::withQueryValue($url, $name, $value );
        }

        //---

        $request = new Request(
            'GET',
            $url,
            $this->buildHeaders()
        );

        try {

            $response = $this->getHttpClient()->sendRequest( $request );

        } catch (\RuntimeException $e){
            throw new Exception\NotifyException( $e->getMessage(), $e->getCode(), $e );
        }

        //---

        switch( $response->getStatusCode() ){
            case 200:
                return $this->handleResponse( $response );
            case 404:
                return null;
            default:
                return $this->handleErrorResponse( $response );
        }

    }

    /**
     * Performs a POST against the Notify API.
     *
     * @param string $path
     * @param array  $payload
     *
     * @return array
     * @throw Exception\NotifyException | Exception\ApiException | Exception\UnexpectedValueException
     */
    private function httpPost( $path, Array $payload ){

        $url = new Uri( $this->baseUrl . $path );

        $request = new Request(
            'POST',
            $url,
            $this->buildHeaders(),
            json_encode( $payload )
        );

        try {

            $response = $this->getHttpClient()->sendRequest( $request );

        } catch (\RuntimeException $e){
            throw new Exception\NotifyException( $e->getMessage(), $e->getCode(), $e );
        }

        //---

        switch( $response->getStatusCode() ){
            case 201:
                return $this->handleResponse( $response );
            default:
                return $this->handleErrorResponse( $response );
        }

    }

    //-------------------------------------------
    // Response Handling

    /**
     * Called with a response from the API when the response code was successful. i.e. 20X.
     *
     * @param ResponseInterface $response
     *
     * @return array
     * @throw Exception\ApiException
     */
    protected function handleResponse( ResponseInterface $response ){

        $body = json_decode($response->getBody(), true);

        // The expected response should always be JSON, thus now an array.
        if( !is_array($body) ){
            throw new Exception\ApiException( 'Malformed JSON response from server', $response->getStatusCode(), $response );
        }

        return $body;

    }

    /**
     * Called with a response from the API when the response code was unsuccessful. i.e. not 20X.
     *
     * @param ResponseInterface $response
     *
     * @return null
     * @throw Exception\ApiException
     */
    protected function handleErrorResponse( ResponseInterface $response ){

        $body = json_decode($response->getBody(), true);

        $message = "HTTP:{$response->getStatusCode()} - ";
        $message .= (isset($body['message'])) ? print_r($body['message'], true) : 'Unexpected response from server';

        throw new Exception\ApiException( $message, $response->getStatusCode(), $response );

    }


    //------------------------------------------------------------------------------------
    // Getters and setters

    /**
     * @return HttpClientInterface
     * @throws Exception\UnexpectedValueException
     */
    final protected function getHttpClient(){

        if( !( $this->httpClient instanceof HttpClientInterface ) ){
            throw new Exception\UnexpectedValueException('Invalid HttpClient set');
        }

        return $this->httpClient;

    }

    /**
     * @param HttpClientInterface $client
     */
    final protected function setHttpClient( HttpClientInterface $client ){

        $this->httpClient = $client;

    }

    /**
     * @return JWTAuthenticationInterface
     * @throws Exception\UnexpectedValueException
     */
    final protected function getAuthenticator(){

        if( !( $this->authenticator instanceof JWTAuthenticationInterface ) ){
            throw new Exception\UnexpectedValueException('Invalid JWTAuthenticationInterface set');
        }

        return $this->authenticator;

    }

    /**
     * @param JWTAuthenticationInterface $authenticator
     */
    final protected function setAuthenticator( JWTAuthenticationInterface $authenticator ){

        $this->authenticator = $authenticator;

    }

}
