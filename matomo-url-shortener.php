<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Exception;
use DateTime;
use Grav\Common\Plugin;
use Grav\Plugin\Directus\Utility\DirectusUtility;
use Grav\Common\Grav;
use Grav\Framework\Flex\Interfaces\FlexInterface;
use Grav\Framework\Flex\Interfaces\FlexObjectInterface;

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

        $hashedVisitorId = sha1($matomoTrackManager->getVisitorId());

        if($this->grav['uri']->Paths() && $this->grav['uri']->Paths()[0] === $this->config()['directus']['shortener_path']) {

            $flex = Grav::instance()->get('flex');
            $object = $flex->getObject($this->grav['uri']->Paths()[1], $this->config()['directus']['url_table']);
            if($object) {
                if(isset($object[$this->config()['directus']['url_goal_id_field']]) && $object[$this->config()['directus']['url_goal_id_field']] !== null) {
                    $matomoTrackManager->doTrackGoal($object[$this->config()['directus']['url_goal_id_field']]);
                    $this->createFile($matomoTrackManager, $hashedVisitorId);
                }

                $urlParams = parse_url($object['redirect']);
                $trackingParam = $this->config()['matomo']['param_name'] . '=' . $matomoTrackManager->getVisitorId();
                $redirectUri = $urlParams['scheme'] .
                    '://' .
                    $urlParams['host'] .
                    '?' .
                    ($urlParams['query'] ? $urlParams['query'] . '&' . $trackingParam : $trackingParam);

                if(!$matomoTrackManager->getVisitorId()) {
                    $this->logLine(json_encode($matomoTrackManager, JSON_THROW_ON_ERROR));
                }
                header('Location: '.$redirectUri);
                exit();
            }
        }
        elseif($this->grav['uri']->Paths() && $this->grav['uri']->Paths()[0] === $this->config()['matomo']['import_path']){
            $file = 'tmp/'. sha1($this->grav['uri']->Paths()[1]).'.txt';

            if(file_exists($file)){
                $requestUrl = $this->doTrackAction();


                echo ('doTrackAction: '.$requestUrl);
            }
            else{
                echo ('file not exists: '.$matomoTrackManager->getVisitorId());
            }
            exit();
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

    private function createFile($matomoTrackManager, $hashedVisitorId){
        $file = 'tmp/'.$hashedVisitorId.'.txt';
        $newTime = date("Y-m-d H:i:s",strtotime(date("Y-m-d H:i:s")." +20 minutes"));
        $url = $matomoTrackManager->getUrlTrackGoal($this->config()['matomo']['goal_id_response']).'&token_auth='.$this->config()['matomo']['token'].'&cdt='.$newTime;

        file_put_contents($file, "Url: ". $url ." | VisitorId:".$matomoTrackManager->getVisitorId());
    }

    private function doTrackAction($matomoTrackManager, $file){
        $content = file_get_contents($file);
        $params = explode(" | ", $content);
        $requestUrl = explode(": ", $params[0])[1];
        $matomoTrackManager->doTrackAction($requestUrl, 'link');

        return $requestUrl;
    }

    private function logLine($text) {
        $fileName = 'logs/' . (new \DateTime)->getTimestamp() . '-log.txt';
        if(!file_exists($fileName)) {
            touch($fileName);
        }
        $current = file_get_contents($fileName);
        $current .= $text;
        file_put_contents($fileName, $current);
    }
}
