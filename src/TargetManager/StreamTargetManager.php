<?php

namespace Octopus\TargetManager;

use Evenement\EventEmitter;
use Octopus\TargetManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;
use SimpleXMLElement;

/**
 * The TargetManager reads URLs from a plain stream and emits them.
 */
class StreamTargetManager extends EventEmitter implements ReadableStreamInterface, TargetManager
{
    /**
     * @see https://www.sitemaps.org/protocol.html
     *
     * @var string
     */
    private const XML_SITEMAP_NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /**
     * @see https://www.sitemaps.org/protocol.html#index
     *
     * @var string
     */
    private const XML_SITEMAP_INDEX_ROOT_ELEMENT = 'sitemapindex';

    /**
     * @see https://www.sitemaps.org/protocol.html#index
     *
     * @var string
     */
    private const XML_SITEMAP_ELEMENT = 'sitemap';

    /**
     * @see https://www.sitemaps.org/protocol.html
     *
     * @var string
     */
    private const XML_SITEMAP_ROOT_ELEMENT = 'urlset';

    /**
     * @var string
     */
    private $buffer = '';

    /**
     * @var bool
     */
    private $closed = false;

    /**
     * @var ReadableStreamInterface
     */
    private $input;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var int
     */
    private $numberOfUrls = 0;

    /**
     * Flag to indicated whether this TargetManager has been initialized: were URLs loaded.
     *
     * @var bool
     */
    private $initialized = false;

    public function __construct(ReadableStreamInterface $input = null, LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        if ($input) {
            if (!$input->isReadable()) {
                $this->logger->info('Input is not readable, closing');

                $this->close();
                $input->close();

                return;
            }

            $this->setInput($input);
        }
    }

    public function close(): void
    {
        $this->logger->debug(\sprintf('received "%s" request', __FUNCTION__));

        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->buffer = '';

        $this->emit('close');
        $this->removeAllListeners();
    }

    public function addUrl(string $url): void
    {
        if (\filter_var($url, \FILTER_VALIDATE_URL) === false) {
            $this->logger->debug('skip invalid URL: '.$url);

            return;
        }

        ++$this->numberOfUrls;
        $this->logger->debug('emitting URL: '.$url);
        $this->emit('data', [$url]);
    }

