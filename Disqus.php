<?php

/*
* This file is part of the KnpDisqusBundle package.
*
* (c) KnpLabs <hello@knplabs.com>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Knp\Bundle\DisqusBundle;

use Buzz\Browser;
use Buzz\Client\Curl;
use Buzz\Message\Request;

use Symfony\Component\DependencyInjection\ContainerInterface;

class Disqus
{
    const DISQUS_URL = 'https://disqus.com/api/3.0/';

    /**
     * @var \Symfony\Component\DependencyInjection\ContainerInterfac
     */
    protected $container;

    protected $apiKey;
    protected $shortname;
    protected $debug;

    protected $id;

    protected $options = array(
        'since'   => null,
        'cursor'  => null,
        'query'   => null,
        'include' => array('approved'),
        'order'   => 'desc',
        'limit'   => 100,
        'debug'   => 0,
    );

    public function __construct(ContainerInterface $container, $apiKey, $debug = 0)
    {
        $this->container = $container;

        $this->apiKey    = $apiKey;
        $this->debug     = $debug;
    }

    public function fetch($shortname, array $options, $fetch = 'threads/listPosts')
    {
        $this->shortname = $shortname;

        $options = $this->setOptions($options);

        $url = $this->buildUrl($options, $fetch);
        if ($this->container->has('knp_zend_manager')) {
            $cache = $this->container->get('knp_zend_manager');
            $cache = $cache->getCache($this->container->getParameter('knp_disqus.cache.'.$shortname));
            $key   = sha1($url);
            if (false === ($content = $cache->load($key))) {
                $content = json_decode($this->httpRequest($url), true);

                $cache->save($content, $key);
            }
        } else {
            $content = json_decode($this->httpRequest($url), true);
        }

        /**
         * Huge temporary hack to make SSL possible. The cache URL breaks
         * down into 2 cases:
         *
         *  1) If the user does not have an avatar, then we get something
         *      like http://www.gravatar..., which ultimately redirects
         *      to http://mediacdn... showing the default image
         *  2) If the user *has* an avatar, then it's always http://mediacdn...
         *      and shows that user's avatar
         *
         * The problem is that in order to be able to swap out http://mediacdn
         * for https:///securecdn to make ssl work, the avatar must be normalized
         * here to always return http://mediacdn... To do that, we're checking
         * to see if it will be situation (1), and if it is, we hardcode
         * directly to the default avatar that lives at http://mediacdn.
         *
         * Overall, this is necessary because disqus really doesn't seem to
         * support https very well - we call their JS file via https, but
         * they still serve us a bunch of http images :/.
         */
        foreach ($content['response'] as $key => $comment) {
            if (isset($comment['author']['avatar']['cache'])) {
                $cache = $comment['author']['avatar']['cache'];
                if (strpos($cache, 'http://www.gravatar.com/avatar.php') === 0) {
                    // we have a default URL, which we need to rewrite so that it's possible to make it secure if needed
                    $content['response'][$key]['author']['avatar']['cache'] = 'http://mediacdn.disqus.com/1341862960/images/noavatar92.png';
                }
            }
        }

        return $content;
    }

    public function getParameters()
    {
        return array(
            'id'         => $this->id,
            'shortname'  => $this->shortname,
            'debug'      => $this->debug,
            'api_key'    => $this->apiKey
        );
    }

    protected function buildUrl($options, $fetch, $format = 'json')
    {
        if (isset($options['identifier'])) {
            $this->id = array('identifier' => $options['identifier']);
            $id       = ':ident='.$options['identifier'];
        } elseif (isset($options['link'])) {
            $this->id = array('link' => $options['link']);
            $id       = ':link='.$options['link'];
        } elseif (isset($options['id'])) {
            $this->id = array('id' => $options['id']);
            $id       = '='.$options['id'];
        }

        if (!isset($id)) {
            throw new \InvalidArgumentException();
        }

        // @todo this should be more based on API docs (many params for many different fetch routes)
        return self::DISQUS_URL.$fetch.'.'.$format.'?thread'.$id.'&forum='.$this->shortname.'&api_key='.$this->apiKey;
    }

    protected function setOptions(array $options)
    {
        if (isset($options['order']) && !in_array($options['order'], array('asc', 'desc'))) {
            throw new \InvalidArgumentException(sprintf('Unknown `order` value used (%s), allowed are: asc, desc', $options['order']));
        }

        if (isset($options['include'])) {
            if (false !== strpos(',', $options['include'])) {
                $options['include'] = explode(',', $options['include']);
            }
            if (!is_array($options['include'])) {
                $options['include'] = array($options['include']);
            }

            $allowedIncludes = array('unapproved', 'approved', 'spam', 'deleted', 'flagged', 'highlighted');
            foreach ($options['include'] as $include) {
                $include = trim($include);
                if (!in_array($include, $allowedIncludes)) {
                    throw new \InvalidArgumentException(sprintf('Unknown `include` value used (%s), allowed are: %s', $include, implode(', ', $allowedIncludes)));
                }
            }

            $options['include'] = implode(', ', $options['include']);
        }

        // Maximum value of 100 (Disqus API limit)
        if (isset($options['limit']) && $options['limit'] > 100) {
            $options['limit'] = 100;
        }

        return array_merge($this->options, $options);
    }

    protected function httpRequest($url, $method = Request::METHOD_GET)
    {
        $buzz = new Browser(new Curl());

        $response = $buzz->call($url, $method);

        return $response->getContent();
    }
}
