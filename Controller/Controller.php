<?php

namespace Bazinga\Bundle\JsTranslationBundle\Controller;

use Bazinga\Bundle\JsTranslationBundle\Finder\TranslationFinder;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * @author William DURAND <william.durand1@gmail.com>
 */
class Controller
{
    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var EngineInterface
     */
    private $engine;

    /**
     * @var TranslationFinder
     */
    private $translationFinder;

    /**
     * @var array
     */
    private $loaders = [];

    /**
     * @var string
     */
    private $cacheDir;

    /**
     * @var boolean
     */
    private $debug;

    /**
     * @var string
     */
    private $localeFallback;

    /**
     * @var string
     */
    private $defaultDomain;
    /**
     * @var int
     */
    private $httpCacheTime;

    /**
     * @param TranslatorInterface $translator      The translator.
     * @param EngineInterface $engine              The engine.
     * @param TranslationFinder $translationFinder The translation finder.
     * @param string $cacheDir
     * @param boolean $debug
     * @param string $localeFallback
     * @param string $defaultDomain
     * @param int $httpCacheTime
     */
    public function __construct(
        TranslatorInterface $translator,
        EngineInterface $engine,
        TranslationFinder $translationFinder,
        $cacheDir,
        $debug = false,
        $localeFallback = '',
        $defaultDomain = '',
        $httpCacheTime = 86400
    )
    {
        $this->translator = $translator;
        $this->engine = $engine;
        $this->translationFinder = $translationFinder;
        $this->cacheDir = $cacheDir;
        $this->debug = $debug;
        $this->localeFallback = $localeFallback;
        $this->defaultDomain = $defaultDomain;
        $this->httpCacheTime = $httpCacheTime;
    }

    /**
     * Add a translation loader if it does not exist.
     *
     * @param string $id              The loader id.
     * @param LoaderInterface $loader A translation loader.
     */
    public function addLoader($id, $loader)
    {
        if (!array_key_exists($id, $this->loaders)) {
            $this->loaders[$id] = $loader;
        }
    }

    public function getTranslationsAction(Request $request, $domain, $_format)
    {
        $locales = $this->getLocales($request);

        if (0 === count($locales)) {
            throw new NotFoundHttpException();
        }

        $cache = new ConfigCache(sprintf('%s/%s.%s.%s',
                                         $this->cacheDir,
                                         $domain,
                                         implode('-', $locales),
                                         $_format
                                 ), $this->debug);

        if (!$cache->isFresh()) {
            $resources = [];
            $translations = [];

            foreach ($locales as $locale) {
                $translations[$locale] = [];

                $files = $this->translationFinder->get($domain, $locale);

                if (1 > count($files)) {
                    continue;
                }

                $translations[$locale][$domain] = [];

                foreach ($files as $file) {
                    $extension = pathinfo($file->getFilename(), \PATHINFO_EXTENSION);

                    if (isset($this->loaders[$extension])) {
                        $resources[] = new FileResource($file->getPath());
                        $catalogue = $this->loaders[$extension]
                            ->load($file, $locale, $domain);

                        $translations[$locale][$domain] = array_replace_recursive(
                            $translations[$locale][$domain],
                            $catalogue->all($domain)
                        );
                    }
                }
            }

            $content = $this->engine->render('BazingaJsTranslationBundle::getTranslations.' . $_format . '.twig', [
                'fallback'       => $this->localeFallback,
                'defaultDomain'  => $this->defaultDomain,
                'translations'   => $translations,
                'include_config' => true,
            ]);

            try {
                $cache->write($content, $resources);
            } catch (IOException $e) {
                throw new NotFoundHttpException();
            }
        }

        $expirationTime = new \DateTime();
        $expirationTime->modify('+' . $this->httpCacheTime . ' seconds');
        $response = new Response(
            file_get_contents($cache->getPath()),
            200,
            ['Content-Type' => $request->getMimeType($_format)]
        );
        $response->prepare($request);
        $response->setPublic();
        $response->setETag(md5($response->getContent()));
        $response->isNotModified($request);
        $response->setExpires($expirationTime);

        return $response;
    }

    public function getMultipleTranslationsAction(Request $request, $domains, $_format)
    {
        $responses = [];
        foreach (explode(',', $domains) as $domain) {
            $responses[] = $this->getTranslationsAction($request, $domain, $_format)->getContent();
        }

        return new Response(implode('', $responses));
    }

    private function getLocales(Request $request)
    {
        if (null !== $locales = $request->query->get('locales')) {
            $locales = explode(',', $locales);
        }
        else {
            $locales = [$request->getLocale()];
        }

        $locales = array_filter($locales, function ($locale) {
            return 1 === preg_match('/^[a-z]{2}([-_]{1}[a-zA-Z]{2})?$/', $locale);
        });

        $locales = array_unique(array_map(function ($locale) {
            return trim($locale);
        }, $locales));

        return $locales;
    }
}
