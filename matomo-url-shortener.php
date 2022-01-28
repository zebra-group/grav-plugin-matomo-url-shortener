<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Exception;
use Grav\Common\Plugin;
use Grav\Plugin\Directus\Utility\DirectusUtility;

/**
 * Class MatomoURLShortenerPlugin
 * @package Grav\Plugin
 */
class MatomoURLShortenerPlugin extends Plugin
{
    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                // Uncomment following line when plugin requires Grav < 1.7
                // ['autoload', 100000],
                ['onPluginsInitialized', 0]
            ]
        ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            return;
        }

        // Enable the main events we are interested in
        $this->enable([
            'onPageInitialized' => ['onPageInitialized', 0],
        ]);
    }

    /**
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function onPageInitialized()
    {
        $matomoTrackManager = new \MatomoTracker($this->config()['matomo']['site_id'], $this->config()['matomo']['url']);
        $matomoTrackManager->setTokenAuth($this->config()['matomo']['token']);

        if(!isset($_COOKIE[$this->config()['matomo']['cookie_name']])) {
            $userId = $this->generateUserId();
            setcookie($this->config()['matomo']['cookie_name'], $userId, strtotime( '+30 days' ));
            $_COOKIE[$this->config()['matomo']['cookie_name']] = $userId;
        }

        $matomoTrackManager->setUserId($_COOKIE[$this->config()['matomo']['cookie_name']]);

        if($this->grav['uri']->Paths() && $this->grav['uri']->Paths()[0] === $this->config()['directus']['shortener_path']) {
            $directusUtility = new DirectusUtility(
                $this->config["plugins.directus"]['directus']['directusAPIUrl'],
                $this->grav,
                $this->config["plugins.directus"]['directus']['email'],
                $this->config["plugins.directus"]['directus']['password'],
                $this->config["plugins.directus"]['directus']['token'],
                isset($this->config["plugins.directus"]['disableCors']) && $this->config["plugins.directus"]['disableCors']
            );

            $requestURL = $directusUtility->generateRequestUrl($this->config()['directus']['url_table'], $this->grav['uri']->Paths()[1] );
            $responseData = $directusUtility->get($requestURL);

            $requestData = $responseData->toArray()['data'];

            if($responseData->getStatusCode() === 200) {

                if(isset($requestData[$this->config()['directus']['url_goal_id_field']]) && $requestData[$this->config()['directus']['url_goal_id_field']] !== null) {
                    $matomoTrackManager->doTrackGoal($requestData[$this->config()['directus']['url_goal_id_field']]);
                }
                $urlParams = parse_url($requestData['redirect']);
                $trackingParam = $this->config()['matomo']['param_name'] . '=' . $matomoTrackManager->getUserId();
                $redirectUri = $urlParams['scheme'] .
                    '://' .
                    $urlParams['host'] .
                    '?' .
                    ($urlParams['query'] ? $urlParams['query'] . '&' . $trackingParam : $trackingParam);
                header('Location: '.$redirectUri);
                exit();
            }
        }
    }

    /**
     * @return string
     * @throws Exception
     */
    private function generateUserId() {
        $date = new \DateTime();
        $timestamp = $date->getTimestamp();
        $stringToHash = (string)$timestamp . (string)random_int(1000, 9999);
        return md5($stringToHash);
    }
}
