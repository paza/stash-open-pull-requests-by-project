<?php

date_default_timezone_set('Europe/Zurich');

require_once __DIR__ . '/../vendor/autoload.php';

use Silex\Application;
use Silex\Provider\UrlGeneratorServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Debug\ErrorHandler;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Silex\Provider\TwigServiceProvider;

ErrorHandler::register();

$app = new Application();
$app->register(new DerAlex\Silex\YamlConfigServiceProvider(__DIR__ . '/../config.yml'));

$app['debug'] = true; // '127.0.0.1' === $_SERVER['REMOTE_ADDR'] || '::1' === $_SERVER['REMOTE_ADDR'];

$app->register(new Silex\Provider\SessionServiceProvider());
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());
$app->register(new Paza\Provider\GuzzleServiceProvider(), array(
    'guzzle.client.read.user' => $app['config']['parameters']['login_read_user'],
    'guzzle.client.read.pass' => $app['config']['parameters']['login_read_pass']
));

$app->register(new TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/../views',
    'twig.options' => array('cache' => __DIR__.'/../cache/twig', 'debug' => true),
));

$app['twig']->addFilter(new Twig_SimpleFilter('gravatarSmall', function ($email) {
    $url = 'http://www.gravatar.com/avatar/';
    $url .= md5( strtolower( trim( $email ) ) );
    $url .= sprintf('?s=%s&d=%s&r=%s', 20, 'mm', 'g');

    return $url;
}));

$app['twig']->addFilter(new Twig_SimpleFilter('gravatar', function ($email) {
    $url = 'http://www.gravatar.com/avatar/';
    $url .= md5( strtolower( trim( $email ) ) );
    $url .= sprintf('?s=%s&d=%s&r=%s', 40, 'mm', 'g');

    return $url;
}));

/**
 * Execute a read request
 *
 * @param string $url
 */
$guzzleRead = function ($url) use ($app) {
    $client = $app['guzzle.client.read']();

    $response = $client->get($app['config']['parameters']['url_rest'] . $url)->send();

    $body = json_decode($response->getBody(), true);

    return $body;
};

/**
 * Get the cache by project
 */
$getCache = function ($cacheFile, $projectKey) {
    $cache = [];

    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);

        if (!empty($cache)) {
            if (!empty($cache['prs'])) {
                $prCacheAge = time() - $cache['prs']['time'];

                // if pr cache is older than 10 minutes, refresh cache
                if (60 * 10 < $prCacheAge) {
                    $cache['prs'] = null;
                }
            }

            if (!empty($cache['tags'])) {
                $tagCacheAge = time() - $cache['tags']['time'];

                // if tags cache is older than 30 minutes, refresh cache
                if (60 * 30 < $tagCacheAge) {
                    $cache['tags'] = null;
                }
            }

            $repoCacheAge = time() - $cache['repos']['time'];

            // if repo cache is older than 12 hours, refresh cache
            if (60 * 60 * 12 < $repoCacheAge) {
                $cache['repos'] = null;
            }
        }
    }

    return $cache;
};

/**
 * Get open pull requests in project
 *
 * @param Request $request
 * @param string  $projectKey
 */
$app->get(
    '/',
    function (Request $request) use ($guzzleRead, $app) {
        $projects = $guzzleRead('api/1.0/projects?limit=200');

        return $app['twig']->render('projects.html.twig', array(
            'projects' => $projects['values']
        ));
    }
)
->bind('projects');

/**
 * Get open pull requests in project
 *
 * @param Request $request
 * @param string  $projectKey
 */
