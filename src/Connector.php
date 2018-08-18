<?php

namespace Cake\Codeception;

use Cake\Core\Configure;
use Cake\Core\HttpApplicationInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Event\Event;
use Cake\Http\Request;
use Cake\Http\Response;
use Cake\Http\Session;
use Cake\Routing\Router;
use ReflectionClass;
use Symfony\Component\BrowserKit\Client;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\BrowserKit\CookieJar;
use Symfony\Component\BrowserKit\History;
use Symfony\Component\BrowserKit\Request as BrowserKitRequest;
use Symfony\Component\BrowserKit\Response as BrowserKitResponse;

class Connector extends Client
{
    /**
     * Associative array of CakePHP classes:
     *
     *  - request: \Cake\Network\Request
     *  - response: \Cake\Network\Response
     *  - session: \Cake\Network\Session
     *  - controller: \Cake\Controller\Controller
     *  - view: \Cake\View\View
     *  - auth: \Cake\Controller\Component\AuthComponent
     *  - cookie: \Cake\Controller\Component\CookieComponent
     *
     * @var array
     */
    public $cake;
    /**
     * The application console commands are being run for.
     *
     * @var \Cake\Core\ConsoleApplicationInterface
     */
    protected $app;


    public function __construct(array $server = [], History $history = null, CookieJar $cookieJar = null)
    {
        parent::__construct($server, $history, $cookieJar);

        // create app instance
        try {
            $reflect = new ReflectionClass(self::getApplicationClassName());
            $this->app = $reflect->newInstanceArgs([CONFIG]);
        } catch (ReflectionException $e) {
            throw new LogicException(sprintf('Cannot load "%s" for use in integration testing.', self::getApplicationClassName()));
        }
        // bootstrap app
        $this->app->bootstrap();
        if ($this->app instanceof PluginApplicationInterface) {
            $this->app->pluginBootstrap();
        }
    }

    /**
     * Ensure that the application's routes are loaded.
     *
     * Console commands and shells often need to generate URLs.
     *
     * @return void
     */
    public function loadRoutes()
    {
        $builder = Router::createRouteBuilder('/');

        if ($this->app instanceof HttpApplicationInterface) {
            $this->app->routes($builder);
        }
        if ($this->app instanceof PluginApplicationInterface) {
            $this->app->pluginRoutes($builder);
        }
    }

    /**
     * Get instance of the session.
     *
     * @return \Cake\Network\Session
     */
    public function getSession()
    {
        if (!empty($this->cake['session'])) {
            return $this->cake['session'];
        }

        if (!empty($this->cake['request'])) {
            $this->cake['session'] = $this->cake['request']->getSession();

            return $this->cake['session'];
        }

        $config = (array)Configure::read('Session') + ['defaults' => 'php'];
        $this->cake['session'] = Session::create($config);

        return $this->cake['session'];
    }

    /**
     * Filters the BrowserKit request to the cake one.
     *
     * @param \Symfony\Component\BrowserKit\Request $request BrowserKit request.
     *
     * @return \Cake\Network\Request Cake request.
     */
    protected function filterRequest(BrowserKitRequest $request)
    {
        $url = preg_replace('/^https?:\/\/[a-z0-9\-\.]+/', '', $request->getUri());

        $_ENV = $environment = ['REQUEST_METHOD' => $request->getMethod()] + $request->getServer();

        $props = [
            'url'         => $url,
            'post'        => (array)$request->getParameters(),
            'files'       => (array)$request->getFiles(),
            'cookies'     => (array)$request->getCookies(),
            'session'     => $this->getSession(),
            'environment' => $environment,
        ];

        $this->cake['request'] = new \Cake\Http\ServerRequest($props);

        return $this->cake['request'];
    }

    /**
     * Filters the cake response to the BrowserKit one.
     *
     * @param \Cake\Http\Response $response Cake response.
     *
     * @return \Symfony\Component\BrowserKit\Response BrowserKit response.
     */
    protected function filterResponse($response)
    {
        $this->cake['response'] = $response;
        foreach ($response->getCookies() as $cookie) {
            $this->getCookieJar()->set(new Cookie(
                $cookie['name'],
                $cookie['value'],
                $cookie['expire'],
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httpOnly']
            ));
        }

        return new BrowserKitResponse(
            $response->getBody() ? $response->getBody() : '',
            $response->getStatusCode(),
            $response->getHeaders()
        );
    }

    /**
     * Makes a request.
     *
     * @param \Cake\Http\Request $request Cake request.
     *
     * @return \Cake\Http\Response Cake response.
     */
    protected function doRequest($request)
    {
        $response = new Response();
        try {
            $server = $this->getServer();
            $server->getEventManager()->on(
                'Dispatcher.beforeDispatch',
                ['priority' => 999],
                [$this, 'controllerSpy']
            );
            $response = $server->run($request);
        } catch (\PHPUnit_Exception $e) {
            throw $e;
        } catch (\Exception $e) {
            $response = $this->handleError($e);
        }

        return $response;
    }

    /**
     * Returns a Server instance to work with
     *
     * @return \Cake\Http\Server
     */
    public function getServer()
    {
        Router::reload();
        return new \Cake\Http\Server($this->app);
    }

    /**
     * Attempts to render an error response for a given exception.
     *
     * This method will attempt to use the configured exception renderer.
     * If that class does not exist, the built-in renderer will be used.
     *
     * @param \Exception $exception Exception to handle.
     *
     * @return void
     * @throws \Exception
     */
    protected function handleError($exception)
    {
        $class = Configure::read('Error.exceptionRenderer');
        if (empty($class) || !class_exists($class)) {
            $class = 'Cake\Error\ExceptionRenderer';
        }
        $instance = new $class($exception);

        return $instance->render();
    }

    /**
     * [controllerSpy description]
     *
     * @param \Cake\Event\Event $event Event.
     */
    public function controllerSpy(Event $event)
    {
        if (empty($event->getData('controller'))) {
            return;
        }

        $this->cake['controller'] = $event->data['controller'];
        $eventManager = $event->data['controller']->getEventManager();

        $eventManager->on(
            'Controller.startup',
            ['priority' => 999],
            [$this, 'authSpy']
        );


        $eventManager->on(
            'View.beforeRender',
            ['priority' => 999],
            [$this, 'viewSpy']
        );
    }

    /**
     * [authSpy description]
     *
     * @param \Cake\Event\Event $event Event.
     */
    public function authSpy(Event $event)
    {
        if ($event->subject()->Auth) {
            $this->cake['auth'] = $event->getSubject()->Auth;
        }
    }

    /**
     * [viewSpy description]
     *
     * @param \Cake\Event\Event $event Event.
     */
    public function viewSpy(Event $event)
    {
        $this->cake['view'] = $event->subject();
    }

    /**
     * Get Application class name
     *
     * @return string
     */
    protected static function getApplicationClassName()
    {
        return '\\' . Configure::read('App.namespace') . '\Application';
    }
}
