<?php

namespace SimPod\ClickHouseClient\GuzzleHttp\Handler;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\Promise;
use Psr\Http\Message\RequestInterface;

/**
 * Returns an asynchronous response using curl_multi_* functions.
 *
 * When using the CurlMultiHandler, custom curl options can be specified as an
 * associative array of curl option constants mapping to values in the
 * **curl** key of the provided request options.
 *
 * @property resource $_mh Internal use only. Lazy loaded multi-handle.
 */
class CurlMultiHandler
{
    /** @var CurlFactoryInterface */
    private $factory;

    private $selectTimeout;

    private $active;

    private $handles = [];

    private $delays = [];

    /**
     * This handler accepts the following options:
     *
     * - handle_factory: An optional factory  used to create curl handles
     * - select_timeout: Optional timeout (in seconds) to block before timing
     *   out while selecting curl handles. Defaults to 1 second.
     *
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->factory       = isset($options['handle_factory'])
            ? $options['handle_factory'] : new CurlFactory(50);
        $this->selectTimeout = isset($options['select_timeout'])
            ? $options['select_timeout'] : 1;
    }

    public function __get($name)
    {
        if ($name === '_mh') {
            return $this->_mh = curl_multi_init();
        }

        throw new \BadMethodCallException();
    }

    public function __destruct()
    {
        if (isset($this->_mh)) {
            curl_multi_close($this->_mh);
            unset($this->_mh);
        }
    }

    public function __invoke(RequestInterface $request, array $options)
    {
        $easy = $this->factory->create($request, $options);
        $id   = (int) $easy->handle;

        $promise = new Promise(
            [$this, 'execute'],
            function () use ($id) {
                return $this->cancel($id);
            }
        );

        $this->addRequest(['easy' => $easy, 'deferred' => $promise]);

        return $promise;
    }

    /**
     * Cancels a handle from sending and removes references to it.
     *
     * @param int $id Handle ID to cancel and remove.
     *
     * @return bool True on success, false on failure.
     */
    private function cancel($id)
    {
        // Cannot cancel if it has been processed.
        if (! isset($this->handles[$id])) {
            return false;
        }

        $handle = $this->handles[$id]['easy']->handle;
        unset($this->delays[$id], $this->handles[$id]);
        curl_multi_remove_handle($this->_mh, $handle);
        curl_close($handle);

        return true;
    }

    private function addRequest(array $entry)
    {
        $easy               = $entry['easy'];
        $id                 = (int) $easy->handle;
        $this->handles[$id] = $entry;
        if (empty($easy->options['delay'])) {
            curl_multi_add_handle($this->_mh, $easy->handle);
        } else {
            $this->delays[$id] = microtime(true) + ($easy->options['delay'] / 1000);
        }
    }

    /**
     * Runs until all outstanding connections have completed.
     */
    public function execute()
    {
        $queue = P\queue();

        while ($this->handles || ! $queue->isEmpty()) {
            // If there are no transfers, then sleep for the next delay
            if (! $this->active && $this->delays) {
                usleep($this->timeToNext());
            }
            $this->tick();
        }
    }

    private function timeToNext()
    {
        $currentTime = microtime(true);
        $nextTime    = PHP_INT_MAX;
        foreach ($this->delays as $time) {
            if ($time < $nextTime) {
                $nextTime = $time;
            }
        }

        return max(0, $nextTime - $currentTime) * 1000000;
    }

    /**
     * Ticks the curl event loop.
     */
    public function tick()
    {
        // Add any delayed handles if needed.
        if ($this->delays) {
            $currentTime = microtime(true);
            foreach ($this->delays as $id => $delay) {
                if ($currentTime >= $delay) {
                    unset($this->delays[$id]);
                    curl_multi_add_handle(
                        $this->_mh,
                        $this->handles[$id]['easy']->handle
                    );
                }
            }
        }

        // Step through the task queue which may add additional requests.
        P\queue()->run();

        if ($this->active &&
            curl_multi_select($this->_mh, $this->selectTimeout) === -1
        ) {
            // Perform a usleep if a select returns -1.
            // See: https://bugs.php.net/bug.php?id=61141
            usleep(250);
        }

        while (curl_multi_exec($this->_mh, $this->active) === CURLM_CALL_MULTI_PERFORM) ;

        $this->processMessages();
    }

    private function processMessages()
    {
        while ($done = curl_multi_info_read($this->_mh)) {
            $id = (int) $done['handle'];
            curl_multi_remove_handle($this->_mh, $done['handle']);

            if (! isset($this->handles[$id])) {
                // Probably was cancelled.
                continue;
            }

            $contents = curl_multi_getcontent($done['handle']);

            $entry = $this->handles[$id];
            $easy = $entry['easy'];

            $curlOptHeaderEnabled = $easy->options['curl'][CURLOPT_HEADER] ?? false;
            if($curlOptHeaderEnabled){
                $headerSize = curl_getinfo($easy->handle)[CURLINFO_HEADER_SIZE];
                $contents = substr($contents, $headerSize);
            }

            $easy->sink->write($contents);
            $easy->errno = curl_errno($done['handle']);

            unset($this->handles[$id], $this->delays[$id]);
            $entry['easy']->errno = $done['result'];
            $entry['deferred']->resolve(
                CurlFactory::finish(
                    $this,
                    $easy,
                    $this->factory
                )
            );
        }
    }
}