    public function getNumberOfUrls(): int
    {
        return $this->numberOfUrls;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function pause(): void
    {
        $this->logger->debug(\sprintf('received "%s" request', __FUNCTION__));

        $this->input->pause();
    }

    public function resume(): void
    {
        $this->logger->debug(\sprintf('received "%s" request', __FUNCTION__));

        $this->input->resume();
    }

    public function pipe(WritableStreamInterface $destination, array $options = []): WritableStreamInterface
    {
        $this->logger->debug(\sprintf('received "%s" request', __FUNCTION__));

        Util::pipe($this, $destination, $options);

        return $destination;
    }

    private function setInput(ReadableStreamInterface $input): void
    {
        $this->input = $input;
        $this->input->on('data', $this->getHandleDataCallback());
        $this->input->on('end', $this->getHandleEndCallback());
        $this->input->on('error', $this->getHandleErrorCallback());
        $this->input->on('close', $this->getHandleCloseCallback());
    }

    private function getHandleDataCallback(): callable
    {
        return function ($data): void {
            $this->buffer .= $data;
        };
    }

    private function getHandleEndCallback(): callable
    {
        return function (): void {
            $this->processBuffer();
            $this->initialized = true; //Mark TargetManager as initialized: from now on it can be processed, stopped, etc.
        };
    }

    private function processBuffer(): void
    {
        if ($this->processBufferAsXml()) {
            return;
        }

        $this->processBufferAsText();
    }

    private function processBufferAsXml(): bool
    {
        $xmlElement = $this->getSimpleXMLElement($this->buffer);

        if ($xmlElement instanceof SimpleXMLElement) {
            $this->logger->notice('Instantiated SimpleXMLElement with "{elementCount}" children', ['elementCount' => $xmlElement->count()]);

            $this->processSimpleXMLElement($xmlElement);

            return true;
        }

        return false;
    }

    private function getSimpleXMLElement(string $data): ?SimpleXMLElement
    {
        try {
            return @(new SimpleXMLElement($data));
        } catch (\Exception $exception) {
            $this->logger->notice('Failed instantiating SimpleXMLElement:'.$exception->getMessage());
        }

        return null;
    }

    private function processSimpleXMLElement(SimpleXMLElement $xmlElement): void
    {
        if ($this->isXmlSitemapIndex($xmlElement)) {
            $this->processSitemapIndex($xmlElement);

            return;
        }
        if ($this->isXmlSitemap($xmlElement)) {
            $this->processSitemapElement($xmlElement);

            return;
        }
    }

    private function isXmlSitemapIndex(SimpleXMLElement $xmlElement): bool
    {
        $xmlRootElement = $xmlElement->getName();

        return $xmlRootElement === self::XML_SITEMAP_INDEX_ROOT_ELEMENT;
    }

    private function processSitemapIndex(SimpleXMLElement $sitemapIndexElement): void
    {
        if ($sitemapLocationElements = $this->getSitemapLocationElements($sitemapIndexElement)) {
            foreach ($sitemapLocationElements as $sitemapLocationElement) {
                $sitemapUrl = (string) $sitemapLocationElement;
                $this->processSitemapUrl($sitemapUrl);
                $this->logger->info('processed '.$sitemapUrl.', #URLs: '.$this->getNumberOfUrls());
            }
        }
    }

    private function getSitemapLocationElements(SimpleXMLElement $xmlElement): ?array
    {
        $xmlElement->registerXPathNamespace('sitemap', self::XML_SITEMAP_NAMESPACE);
        if ($sitemapLocationElements = $xmlElement->xpath('//sitemap:loc')) {
            return $sitemapLocationElements;
        }

        return null;
    }

    private function processSitemapUrl(string $sitemapUrl): void
    {
        if ($data = $this->loadExternalData($sitemapUrl)) {
            if ($sitemapElement = $this->getSimpleXMLElement($data)) {
                $this->processSitemapElement($sitemapElement);
            }
        }
    }

    private function loadExternalData(string $file): ?string
    {
        if ($data = @\file_get_contents($file)) {
            return $data;
        }

        return null;
    }

    private function processSitemapElement(SimpleXMLElement $sitemapElement): void
    {
        if ($sitemapLocationElements = $this->getSitemapLocationElements($sitemapElement)) {
            foreach ($sitemapLocationElements as $sitemapLocationElement) {
                $sitemapUrl = (string) $sitemapLocationElement;

                $this->addUrl($sitemapUrl);
            }
        }
    }

    private function isXmlSitemap(SimpleXMLElement $xmlElement): bool
    {
        $xmlRootElement = $xmlElement->getName();

        return $xmlRootElement === self::XML_SITEMAP_ROOT_ELEMENT //Used by standalone Sitemaps
            || $xmlRootElement === self::XML_SITEMAP_ELEMENT; //Used when part of a Sitemap Index
    }

    private function processBufferAsText(): void
    {
        $regularExpressionToDetectUrl = "/^\s*((?U).+)\s*$/mi";
        $this->logger->notice('detect URLs in TXT file using regular expression: '.$regularExpressionToDetectUrl);

        $matches = [];
        \preg_match_all($regularExpressionToDetectUrl, $this->buffer, $matches);

        $this->logger->notice(\sprintf('detected %d URLs in TXT file', \count($matches[1])));

        $this->addUrls(...$matches[1]);
    }

    private function addUrls(string ...$urls): void
    {
        foreach ($urls as $url) {
            $this->addUrl($url);
        }
    }

    private function getHandleErrorCallback(): callable
    {
        return function (\Exception $error): void {
            $this->emit('error', [$error]);
            $this->close();
        };
    }

    private function getHandleCloseCallback(): callable
    {
        return function (): void {
            $this->close();
        };
    }
}
