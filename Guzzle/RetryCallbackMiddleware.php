<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 30/10/15
 * Time: 12:23
 */

namespace Keboola\Google\ClientBundle\Guzzle;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use Psr\Http\Message\RequestInterface;

/**
 * Middleware that retries requests based on the boolean result of
 * invoking the provided "decider" function and modifying
 * the request with callback "callback"
 */
class RetryCallbackMiddleware
{
    /** @var callable  */
    private $nextHandler;

    /** @var callable */
    private $decider;

    /** @var callable */
    private $callback;

    /**
     * @param callable $decider     Function that accepts the number of retries,
     *                              a request, [response], and [exception] and
     *                              returns true if the request is to be
     *                              retried.
     * @param callable $callback    Function that accepts request and response,
     *                              return modified request. It is only called
     *                              when decider returns true.
     * @param callable $nextHandler Next handler to invoke.
     * @param callable $delay       Function that accepts the number of retries
     *                              and returns the number of milliseconds to
     *                              delay.
     */
    public function __construct(
        callable $decider,
        callable $callback,
        callable $nextHandler,
        callable $delay = null
    ) {
        $this->decider = $decider;
        $this->callback = $callback;
        $this->nextHandler = $nextHandler;
        $this->delay = $delay ?: __CLASS__ . '::exponentialDelay';
    }

    /**
     * Default exponential backoff delay function.
     *
     * @param $retries
     *
     * @return int
     */
    public static function exponentialDelay($retries)
    {
        return (int) pow(2, $retries - 1);
    }

    /**
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options)
    {
        if (!isset($options['retries'])) {
            $options['retries'] = 0;
        }

        $fn = $this->nextHandler;
        return $fn($request, $options)
            ->then(
                $this->onFulfilled($request, $options),
                $this->onRejected($request, $options)
            );
    }

    private function onFulfilled(RequestInterface $req, array $options)
    {
        return function ($value) use ($req, $options) {
            if (!call_user_func(
                $this->decider,
                $options['retries'],
                $req,
                $value,
                null
            )) {
                return $value;
            }

            // call callback
            $fn = $this->callback;
            return $this->doRetry($fn($req, $value), $options);
        };
    }

    private function onRejected(RequestInterface $req, array $options)
    {
        return function ($reason) use ($req, $options) {
            if (!call_user_func(
                $this->decider,
                $options['retries'],
                $req,
                null,
                $reason
            )) {
                return new RejectedPromise($reason);
            }
            return $this->doRetry($req, $options);
        };
    }

    private function doRetry(RequestInterface $request, array $options)
    {
        $options['delay'] = call_user_func($this->delay, ++$options['retries']);

        return $this($request, $options);
    }

}