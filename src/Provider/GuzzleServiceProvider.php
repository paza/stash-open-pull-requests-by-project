<?php

namespace Paza\Provider;

use Silex\Application;
use Silex\ServiceProviderInterface;
use Guzzle\Http\Client as GuzzleClient;

/**
 * Guzzle Service Provider.
 *
 * @author Patrick Zahnd <pazaaa@gmail.com>
 */
class GuzzleServiceProvider implements ServiceProviderInterface
{
    /**
     * Register Guzzle Clients
     *
     * @param Application $app
     *
     * @return Guzzle\Http\Client
     */
    public function register(Application $app)
    {
        $app['guzzle.client.read'] = $app->protect(function() use ($app) {
            $client = new GuzzleClient();

            $client->setDefaultOption('auth', array(
                $app['guzzle.client.read.user'],
                $app['guzzle.client.read.pass']
            ));

            return $client;
        });
    }

    /**
     * Does nothing right now
     *
     * @param Application $app
     */
    public function boot(Application $app)
    {
    }
}