$app->get(
    '/{projectKey}/pull-requests',
    function (Request $request, $projectKey) use ($guzzleRead, $getCache, $app) {

        $cacheFile = sprintf(__DIR__ . '/../pr-cache/%s.json', $projectKey);
        $cache = $getCache($cacheFile, $projectKey);

        $hasChanges = false;

        $refresh = $request->get('refresh');

        if (!in_array($refresh, ['pr', 'all'])) {
            $refresh = null;
        }

        if (empty($cache['repos']) || in_array($refresh, ['all'])) {
            $repos = $guzzleRead(sprintf(
                'api/1.0/projects/%s/repos?limit=200',
                $projectKey
            ));

            $cache['repos'] = [
                'time' => time(),
                'values' => $repos['values'],
            ];

            $hasChanges = true;
        }

        if (empty($cache['prs']) || in_array($refresh, ['pr', 'all'])) {
            $openPullrequests = [];

            foreach ($cache['repos']['values'] as $repo) {
                $repoKey = $repo['slug'];

                $pullRequests = $guzzleRead(sprintf(
                    'api/1.0/projects/%s/repos/%s/pull-requests',
                    $projectKey,
                    $repoKey
                ));

                if (0 < $pullRequests['size']) {
                    $openPullrequests[] = [
                        'repo' => $repo,
                        'prs' => $pullRequests['values']
                    ];
                }
            }

            $cache['prs'] = [
                'time' => time(),
                'values' => $openPullrequests,
            ];

            $hasChanges = true;
        }

        if ($hasChanges) {
            file_put_contents($cacheFile, json_encode($cache));

            // redirect so if the user refreshes the browser
            // page the refresh url params are not set
            return $app->redirect($app['url_generator']->generate('pull-requests', [
                'projectKey' => $projectKey
            ]));
        }

        return $app['twig']->render('pull-requests.html.twig', array(
            'projectKey' => $projectKey,
            'cache'      => $cache,
            'parameters' => $app['config']['parameters'],
        ));
    }
)
->bind('pull-requests');

/**
 * Get open pull requests in project
 *
 * @param Request $request
 * @param string  $projectKey
 */
$app->get(
    '/{projectKey}/tags-branches',
    function (Request $request, $projectKey) use ($guzzleRead, $getCache, $app) {

        $cacheFile = sprintf(__DIR__ . '/../pr-cache/%s.json', $projectKey);
        $cache = $getCache($cacheFile, $projectKey);

        $hasChanges = false;

        $refresh = $request->get('refresh');

        if (!in_array($refresh, ['tags', 'all'])) {
            $refresh = null;
        }

        if (empty($cache['repos']) || in_array($refresh, ['all'])) {
            $repos = $guzzleRead(sprintf(
                'api/1.0/projects/%s/repos?limit=200',
                $projectKey
            ));

            $cache['repos'] = [
                'time' => time(),
                'values' => $repos['values'],
            ];

            $hasChanges = true;
        }

        if (empty($cache['tags']) || in_array($refresh, ['tags', 'all'])) {
            $repoTags = [];

            foreach ($cache['repos']['values'] as $repo) {
                $repoKey = $repo['slug'];

                $tags = $guzzleRead(sprintf(
                    'api/1.0/projects/%s/repos/%s/tags?limit=200',
                    $projectKey,
                    $repoKey
                ));

                $branches = $guzzleRead(sprintf(
                    'api/1.0/projects/%s/repos/%s/branches?limit=200', // ?details=true to get metadata
                    $projectKey,
                    $repoKey
                ));

                $repoTags[] = [
                    'repo' => $repo,
                    'tags' => $tags['values'],
                    'branches' => $branches['values']
                ];
            }

            $cache['tags'] = [
                'time' => time(),
                'values' => $repoTags,
            ];

            $hasChanges = true;
        }

        if ($hasChanges) {
            file_put_contents($cacheFile, json_encode($cache));

            // redirect so if the user refreshes the browser
            // page the refresh url params are not set
            return $app->redirect($app['url_generator']->generate('tags', [
                'projectKey' => $projectKey
            ]));
        }

        return $app['twig']->render('tags.html.twig', array(
            'projectKey' => $projectKey,
            'cache'      => $cache,
            'parameters' => $app['config']['parameters'],
        ));
    }
)
->bind('tags');

return $app;
